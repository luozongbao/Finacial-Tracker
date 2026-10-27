# Issue-008 — UX/UI: install wizard screen

> **Status:** ready for UX/UI pickup.
> **Target:** v0.1 (design foundation).
> **Severity:** **n/a** — design deliverable, no application impact.
>
> **Requested by the user:**
> > "alsow write issue files for UX/UI design to write mockup html in /docs/mockup/"
>
> **Depends on:** [issue-004.md](issue-004.md) (tokens, shell, components).
> **Related:** `AGENTS.md`, "Install and config contract";
> [issue-003.md](issue-003.md) (the web-root hardening the installer lives
> behind).

---

## Table of contents

1. Objective
2. Deliverable
3. Constraints
4. What the wizard must collect
5. The flow
6. The screens, step by step
7. Acceptance criteria
8. Verification
9. Handoff notes for the application implementer
10. Out of scope

---

## 1. Objective

> Deliver a mockup of the **install wizard** — the one screen that is not
> part of the everyday product, but that every user sees exactly once — that
> specifies the fields, the step order, the per-step validation and the
> failure states precisely enough to implement `install.php` from.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `11-install-wizard.html` exists and renders standalone from `file://` |
| 2 | The wizard collects exactly the eight values the config contract names, and nothing else |
| 3 | Each step's validation and failure states are shown |
| 4 | The password fields are typed `password` and are **never** echoed back |
| 5 | The wizard has a clear final state that tells the operator what to do next |
| 6 | The design says plainly that the installer must be removed after a successful install |

---

## 2. Deliverable

| File | Screen | Purpose |
|---|---|---|
| `11-install-wizard.html` | Install wizard | Collects the database and SMTP settings and writes `config.php`. |

> This screen is served by `install.php`, **not** by `index.php`. The mockup
> file is a static artefact in `docs/mockup/`; the application route is a
> separate PHP page with the same design.

---

## 3. Constraints

| Constraint | Consequence |
|---|---|
| `install.php` writes `config.php` from **user input**: database host, database name, database user, database password, SMTP server, SMTP port, email user, SMTP password | Exactly **eight** values. No more. |
| `config.php` also carries `ALLOW_REGISTRATION`, defaulting to `true` | The wizard writes it as a **real boolean**. The design shows it as a switch, defaulting to on, with a clear explanation of what turning it off does. |
| `config.php` is the **only** configuration source | The wizard writes the file; nothing else reads environment variables. |
| The installer is **unauthenticated and runs before the app exists** | No app shell, no navigation, no session, no user context. A single centred column. |
| `config.php` is **git-ignored**; `config.template.php` is the tracked twin with the **same constant set** | The wizard writes every constant the template declares, and no others. The design must not introduce an extra field that has no home in the template. |
| The values are written with `var_export()` | A boolean stays a boolean; the strings stay strings. The design must not imply a "true/false" text field. |
| `install.php` must be **deleted or locked** after a successful install | The final step must say so, in the design, as an instruction to the operator. |
| Password fields | Typed `password`, never pre-filled, never echoed back to the browser, and the page must not be cached. |
| The page is reachable **before** `config.php` exists and must **stop** being reachable once it does | The design's final state covers this: after a successful install, a reload lands on the dashboard, not on the wizard. |

---

## 4. What the wizard must collect

| # | Value | Constant | Field | Required | Notes |
|---|---|---|---|---|---|
| 1 | Database host | `DB_HOST` | text | yes | Defaults to `localhost`. In the Docker stack it is the service name `db`. |
| 2 | Database name | `DB_NAME` | text | yes | The schema, e.g. `financial_tracker`. |
| 3 | Database user | `DB_USER` | text | yes | |
| 4 | Database password | `DB_PASSWORD` | password | yes | Never echoed back. |
| 5 | SMTP server | `SMTP_HOST` | text | no | May be left blank — the application then cannot send mail. |
| 6 | SMTP port | `SMTP_PORT` | number | no | Default 587. |
| 7 | Email user | `SMTP_USER` | text | no | |
| 8 | SMTP password | `SMTP_PASS` | password | no | |
| — | Registration switch | `ALLOW_REGISTRATION` | switch | no | Defaults to **on**. |

