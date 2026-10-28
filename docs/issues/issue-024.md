# Issue-024 — Security: the response-hardening headers

> **Status:** **open** — found by the audit of 2026-10-28, measured against the
> running application.
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
> of the file's contents.

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

- [ ] Every **document** response carries
      `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options`,
      `Referrer-Policy`, `Permissions-Policy`,
      `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy` and
      `X-Permitted-Cross-Domain-Policies`.
- [ ] The CSP contains **no** `unsafe-inline`, **no** `unsafe-eval` and **no**
      nonce — and a test asserts their absence.
- [ ] The CSP contains `object-src 'none'`, `base-uri 'none'` and
      `form-action 'self'`.
- [ ] `Strict-Transport-Security` is sent **only** over HTTPS. A test
      asserts its absence on a plain-HTTP response.
- [ ] `Upgrade-Insecure-Requests` is sent **only** over HTTPS.
- [ ] The **installer** carries the same header set as the application. A
      test asserts it on `/install.php` — in a sandbox where `config.php`
      does **not** exist, so the wizard actually renders.
- [ ] The header set is defined in **exactly one** function, and both entry
      points call it. A source-level assertion checks that there is one
      definition and two call sites.
- [ ] `X-XSS-Protection` is **not** sent. See §13.
- [ ] The application still **works** under the policy: the stylesheets load,
      the dark-mode toggle works, the theme cookie is honoured, the forms
      submit, and the confirm dialogs open. A LIVE assertion loads
      `/dashboard` and checks that the CSS applied (a computed style, not a
      byte count).
- [ ] The `Permissions-Policy` feature names are all well-known — a
      source-level assertion against a list of accepted names.

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
