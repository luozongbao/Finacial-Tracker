# Issue-005 — UX/UI: authentication screens (sign in, register, registration closed, forgot password)

> **Status:** ready for UX/UI pickup.
> **Target:** v0.1 (design foundation).
> **Severity:** **n/a** — design deliverable, no application impact.
>
> **Requested by the user:**
> > "alsow write issue files for UX/UI design to write mockup html in /docs/mockup/"
>
> **Depends on:** [issue-004.md](issue-004.md) (tokens, shell, components).
> **Related:** `AGENTS.md`, "Install and config contract" — the
> `ALLOW_REGISTRATION` gate.

---

## Table of contents

1. Objective
2. Deliverables
3. Constraints
4. Shared behaviour of the auth screens
5. `01-login.html` — sign in
6. `02-register.html` — create account
7. `03-register-closed.html` — registration disabled
8. `04-forgot-password.html` — request a reset link
9. Acceptance criteria
10. Verification
11. Handoff notes for the application implementer
12. Out of scope

---

## 1. Objective

> Deliver the four authentication screens as static mockups that specify
> **every state the application can produce** — including the two that are
> easy to forget: a **closed registration** page and a **failed** sign-in.

Success criteria:

| # | Criterion |
|---|---|
| 1 | The four files exist and render standalone from `file://` |
| 2 | Every form control has a label, a hint where it matters, and a documented error message |
| 3 | The sign-in screen shows the **locked-out / too many attempts** state |
| 4 | The register screen shows the **server-side rejection** state, not only client-side validation |
| 5 | The registration-closed screen is a **first-class design**, not an error dump |
| 6 | The forgot-password screen does **not** reveal whether an address exists |
| 7 | All four screens share one layout, one component vocabulary, one look |

---

## 2. Deliverables

| File | Screen | Purpose |
|---|---|---|
| `01-login.html` | Sign in | The default landing page for an unauthenticated visitor. |
| `02-register.html` | Create account | Open only while `ALLOW_REGISTRATION` is `true`. |
| `03-register-closed.html` | Registration disabled | Shown when `ALLOW_REGISTRATION` is `false`. |
| `04-forgot-password.html` | Request a reset link | Sends the password-reset mail. |

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| `ALLOW_REGISTRATION` is a **real boolean** in `config.php` | The register screen has exactly two states: rendered, or **not rendered** (HTTP 403). The design must show the closed state as a page a visitor can actually land on. |
| The gate is enforced **twice** | Front end: the page does not render a form and returns 403. Back end: the POST handler rejects the request regardless of what was rendered. The mockup must not imply that hiding the form is the protection. |
| `users.username` and `users.email` are **`NOT NULL UNIQUE`** | Both are required at registration. The design must ask for both. |
| Passwords are stored **hashed**; the reset link is **time-limited** | The design must communicate "temporary" and "one-time" without exposing implementation detail. |
| The mailer uses the **SMTP settings from `config.php`** | The forgot-password screen needs a **"mail is not configured"** state — otherwise the user waits forever for a mail that will never arrive. |
| The application is server-rendered | The screens are plain forms. No client-side router, no SPA. |
| Every form needs a **CSRF token** | The design shows a hidden field as an implementation note, or omits it — but it must not contradict the server contract. |
| The screens are **unauthenticated** | No navigation bar, no app shell, no user context. |

---

## 4. Shared behaviour of the auth screens

| Aspect | Decision |
|---|---|
| **Layout** | A single centred column, max ≈ 420 px, on a plain page background. No sidebar, no top nav. |
| **Branding** | The product name and a one-line description of what the app is. |
| **Footer** | A single line with a muted note. No link farm. |
| **Cross-links** | Login ↔ Register ↔ Forgot password. Every screen shows the relevant cross-link, except the registration-closed screen, which must **not** link to a register form that cannot work. |
| **Password field** | A reveal toggle is welcome; the mockup shows the **hidden** state by default and the revealed state as a variant. |
| **Autofill** | Mark up `autocomplete` correctly: `username`, `current-password`, `new-password`, `email`. |
| **Submit button** | Shows the **loading** state, and the **disabled** state while the required fields are empty. |
| **Error presentation** | One alert block above the form, and a per-field message under the field. Both are present in the design for the server-rejection case. |
| **Error copy** | Never "Error: 403". Say what happened and what to do next. |
| **Dark mode** | Designed, not derived. |
| **Mobile** | The column is full-width with 16 px gutters; the keyboard-type hints are correct (`type="email"`, `inputmode="decimal"` is not needed here). |