The database host **is** collected, because `config.template.php` declares
`DB_HOST` and the wizard writes every constant the template declares. The
field is pre-filled with `localhost`, which is the correct value for a normal
installation; the Docker stack overrides it with the service name `db`.

---

## 5. The flow

````text
        ┌──────────────┐
        │  Step 1 of 3 │   Database
        │  Database    │   host, name, user, password
        └──────┬───────┘
               │  validate → test the connection
               ▼
        ┌──────────────┐
        │  Step 2 of 3 │   Mail (optional)
        │  SMTP        │   host, port, user, password
        └──────┬───────┘
               │  validate → test the SMTP connection
               ▼
        ┌──────────────┐
        │  Step 3 of 3 │   Access
        │  Registration│   ALLOW_REGISTRATION switch
        │  + summary   │   summary of everything entered
        └──────┬───────┘
               │  write config.php → create the schema
               ▼
        ┌──────────────┐
        │   Success    │   "Install complete" + what to do next
        └──────────────┘
````

**Three steps, one page, one form.** The mockup shows all three steps stacked
in a single scrolling page, with the current step highlighted — a
stepper-style layout. Rationale: the wizard runs once, on a personal
installation, and a multi-page flow adds CSRF-state complexity for no benefit.

A **step indicator** at the top shows which step is being filled. The
indicator is **not** interactive — the steps are not individually navigable,
because steps 2 and 3 depend on step 1 succeeding.

---

## 6. The screens, step by step

### Step 1 — Database

| Field | Validation shown | Notes |
|---|---|---|
| Database host | Non-empty. | Pre-filled with `localhost`. A hostname or an IP address. |
| Database name | Non-empty; a valid identifier (letters, digits, underscore). | |
| Database user | Non-empty. | |
| Database password | Non-empty. | |

**Step-level action:** **Test connection and continue**.

**Failure states:**

| State | What is shown |
|---|---|
| Connection refused | A step-level alert: "Could not connect to the database. Check the name, user and password, and that the server is running." The server error is **not** shown verbatim. |
| Unknown database | The same alert, with a hint that the database must exist already — the installer connects to it, it does not create it. |
| Permission denied | The same alert, with a hint about grants. |

### Step 2 — Mail (optional)

| Field | Validation shown | Notes |
|---|---|---|
| SMTP host | Optional. If any SMTP field is filled, the host is required. | |
| SMTP port | A number between 1 and 65535. Default 587. | |
| Email user | Optional; required once the host is set. | |
| SMTP password | Optional; required once the host is set. | |

**Step-level action:** **Skip mail setup and continue** / **Test and continue**.
Both are present. Skipping is a first-class choice, not a failure.

**Failure states:**

| State | What is shown |
|---|---|
| Partial configuration | A field-level message: "If you set an SMTP host, the user and password are required too." |
| Connection refused / auth failed | A step-level alert: "Could not connect to the mail server." with a generic message. |

### Step 3 — Access and summary

| Element | Content |
|---|---|
| The registration switch | **On** by default, with a one-line explanation: "When off, no one can create an account, and the register page returns 403." The design shows **both** switch positions. |
| The summary | A read-only list of everything that will be written: the database host, the database name, the user, the SMTP host and port, and the registration state. **The database password and the SMTP password are shown as masked, and only in this summary** — the design shows `••••••••` with a note that they are stored as given. |

**Step-level action:** **Install**.

**Failure states:**

| State | What is shown |
|---|---|
| `config.php` is not writable | A step-level alert: "The configuration file could not be written. Check that the project directory is writable." This is a real failure mode — the document root is a bind mount. |
| The schema could not be applied | A step-level alert: "The database schema could not be created." |
| A partial write | The alert says the configuration was **not** written and that the installer can be run again. |

### The success state

| Element | Content |
|---|---|
| Title | "Install complete" |
| Confirmation | One sentence: "Your configuration has been written to `config.php`." |
| **What to do next** | The critical instruction, in a prominent block: **"Delete `install.php` now."** With the reason: a live installer is a re-configuration hole. |
| The primary action | **Go to the dashboard**. |
| A note | "Sign in with the account you create after signing in." — the installer does **not** create a user. |

