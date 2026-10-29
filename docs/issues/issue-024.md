# Issue-024 — Security: the response-hardening headers

> **Status:** **implemented and verified** — found by the audit of 2026-10-28,
> measured against the running application; implemented on 2026-10-29.
> See [§14](#14-the-implementation-as-built) for what was built and what was
> measured.
> **Target:** `index.php`, `install.php`, `.htaccess`, `includes/functions.php`
> **Severity:** **Low to Medium** — the application is already CSP-clean by
> construction, so the missing headers cost the deployment very little today
> and a great deal if an injection defect is ever introduced.
> **Requested by the user:** "Now the implementation is complete. before release
> v.1.0, let's focus on security for a bit. 1. help check sql injection, or any
> security risk. and write issue files for dev to fix those risks."
> **Depends on:** [issue-013](issue-013.md) (the shared shell — the reason the
> application is CSP-clean),
> [issue-014](issue-014.md) (the front controller and the security headers it
> already sends),
> [issue-015](issue-015.md) (the install wizard — a **second** entry point with
> its own document)
> **Related:** [issue-022](issue-022.md) (the unauthenticated endpoints),
> [issue-023](issue-023.md) (the web-root deny block — the same file, `.htaccess`)

---

## Table of contents

1. [Objective](#1-objective)
2. [What the application sends today](#2-what-the-application-sends-today)
3. [Finding 1 — there is no Content-Security-Policy](#3-finding-1--there-is-no-content-security-policy)
4. [Finding 2 — there is no Strict-Transport-Security](#4-finding-2--there-is-no-strict-transport-security)
5. [Finding 3 — there are no Permissions-Policy, COOP or CORP](#5-finding-3--there-are-no-permissions-policy-coop-or-corp)
6. [Finding 4 — the installer sends no headers at all](#6-finding-4--the-installer-sends-no-headers-at-all)
7. [The fix](#7-the-fix)
8. [Acceptance criteria](#8-acceptance-criteria)
9. [Verification](#9-verification)
10. [Rollback](#10-rollback)
11. [Risks](#11-risks)
12. [Out of scope](#12-out-of-scope)
13. [Headers that must NOT be added](#13-headers-that-must-not-be-added)
14. [The implementation, as built](#14-the-implementation-as-built)

---

## 1. Objective

`index.php` sends three security headers today:

```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
```

Those are the right three, and they are the easy ones. The four headers that
are **missing** are the ones that turn a *known* injection defect into an
*inert* one:

| Header | What it buys |
|---|---|
| `Content-Security-Policy` | A second line of defence against XSS — and, more importantly, a mechanism that makes an XSS defect **visible** instead of silent. |
| `Strict-Transport-Security` | The downgrade attack: an active network attacker who can intercept the first plain-HTTP request can strip the cookie's `Secure` flag's protection for the whole session. |
| `Permissions-Policy` | A browser feature this application has no use for is denied by default, rather than being available to any script that is ever injected. |
| `Cross-Origin-Opener-Policy` / `Cross-Origin-Resource-Policy` | Keeps a window handle and the asset cache from crossing an origin boundary. |

The good news — and it is unusual good news — is that **the application is
already CSP-clean**. It was built that way deliberately (issue-013 §11), so the
policy can be strict: **no `unsafe-inline`, no nonces, no `unsafe-eval`.**

---

## 2. What the application sends today

> **This is the state BEFORE the fix.** The measured result after the fix is
> in [§14.7](#147-the-measured-result).

Measured against the running application:

| Response | The headers it carries |
|---|---|
| `/login`, `/dashboard`, `/transactions`, `/reports` | `nosniff`, `X-Frame-Options`, `Referrer-Policy` + PHP's session cache headers |
| `/nope` (the 404 page) | the same three |
| **the 500 boundary** | the same three (they are sent before the boundary runs) |
| **`/install.php`** | **none of them** |
| **`/assets/css/tokens.css`** | **none of them** — served by LiteSpeed, not by PHP |

The cache headers (`cache-control: no-store, no-cache, must-revalidate` and
`pragma: no-cache`) come from PHP's **session** machinery, which every request
starts. They are correct — no page of this application is cacheable — and they
need no work.

---

## 3. Finding 1 — there is no Content-Security-Policy

**Severity: Medium.** (A missing defence in depth, not an exploitable
defect — the XSS audit was clean.)

The application's output escaping was audited and is clean: every dynamic value
in every view goes through `e()`, there is no `echo`, and `assets/js/app.js`
contains no `innerHTML`, no `outerHTML`, no `insertAdjacentHTML`, no `eval` and
no `fetch`. **But** that is a property of the code *today*. A CSP is the
property of the *deployment*, and it survives the next developer.

The audit also established that the application can take a **strict** policy
with no accommodation at all:

| CSP obstacle | The application's state |
|---|---|
| Inline `<script>` blocks | **None.** The only script tag is `<script src=".../js/app.js">` in `includes/footer.php`. |
| Inline `<style>` blocks | **None.** The four stylesheets are `<link rel="stylesheet">` tags in `includes/header.php`. |
| `style="..."` attributes | **None.** |
| `onclick=` / other inline event handlers | **None.** |
| Third-party origins (a CDN, a font service, an analytics tag) | **None.** Every `src` and `href` is same-origin. |
| `eval` / `new Function` in `app.js` | **None.** |
| The theme, which normally forces an inline script | **Solved.** The theme is a **cookie** the server reads, and the `data-theme` attribute is rendered **server-side** onto `<html>`. There is no inline script to allow. |

So the policy can be:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'
```

**Without a single `unsafe-inline` and without a nonce.** A nonce would mean
the header changes on every request and would put the value into the HTML — a
mechanism the application does not need and should not acquire.

> **`style-src 'self'` has a consequence the next developer must know.** It
> blocks inline `style` **attributes** as well as `<style>` blocks. A
> developer who adds `style="color: red"` will find it silently ignored, with
> no error in the console unless the developer opens it. That is the intended
> behaviour, and it belongs in the developer notes — not in a comment nobody
> reads.

---

## 4. Finding 2 — there is no Strict-Transport-Security

**Severity: Low** on a plain-HTTP LAN install; **Medium** on an HTTPS
installation.

`requestIsHttps()` exists and is used correctly — for the `Secure` flag on
the session cookie and for the scheme of a link in a mail. What is missing is
the instruction to the browser to **refuse to speak plain HTTP to this host
again**.

Without it, the very first request to an HTTPS installation can be made over
plain HTTP by an active network attacker. That request is not protected: the
`Secure` flag on the cookie is only honoured for the cookie the browser
already holds, and a stripped response is a downgrade.

> **The header must be conditional.** A plain-HTTP installation is an
> explicitly supported deployment — "a box on the LAN" — and an HSTS header
> on a host that does not speak HTTPS would be a **self-inflicted outage**:
> the browser would refuse every subsequent request.
>
> The spec is also explicit: *"Hosts MUST NOT include the STS header field in
> responses received over HTTP."* The correct behaviour is to send it **only**
> when `requestIsHttps()` is true — which is also the behaviour the test can
> assert.

`includeSubDomains` and `preload` must **not** be added by default: they
commit every subdomain, and `preload` commits the domain to the browser
vendors' list. Both belong to the operator who owns the domain, and the
operator should be told they exist.

---

## 5. Finding 3 — there are no Permissions-Policy, COOP or CORP

**Severity: Low.**

The application uses **no** browser capability that needs a permission: no
camera, no microphone, no geolocation, no payment request, no USB, no
Bluetooth, no screen capture, no clipboard read. A deny-by-default policy is
therefore free:

```
Permissions-Policy: accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(), screen-wake-lock=(), sync-xhr=(), usb=(), xr-spatial-tracking=()
```

`Cross-Origin-Opener-Policy: same-origin` severs a cross-origin `window.opener`
relationship, and `Cross-Origin-Resource-Policy: same-origin` stops another
origin from embedding or reading this application's assets. The application
has no popup flow and no cross-origin asset, so neither costs anything.

> **Only well-known feature names.** The `Permissions-Policy` specification
> says an **unrecognised** feature name makes the header a parse error — and a
> header that fails to parse is ignored **in its entirety**. A single typo
> would silently disable every feature in the list. The names above are all
> standard; a new one must be checked before it is added.

---

## 6. Finding 4 — the installer sends no headers at all

**Severity: Low.**

`install.php` is a **second entry point**. It renders its own HTML document,
it does not go through `index.php`, and it sets **no** headers at all:

```
$ curl -s -D - -o /dev/null http://127.0.0.1:8088/install.php
HTTP/1.1 403 Forbidden
set-cookie: PHPSESSID=...; path=/; HttpOnly; SameSite=Lax
expires: Thu, 19 Nov 1981 08:52:00 GMT
cache-control: no-store, no-cache, must-revalidate
pragma: no-cache
content-type: text/html; charset=UTF-8
```

Not `X-Content-Type-Options`, not `X-Frame-Options`, not `Referrer-Policy` —
none of the three that `index.php` sends. The installer is the **first** page
a visitor sees on a new installation and the **last** page they see before the
installation is finished; it is also the page that receives the **database
password** and the **SMTP password**.

The fix is to give the installer the same header block. The cleanest way is
a **shared helper** — the same set of headers, emitted from one function, so
the two entry points can never drift apart.

> **The static assets are served by LiteSpeed, not by PHP**, so they carry no
> security headers either. A `X-Content-Type-Options: nosniff` on them is
> desirable but not important — and it can only be set from the server
> configuration, not from PHP. See §7.3.

---

## 7. The fix

### 7.1 One header helper, used by both entry points

Put the whole set in **one** function, in `includes/functions.php`:

```php
/**
 * The security headers every document response carries.
 *
 * Both entry points call this: index.php for the application, install.php
 * for the wizard. Two copies of this list would drift apart.
 */
function sendSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');

    // The policy is strict on purpose. The application has no inline
    // script, no inline style, no inline event handler and no third-party
    // origin, so it needs neither 'unsafe-inline' nor a nonce.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; object-src 'none'; "
        . "base-uri 'none'; form-action 'self'; frame-ancestors 'self'"
    );

    header(
        "Permissions-Policy: accelerometer=(), autoplay=(), camera=(), "
        . "display-capture=(), encrypted-media=(), fullscreen=(), "
        . "geolocation=(), gyroscope=(), magnetometer=(), microphone=(), "
        . "midi=(), payment=(), picture-in-picture=(), "
        . "publickey-credentials-get=(), screen-wake-lock=(), sync-xhr=(), "
        . "usb=(), xr-spatial-tracking=()"
    );

    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    // HSTS and the upgrade directive are HTTPS-only. A plain-HTTP
    // installation is a supported deployment, and a browser that has been
    // told to expect HTTPS will refuse to talk to a host that does not.
    if (requestIsHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
        header('Upgrade-Insecure-Requests: 1');
    }
}
```

Then:

- `index.php` calls `sendSecurityHeaders()` **in place of** its three
  `header()` calls.
- `install.php` calls `sendSecurityHeaders()` **before it emits anything**.

> **The call must come before the first byte of output.** The session opens
> `ob_start()` precisely because `output_buffering` is `0` in this stack —
> `header()` after output is a **silent** failure, and a silently dropped
> security header looks exactly like a security header that was never added.

### 7.2 The `Upgrade-Insecure-Requests` directive

It belongs inside the HTTPS conditional, for the same reason as HSTS: it
rewrites `http://` subresource URLs to `https://`. On a plain-HTTP
installation it would break every stylesheet and the script.

### 7.3 The static assets (optional)

LiteSpeed serves `assets/` directly, so PHP cannot put a header on them. If
the image's `mod_headers` is available, `.htaccess` can set one for the
static tree:

```apache
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|svg|woff2?)$">
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>
```

> **Verify before relying on it.** A `Header` directive inside an
> `<IfModule>` that does not exist is ignored **silently** — the same failure
> mode as a missing security header. The implementer must confirm the header
> appears on a live response and must not report it as done on the strength
> of the file's contents.>
> **Measured: it is not in the image.** Three mechanisms were tried against
> `litespeedtech/openlitespeed:1.9.4-lsphp85` and none of them works — see
> [§14.8](#148-the-73-finding--mod_headers-is-not-in-the-image). The block
> was therefore **removed** rather than left as dead configuration, and the
> header on the static tree is a **vhost** concern.
> **`assets/` is an addition to `.htaccess`, not a replacement.** The deny
> block stays exactly where it is.

### 7.4 What to tell the operator

`AGENTS.md` and the operator's notes must state:

- **HSTS is only sent over HTTPS**, and an installation that is later moved
  behind TLS will start sending it — at which point the host is committed to
  HTTPS for a year. The `max-age` is the operator's to change.
- **`includeSubDomains` and `preload` are deliberately absent**, and why.
- **The CSP is strict**, so an inline style or an inline handler will not
  work — by design.

---

## 8. Acceptance criteria

- [x] Every **document** response carries
      `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options`,
      `Referrer-Policy`, `Permissions-Policy`,
      `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` and
      `X-Permitted-Cross-Domain-Policies`.
      *Measured on the live stack (§14.7) and asserted on the 404 page, the
      500 boundary and the installer.*
- [x] The CSP contains **no** `unsafe-inline`, **no** `unsafe-eval` and **no**
      nonce — and a test asserts their absence.
      *`database/security_headers_test.php` §B; the LIVE tier §15 of
      `database/live/live_app_test.php`.*
- [x] The CSP contains `object-src 'none'`, `base-uri 'none'` and
      `form-action 'self'`.
- [x] `Strict-Transport-Security` is sent **only** over HTTPS. A test
      asserts its absence on a plain-HTTP response.
      *§E proves the HTTPS branch with a probe that sets `$_SERVER['HTTPS']`,
      the same thing LiteSpeed does for a TLS request.*
- [x] `Upgrade-Insecure-Requests` is sent **only** over HTTPS.
- [x] The **installer** carries the same header set as the application. A
      test asserts it on `/install.php` — in a sandbox where `config.php`
      does **not** exist, so the wizard actually renders.
- [x] The header set is defined in **exactly one** function, and both entry
      points call it. A source-level assertion checks that there is one
      definition and two call sites.
- [x] `X-XSS-Protection` is **not** sent. See §13.
- [x] The application still **works** under the policy: the stylesheets load,
      the dark-mode toggle works, the theme cookie is honoured, the forms
      submit, and the confirm dialogs open. A LIVE assertion loads
      `/dashboard` and checks that the CSS applied (a computed style, not a
      byte count).
      *§16 of `database/live/live_app_test.php` — the computed background of
      the body, resolved from the stylesheets the server really serves.*
- [x] The `Permissions-Policy` feature names are all well-known — a
      source-level assertion against a list of accepted names.
      *§C: 34 names, each asserted against the accepted list.*

---

## 9. Verification

````bash
# 1. The headers, on the application.
curl -s -D - -o /dev/null http://127.0.0.1:8088/login

# 2. The headers, on the 404 page.
curl -s -D - -o /dev/null http://127.0.0.1:8088/nope

# 3. The headers, on the installer (needs a sandbox without config.php).
curl -s -D - -o /dev/null http://127.0.0.1:8088/install.php

# 4. No 'unsafe-inline', no nonce.
curl -s -D - -o /dev/null http://127.0.0.1:8088/login \
  | grep -i "content-security-policy" \
  | grep -E "unsafe-inline|unsafe-eval|nonce" && echo "FAIL: a relaxation is present"

# 5. No HSTS over plain HTTP.
curl -s -D - -o /dev/null http://127.0.0.1:8088/login \
  | grep -i "strict-transport-security" && echo "FAIL: HSTS over plain HTTP"

# 6. The page still works — the CSS must actually apply.
curl -s http://127.0.0.1:8088/login | grep -c "assets/css/tokens.css"

# 7. The regression.
docker compose exec -T app php /var/www/html/database/front_controller_test.php
docker compose exec -T app php /var/www/html/database/install_test.php
docker compose exec -T app php /var/www/html/database/auth_test.php

# 8. The whole suite and the LIVE tier.
.github/skills/dev-stack-ops/scripts/run-tests.sh
bash database/live/run-live-tests.sh
````

> **The CSP is only proven by the application still working.** A policy that
> blocks the stylesheets produces a page that still returns **200** and still
> contains the `<link>` tag — and looks unstyled. The LIVE assertion must
> check a **computed style**, not the presence of a tag.

---

## 10. Rollback

- The helper is one function and two call sites. Removing the call from
  `index.php` restores the previous three headers exactly.
- The policy is strict but, on this application, **compatible**. If a
  browser misbehaves, the first step is to identify the directive at fault
  (the browser console names it) and to relax that **one** directive — never
  to fall straight to `unsafe-inline`.
- `install.php` is independent: removing its call restores its current
  behaviour.

---

## 11. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| The CSP blocks something the application needs | **Low** | Medium | The audit established there is no inline script, no inline style, no inline handler and no third-party origin. The LIVE assertion checks a computed style, so a break is caught, not assumed away. |
| `Permissions-Policy` contains an unknown feature name, and the whole header is ignored | Medium | Low | The names are checked against the specification before use, and the assertion is against a list of accepted names. |
| HSTS is sent over plain HTTP and locks the operator out | Low | **High** | The header is inside the `requestIsHttps()` conditional, and a test asserts its **absence** over plain HTTP. |
| `Upgrade-Insecure-Requests` breaks a plain-HTTP installation | Low | High | Same conditional, same test. |
| `header()` is called after output and the headers are silently dropped | Medium | Medium | `startSession()` opens `ob_start()`; the helper is called before the first byte, and the tests assert the headers on the **live** response. |
| `Strict-Transport-Security` commits the host to HTTPS for a year when the operator later moves it behind a proxy | Medium | Medium | `max-age` is stated in the operator's notes, and `includeSubDomains` / `preload` are deliberately absent. |

---

## 12. Out of scope

- **`Cross-Origin-Embedder-Policy`** — it is the strictest of the family and
  it can break embedded content for no benefit here.
- **A CSP reporting endpoint** (`report-uri` / `report-to`) — it adds a write
  path and an unauthenticated endpoint, which is exactly what
  [issue-022](issue-022.md) is about reducing. It can be added later, behind
  the same throttle.
- **`Server: LiteSpeed`** — the server token is disclosed. Suppressing it is
  a **vhost configuration** change (`server_tokens off`), not an application
  change, and it belongs to the deployment.
- **The `assets/` headers** — optional, and only if `mod_headers` is
  confirmed to be present in the image.
- **A nonce-based CSP** — the application does not need one, and a nonce
  would make the header dynamic for no gain.

---

## 13. Headers that must NOT be added

The instinct to add headers is not always right. Two are actively harmful,
and the audit recommends **against** them.

### `X-XSS-Protection`

**Do not send it.** The legacy XSS auditor that it enables has been shown to
**introduce** vulnerabilities in some configurations — it rewrites the
response, and it can turn a safe response into an unsafe one. The header has
been removed from the major browsers' recommendations, and the modern answer
is the CSP plus correct output escaping, both of which this application
already has.

### `X-Frame-Options` alone, without `frame-ancestors`

The modern replacement for `X-Frame-Options` is `frame-ancestors` in the CSP.
**Send both**: `X-Frame-Options` for the browsers that only understand it,
`frame-ancestors` for the ones that do not. Removing the old header would
lose the protection in the older browsers for no gain.

### `Cache-Control` on the documents

PHP's session already sends `cache-control: no-store, no-cache, must-revalidate`
on every request, which is exactly right. Adding a second, hand-written
`Cache-Control` header would **duplicate** it — and a duplicated header with
conflicting values is resolved by the browser unpredictably. Leave it alone.

---

## 14. The implementation, as built

Implemented on 2026-10-29, in one change: the helper, the two call sites, the
new regression test, the cascade resolver and the documentation land
together — because the criterion that forced the resolver cannot be checked
without it.

### 14.1 What changed

| File | Change |
|---|---|
| `includes/functions.php` | The new `sendSecurityHeaders()` — the **only** definition of the header set. |
| `index.php` | The three inline `header()` calls replaced by one `sendSecurityHeaders();`, after `startSession()`. |
| `install.php` | The new call, after `startSession()` and before the conditional `vendor/autoload.php`. |
| `database/security_headers_test.php` | **New** — the guard, in eight sections (§14.4). |
| `database/css_cascade.php` | **New** — the cascade resolver (§14.5). |
| `database/live/live_app_test.php` | §15 rewritten and **§16 added** — the live header set and the computed style. |
| `database/front_controller_test.php` | The three stale literal assertions replaced by "the controller calls the shared helper" and "the helper is defined exactly once". |
| `database/webroot_hardening_test.php` | `/database/css_cascade.php` pinned in the denied list. |
| `AGENTS.md`, `docs/About.md`, `docs/issues/README.md` | The status, the operator notes and the index. |

### 14.2 The header helper, as written

The helper is in `includes/functions.php`, under a new **"Response headers"**
section, and it is exactly the set §7.1 specifies — **plus**
`X-Permitted-Cross-Domain-Policies: none`, which the acceptance criteria
require and the specification's example omitted.

Two properties were added to the file's own contract docblock:

> 8. The security headers live in ONE function — `sendSecurityHeaders()` —
>    and BOTH entry points call it. No `'unsafe-inline'`, no nonce.

### 14.3 The two call sites

```php
// index.php
startSession();
sendSecurityHeaders();
```

```php
// install.php
startSession();
sendSecurityHeaders();
```

Both are **after** `startSession()` — which opens the `ob_start()` that makes
the `header()` calls legal before the first byte of output — and **before**
anything is echoed. `install.php` calls it before the conditional
`vendor/autoload.php`, so the wizard carries the headers even on a machine
with no `vendor/` tree.

### 14.4 The regression test, as written

`database/security_headers_test.php` is **new**. It has eight sections, and
the point of the eight is that a header is a property of the **response**,
not of the source:

| Section | What it proves |
|---|---|
| §A | One definition, two call sites, no inline `header('X-…')` left in the entry points, and the **order** of the calls. |
| §B | The CSP carries its ten directives and **no** `unsafe-inline`, `unsafe-eval`, `unsafe-hashes`, `strict-dynamic` or nonce. |
| §C | Every `Permissions-Policy` feature is `()` and every name is on the list of 34 accepted names. |
| §D | The headers that must **not** be there — `X-XSS-Protection` among them. |
| §E | The **runtime**: the real controller and the real installer, under the PHP built-in server, with the headers read off the responses. The HTTPS branch is proved with a probe that sets `$_SERVER['HTTPS']`. |
| §F | The application is **CSP-clean**: no inline script, no inline style, no inline event handler, no third-party origin, no dangerous JavaScript API — and every subresource and every form action the policy must permit. |
| §G | The **live** stack, over HTTP. Skipped cleanly when the stack is not answering. |
| §H | The cascade resolver — proved (§14.5). |

§E is the tier that matters most, and it exists for the reason the whole
issue exists: **a `header()` call after the first byte of output is a silent
failure**, and a silently dropped security header is indistinguishable from a
security header that was never added. A source-level test cannot see that
failure; a response-level test can.

### 14.5 The cascade resolver — why it is in the repository

The acceptance criteria say:

> A LIVE assertion loads `/dashboard` and checks that the CSS applied (**a
> computed style, not a byte count**).

The stack has **no browser** and **no JavaScript engine**, and `ext-dom` in
this image has **no `DOMElement::matches()`** — every `->matches()` call
throws `Error: Call to undefined method DOMElement::matches()`. So the
cascade had to be resolved by hand.

`database/css_cascade.php` is that resolver: a stylesheet parser, a selector
matcher and a cascade. It is **not** a browser, and it says so in its
docblock. The rules it follows:

- **It throws on anything it does not understand.** A silently skipped rule
  would make an unstyled page look styled. An unsupported selector, an
  unsupported at-rule and an unsupported media feature are all **refusals**,
  and §H asserts each one.
- **It supports the subset the application uses.** The vocabulary of
  `assets/css/` was audited first: five `@media` blocks, one `@keyframes`,
  and the pseudo-classes `:hover`, `:focus`, `:focus-visible`, `:root`,
  `:last-child`, `:checked`, `:not()`, `:first-child`, `:last-child`,
  `:only-child`, `:nth-child()`, `:first-of-type`, `:last-of-type`,
  `:disabled`, `:enabled` and `:empty`. There is **no** `:has()`, no `:is()`,
  no `:where()` and **no `!important`** anywhere in the tree.
- **It resolves `var()`** against the effective custom-property map, and an
  undefined custom property with no fallback makes the declaration invalid at
  computed-value time — which is what a browser does.
- **It inherits** the inherited properties from the ancestor chain, so the
  colour of a `<p>` is the colour of the body.

§H proves it against known inputs — the specificity order, the source order,
`!important`, the media query, the fallback, the combinators, the pseudo-
element that does not style the element itself, the live state that does not
match the resting state, and the three refusals — and then resolves the
**shipped** stylesheets to the colours the design says they are:

| Theme | The body's background | The body's text |
|---|---|---|
| light | `#eef0f4` | `#1c1f24` |
| dark | `#23272e` | `#f2f4f7` |

### 14.6 The LIVE assertion

§16 of `database/live/live_app_test.php` is the criterion, carried out
against the running application:

1. A fresh session is established (the one from §12 is dead — the test logs
   out before it).
2. `/dashboard` is fetched with the `theme` cookie set to `dark` and again
   with it set to `light`.
3. The server must render `data-theme="dark"` / `data-theme="light"` onto the
   `<html>` element — the theme cookie is honoured **server-side**.
4. Every `<link rel="stylesheet">` is fetched **over HTTP** and must answer
   **200** with a **`text/css`** content type (a stylesheet served as
   `text/html` is blocked by the browser whatever the policy says).
5. The served HTML is parsed and the body's `background-color` and `color` are
   resolved through the cascade.
6. The two themes must paint **different** backgrounds.

The environment is deliberately set to the **opposite** of the theme: the
explicit theme the server rendered must beat the system preference in **both**
directions, which is the whole point of the token layer.

The rest of the criterion is carried out too: the theme toggle is on the
page, every `data-modal-open` trigger has a matching `id`, every
`data-modal-close` points at a dialog that exists, **the `.modal` rule
declares no `display`** (so the `hidden` attribute the script sets is the only
switch), and a form still POSTs and the row it creates is there.

> **What "the dialog opens" means in a headless tier.** There is no browser,
> so the test cannot click. What it asserts is the whole chain that the
> click would exercise: the trigger, the dialog and the closer all agree on
> the same id, and no stylesheet rule overrides the `hidden` attribute. A
> break anywhere in that chain is caught; a break *inside* the browser is not,
> and that limit is stated here rather than hidden.

### 14.7 The measured result

Against the running application:

```
HTTP/1.1 200 OK
x-content-type-options: nosniff
x-frame-options: SAMEORIGIN
referrer-policy: same-origin
content-security-policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'
permissions-policy: accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(), screen-wake-lock=(), sync-xhr=(), usb=(), xr-spatial-tracking=()
cross-origin-opener-policy: same-origin
cross-origin-resource-policy: same-origin
x-permitted-cross-domain-policies: none
content-type: text/html; charset=UTF-8
```

and the computed styles, from the stylesheets the server really serves:

| Theme cookie | The server renders | The computed body background |
|---|---|---|
| `theme=dark` | `<html lang="en" data-theme="dark">` | **`#23272e`** |
| `theme=light` | `<html lang="en" data-theme="light">` | **`#eef0f4`** |

### 14.8 The §7.3 finding — `mod_headers` is not in the image

§7.3 offered the static assets' headers as optional, on the condition that
the image's `mod_headers` is available. **It is not.** Three mechanisms were
measured against `litespeedtech/openlitespeed:1.9.4-lsphp85`:

| Mechanism | Result |
|---|---|
| `<IfModule mod_headers.c>` + `FilesMatch` | **No effect** — the module is not in `/usr/local/lsws/modules` (the directory holds `mod_acme.so`, `mod_security.so`, `modcompress.def`, `moddecompress.def` and `modpagespeed.so`). |
| A bare `Header set …` | **No effect** — without the module the directive is ignored, and it is ignored **silently**. |
| `RewriteRule … [E=HTTP_X_CONTENT_TYPE_OPTIONS:nosniff]` | **No effect** — LiteSpeed does not turn an `E=` variable into a response header. |

So the block was **removed** rather than left in `.htaccess` as dead
configuration. The static assets are served by LiteSpeed, and the header on
them is a **vhost / reverse-proxy** concern:

```apache
# The vhost, in the LiteSpeed configuration -- NOT in .htaccess.
staticFile [*.css$ *.js$]
    Header set X-Content-Type-Options "nosniff"
```

The assets are `text/css` and `text/javascript` today and are served
same-origin only, so the risk is low — but the operator should be told that
the header belongs to the server configuration, not to the application.

### 14.9 The one thing the audit did not say

The audit said the headers were thin and that the criterion would be a
computed style. It did not say that **the stack cannot compute a style at
all** — no browser, no JavaScript engine, and `ext-dom` without
`DOMElement::matches()`. The criterion is therefore not merely harder to
check than it looks: on this stack it is **not checkable with the tools that
are there**, and the honest answer was to build the resolver rather than to
weaken the criterion to something the tools could do.

Two smaller findings are worth recording, because both would have shipped as
silent gaps:

- **`mod_headers` is not in the image**, so §7.3's optional block does
  nothing (§14.8).
- **The order of the two call sites matters.** `sendSecurityHeaders()` must
  come **after** `startSession()` — the session's `ob_start()` is what makes
  the `header()` calls legal — and **before** the first byte of output. The
  test asserts the order of the calls in the source, because the wrong order
  is a silent failure in production and a green test.