---

## 5. `01-login.html` — sign in

### Fields

| Field | Type | Validation shown | Notes |
|---|---|---|---|
| Username or email | `text`, `autocomplete="username"` | — | The application accepts either; the mockup says so in the field hint. |
| Password | `password`, `autocomplete="current-password"` | — | Reveal toggle. |

### Actions

- Primary: **Sign in** (loading state shown).
- Secondary link: **Forgot password?** → `04-forgot-password.html`.
- Secondary link: **Create an account** → `02-register.html`, **only when registration is open**. When it is closed, this link is absent — and `03-register-closed.html` shows what replaces it (a plain sentence, not a broken link).

### States to render on this screen

| State | What is shown |
|---|---|
| **Empty** | The default state of the screen. |
| **Validation error** | Empty password → the field error only, focus moved to the field. |
| **Server rejection — bad credentials** | One alert: "That username and password do not match." **No hint about which half was wrong** — that would confirm whether an account exists. |
| **Server rejection — too many attempts** | One alert with the wait: "Too many attempts. Try again in N minutes." This state must exist in the design: the application limits sign-in attempts. |
| **Server rejection — account locked / disabled** | One alert: "This account is not available." |
| **Server error (500)** | One generic alert: "Something went wrong on our side. Please try again." Never a stack trace, never the raw error. |
| **After a successful sign-in** | Not a screen state — the design shows the redirect target (the dashboard) as a note. |

> **Design intent:** the sign-in screen is the one screen where a wrong
> password is *expected*. It must not be an aggressive, hostile screen. One
> clear message, one retry, no blocking.

---

## 6. `02-register.html` — create account

### Fields

| Field | Type | Validation shown | Notes |
|---|---|---|---|
| Username | `text`, `autocomplete="username"` | 3–32 characters; letters, digits, underscore, hyphen. | **Unique** — the server may reject it. |
| Email | `email`, `autocomplete="email"` | Valid address; **unique**. | Used for the password reset. |
| Password | `password`, `autocomplete="new-password"` | Minimum length (recommendation: 10); must differ from the confirmation. | |
| Confirm password | `password`, `autocomplete="new-password"` | Must match. | |

### Actions

- Primary: **Create account**.
- Secondary link: **I already have an account** → `01-login.html`.

### States to render on this screen

| State | What is shown |
|---|---|
| **Empty** | The default state. |
| **Client-side validation** | Per-field messages under the offending field. |
| **Server rejection — username taken** | The error is attached to the **username** field, and the message does not confirm the address behind the taken username. |
| **Server rejection — email taken** | The error is attached to the **email** field. |
| **Server rejection — password too weak** | Attached to the password field. |
| **Success** | The design shows the confirmation state: the form is replaced by a success notice and a link to sign in. (The application may sign the new user in directly; the mockup shows the **sign-in** outcome and notes the alternative.) |
| **Registration closed** | This screen is **not served**. The visitor lands on `03-register-closed.html` instead. |

> The mockup must **not** be the only protection. The application rejects the
> POST server-side regardless of what was rendered — the design must not
> suggest otherwise.

---

## 7. `03-register-closed.html` — registration disabled

This screen is reached when `ALLOW_REGISTRATION === false`.

### Content

| Element | Content |
|---|---|
| Title | "Registration is closed" |
| Explanation | One sentence, in plain language: "This tracker is currently in private use, so new accounts cannot be created." |
| What to do instead | A link to **Sign in**. If the visitor has no account, a sentence saying so — **not** a "register" link. |
| Tone | Neutral and brief. **Not** an apology, not a 403 dump, not a "Sorry, something went wrong". |
| Product identity | The product name is present, so the page does not look like a broken link. |

### Rules

- The screen must **not** contain a register form, a disabled register form, or a link that would 403.
- It must not reveal **who** closed it, or offer a contact address for it.
- It must not display a raw HTTP status code.
- It should show the **200**-rendered page. (The application returns 403 for the
  *register route* in some flows; the mockup documents the page as the
  visitor-facing experience and notes the status code in an implementation
  note for the developer.)

> **Implementation note for the developer:** the application must close
> registration on **both** sides — the page does not render the form, **and**
> the POST handler rejects the request. A check in only one of the two places
> is a bug.

---

## 8. `04-forgot-password.html` — request a reset link

### Fields

| Field | Type | Validation shown | Notes |
|---|---|---|---|
| Email | `email`, `autocomplete="email"` | Valid address. | The only identifier used. |