---

## 7. Acceptance criteria

- [ ] `11-install-wizard.html` exists, links the shared stylesheets by relative path, and renders from `file://`.
- [ ] The form collects exactly the eight values in §4 — no more fields.
- [ ] There **is** a database-host field, pre-filled with `localhost`.
- [ ] The registration control is a **switch**, not a text field, and its default is on.
- [ ] The switch's explanation mentions that the register page returns 403 when it is off.
- [ ] Both password fields are `type="password"` and are never pre-filled.
- [ ] No password value appears in the HTML source, except as a mask in the summary.
- [ ] The stepper shows three steps with the current one highlighted, and it is not a set of links.
- [ ] Step 1 shows the three connection-failure states.
- [ ] Step 2 shows a **skip** action next to the test action.
- [ ] Step 3 shows the summary and the "not writable" failure state.
- [ ] The success state contains a prominent **"Delete `install.php`"** instruction.
- [ ] The wizard has no app navigation and no footer link farm.
- [ ] The wizard fits 360 px without horizontal scrolling and is legible in light and dark mode.

---

## 8. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 1. The file exists
ls -la docs/mockup/11-install-wizard.html

# 2. Relative paths only
grep -In 'href="/\|src="/' docs/mockup/11-install-wizard.html && echo "FAIL: absolute path" || echo "OK"

# 3. No inline scripts
grep -In '<script>' docs/mockup/11-install-wizard.html && echo "FAIL: inline script" || echo "OK"

# 4. The password inputs are masked
grep -c 'type="password"' docs/mockup/11-install-wizard.html    # must be >= 2

# 5. No secret value in the source (only a mask)
#    The allow-list covers the NON-secret values that legitimately appear:
#    the database name, the port, the database/SMTP user names and the
#    example mail host. A password may never appear as a literal value.
grep -oE 'value="[^"]{3,}"' docs/mockup/11-install-wizard.html \
  | grep -viE 'financial_tracker|587|localhost|smtp|mail|user|tracker' \
  && echo "FAIL: literal value" || echo "OK"

# 6. The delete-installer instruction is present
grep -i 'delete' docs/mockup/11-install-wizard.html | head -3

# 7. Exactly one <h1>
grep -c '<h1' docs/mockup/11-install-wizard.html

# 8. It renders in the viewer
docker compose up -d mockup
curl -sS -o /dev/null -w "GET /11-install-wizard.html -> %{http_code}\n" \
  "http://localhost:${MOCKUP_PORT:-8089}/11-install-wizard.html"
````

---

## 9. Handoff notes for the application implementer

| Mockup element | Application obligation |
|---|---|
| The three steps | Rendered by **one** page and one form. `install.php` is a single script. |
| "Test connection" | The server actually attempts the connection before writing anything. The mockup's step-level alerts mirror the server's responses. |
| The registration switch | Written as `var_export(true, true)` / `var_export(false, true)` → a **real boolean**. Never the strings `'true'` / `'false'`. |
| The masked summary | The database and SMTP passwords are masked in the summary. They are still written to the file in plain text — `config.php` is git-ignored and must stay that way. |
| "Delete `install.php`" | The success page instructs the operator to delete it. The application must also stop offering the installer once `config.php` exists. |
| The write failure | `config.php` must be writable by the web server user. On the dev stack the LSPHP worker runs as uid **1000**, which matches the host user. |
| Every step | A CSRF token in a hidden field, validated **before** any input is read. |
| The whole page | `Cache-Control: no-store`, so a password is never left in a shared browser cache. |

---

## 10. Out of scope

- **Creating the database itself.** The installer connects to an existing
  database; it does not create one.
- **Creating the first user account.** The installer writes the
  configuration; the user signs in and registers through the normal flow —
  which is why `ALLOW_REGISTRATION` matters at this point.
- **A multi-page wizard with per-step URLs.** One page, one form.
- **Branding the installer differently.** It uses the same tokens.
- **An "update configuration" mode.** The installer is a first-run tool. Once
  `config.php` exists, the installer is deleted — re-running it is a
  deliberate, manual action.