### Actions

- Primary: **Send reset link**.
- Secondary link: **Back to sign in**.

### States to render on this screen

| State | What is shown |
|---|---|
| **Empty** | The default state. |
| **Invalid address** | Field error: "Enter a valid email address." |
| **Submitted — address known** | A neutral confirmation: "If that address has an account, a reset link is on its way." **The wording must not confirm that the address exists.** |
| **Submitted — address unknown** | **Exactly the same confirmation.** The response is identical in both cases. |
| **Mail not configured** | A distinct, actionable notice: "Email is not configured for this installation, so reset links cannot be sent." This state exists because SMTP settings are optional and may be blank. |
| **Send failed** | A generic notice plus a retry. Never a raw SMTP error to the visitor. |

### Rules

- The screen must **never** say "no account with that address". That is an
  account-enumeration leak.
- The reset link itself is **time-limited** and **single-use**. The design
  does not show the link, but it may show a note that the link expires.
- The design must not show a "resend" button that reveals anything about
  whether the previous attempt succeeded.

---

## 9. Acceptance criteria

- [ ] All four files exist, link the shared stylesheets by **relative** path, and render from `file://`.
- [ ] All four files link `assets/tokens.css`, `assets/base.css`, `assets/components.css` and `assets/mockup.js` — in that order.
- [ ] Every form control has a `<label>`; the error message is associated with its control.
- [ ] Every screen in §5–§8 shows **all** the states listed for it.
- [ ] `01-login.html` shows the "too many attempts" state.
- [ ] `02-register.html` shows a server-side rejection attached to the field, not only a client-side message.
- [ ] `03-register-closed.html` contains **no** register form and **no** link to a register route.
- [ ] `04-forgot-password.html` shows the "mail not configured" state.
- [ ] The "known address" and "unknown address" confirmations in `04-forgot-password.html` are **word-for-word identical**.
- [ ] The bad-credentials message in `01-login.html` does not say which half was wrong.
- [ ] All four screens fit 360 px wide with no horizontal scrolling.
- [ ] All four screens are legible and complete in both light and dark mode.

---

## 10. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 1. The files exist
ls -la docs/mockup/0*.html

# 2. Relative paths only
grep -RIn 'href="/\|src="/' docs/mockup/0*.html && echo "FAIL: absolute path" || echo "OK"

# 3. No inline scripts
grep -RIn '<script>' docs/mockup/0*.html && echo "FAIL: inline script" || echo "OK"

# 4. Every screen has exactly one <h1>
for f in docs/mockup/0*.html; do printf "%-40s h1=%s\n" "$f" "$(grep -c '<h1' "$f")"; done

# 5. Every input has a label
grep -c '<label' docs/mockup/0*.html

# 6. The closed-registration screen has no form
grep -c '<form' docs/mockup/03-register-closed.html     # must be 0

# 7. The enumeration check: the two confirmations must be identical
diff <(grep -o 'If that address has an account[^<]*' docs/mockup/04-forgot-password.html | sort -u | wc -l) <(echo 1)

# 8. It renders in the viewer
docker compose up -d mockup
for f in 01-login 02-register 03-register-closed 04-forgot-password; do
  printf "%-26s " "$f"
  curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:${MOCKUP_PORT:-8089}/$f.html"
done
````

---

## 11. Handoff notes for the application implementer

| Mockup element | Application obligation |
|---|---|
| The cross-link between login and register | Rendered **only** when `ALLOW_REGISTRATION === true`. The link is a convenience, not the gate. |
| `03-register-closed.html` | The register route returns **403** and renders this page (or an equivalent). The POST handler rejects the request **independently**. |
| The "too many attempts" state | The server enforces a sign-in attempt limit; the design must match the server's copy and timing. |
| The "mail not configured" state | Shown when the SMTP constants are empty. The server publishes the condition; the client must not re-derive it. |
| The identical confirmations | Enforced server-side: the response must not vary with the address. |
| Every form | A CSRF token in a hidden field, validated **before** any input is read. |

---

## 12. Out of scope

- **Social login, SSO, two-factor authentication.** Not in the brief.
- **A "remember me" checkbox.** The session lifetime is a configuration
  concern, not a design decision.
- **Password strength meters.** A plain requirement message is enough.
- **Account recovery by security question.**
- **A user-facing "account deleted" flow.**
- **Copywriting for a brand voice.** The tone is specified above; the exact
  wording is the designer's call.
