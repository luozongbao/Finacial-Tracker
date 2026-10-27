# Issue-002 — Ops: serve the UX/UI mockups from `docs/mockup/` on a dedicated static container

> **Status:** reference implementation provided — ready for ops pickup and verification.
> **Target:** v0.1 (development infrastructure).
> **Severity:** **n/a** — additive infrastructure, no application impact.
>
> **Requested by the user:**
> > "create docker compose file for the project **and for html mockup files** …
> > the mockup files should be in /docs/mockup/"
>
> **Depends on:** [issue-001.md](issue-001.md) (the compose stack).
> **Related:** the UX/UI issues [issue-004.md](issue-004.md) … [issue-008.md](issue-008.md).

---

## Table of contents

1. Objective
2. Constraints
3. Target architecture
4. Specification — the `mockup` service
5. Specification — `docker/mockup-nginx.conf`
6. Specification — `docs/mockup/`
7. Acceptance criteria
8. Verification
9. Operating the mockup viewer
10. Rollback
11. Security notes
12. Out of scope

---

## 1. Objective

> Provide a **zero-risk, zero-application-impact** way to browse the UX/UI
> mockups in `docs/mockup/` from a browser on the developer's machine, at
> **`http://localhost:8089/`**, without starting or modifying the application
> stack.

Success criteria:

| # | Criterion |
|---|---|
| 1 | `docker compose up -d mockup` starts a static server on port 8089 |
| 2 | `http://localhost:8089/` serves the mockup gallery |
| 3 | Every mockup file and every shared asset is reachable by a **relative** link |
| 4 | The service is **read-only** and can see **nothing** outside `docs/mockup/` |
| 5 | The application stack (`app`, `db`, `phpmyadmin`) is **not** started or modified |
| 6 | `docker compose up -d` (the normal command) is **unchanged** — the mockup does not start |
| 7 | `docker compose down` leaves no stale container |
| 8 | The service is removable with a single documented command |

---

## 2. Constraints

| Constraint | Consequence |
|---|---|
| The mockups are **pure static HTML/CSS/JS** | No PHP, no database, no application dependency. |
| The mockups live in `docs/mockup/`, inside the repository | A service that mounts the whole repository would publish `docs/issues/`, `database/` and the git metadata. Only `docs/mockup/` may be mounted. |
| `docs/mockup/` is a **live folder** — UX adds files to it | The mount must be a bind mount, not a copy, so a new file is visible immediately. |
| The mockups are **design artefacts**, not application code | They must never be executable. The static server has no PHP handler at all. |
| The app port is 8088 and is often in use | The mockup uses **8089**, overridable via `MOCKUP_PORT`. |
| `docker compose up -d` must stay a simple command | The mockup sits behind a compose **profile** so it is opt-in. |
| The UX team may use a generator script | Its source and its build directory must not be downloadable. |

---

## 3. Target architecture

````text
host
 │
 ├─ :8089 ──► mockup (nginx:alpine)          [profile: mockup]
 │              root  /usr/share/nginx/html
 │              bind  ./docs/mockup            (read-only)
 │              bind  ./docker/mockup-nginx.conf → default.conf (read-only)
 │              no PHP, no database, no depends_on
 │
 ├─ :8088 ──► app (LiteSpeed)                  the application — untouched
 └─        ──► db, phpmyadmin
````

The mockup container is **not** on the application’s dependency graph. It has
no `depends_on`, no database credentials and no access to the app’s network
services beyond the shared bridge network it does not need.

---

## 4. Specification — the `mockup` service

````yaml
  mockup:
    image: nginx:alpine
    container_name: ft-mockup
    restart: unless-stopped
    profiles:
      - mockup
    ports:
      - "${MOCKUP_PORT:-8089}:80"
    volumes:
      - ./docs/mockup:/usr/share/nginx/html:ro
      - ./docker/mockup-nginx.conf:/etc/nginx/conf.d/default.conf:ro
    healthcheck:
      test: ["CMD", "wget", "-q", "--spider", "http://127.0.0.1/health"]
      interval: 15s
      timeout: 5s
      retries: 3
      start_period: 5s
    networks:
      - ft-net
````

| Key | Value | Reason |
|---|---|---|
| `image` | `nginx:alpine` | Small, static-only, no PHP module. |
| `profiles` | `[mockup]` | A plain `docker compose up -d` must not start it. `docker compose up -d mockup` enables the profile for that service automatically. |
| `ports` | `"${MOCKUP_PORT:-8089}:80"` | Distinct from `APP_PORT`. |
| `volumes` | `./docs/mockup:/usr/share/nginx/html:ro` | The **only** repository path published. Read-only. |
| `volumes` | `./docker/mockup-nginx.conf:/etc/nginx/conf.d/default.conf:ro` | The server config. |
| `healthcheck` | `wget --spider /health` | `wget` ships with `nginx:alpine`; no extra tooling needed. |

---

## 5. Specification — `docker/mockup-nginx.conf`

| Directive | Purpose |
|---|---|
| `root /usr/share/nginx/html;` | The mockup document root. |
| `index index.html;` | `index.html` is the gallery entry point. |
| `charset utf-8;` | The mockups contain typographic characters. |
| `server_tokens off;` | Do not advertise the nginx version. |
| `location = /health` | Returns `200 ok` — used by the compose healthcheck. |
| `location / { try_files $uri $uri/ =404; }` | No directory listing, no fallback to `index.php`. |
| `location ~ /\. { return 404; }` | Dotfiles (`.git`, `.htaccess`, editor swap files) are never served. |
| `location ~* ^/_build/` | The generator’s build directory is never served. |
| `location ~* \.(?:php\|sh\|bash\|py\|pyc\|pyo\|bak\|swp\|tmp\|log\|sql\|ini\|env)$` | Source and scratch files are never served. |
| `expires 1h` on static assets | Cache-busting is the UX team’s business; one hour is a sane default. |
| `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy` | Baseline hardening, matching the sibling project’s mockup config. |

> The config is deliberately **separate** from the application’s web-server
> configuration. The application container serves the project at `/` and may
> serve `/docs/mockup/` as a convenience, but the static container is the
> sanctioned viewer: it cannot execute application code, and it never sees
> the rest of the repository.

---

## 6. Specification — `docs/mockup/`

The folder is the **contract between UX and the application**, not just a
drop target. Its contents are specified in
[docs/mockup/README.md](../mockup/README.md); the summary:

| Rule | Detail |
|---|---|
| One file per screen | `NN-screen-name.html`, two digits, kebab-case. |
| Self-contained | Every screen renders correctly from `file://`. No server-side includes. |
| Shared assets | `assets/` holds the shared stylesheet and script, referenced by **relative** path. |
| No absolute paths | Never `/assets/...` — it breaks both the `file://` case and the static container’s document root. |
| No frameworks | Vanilla CSS and vanilla JS only; no CDN dependency for anything structural. |
| States are part of the screen | Empty, loading, validation-error, success and permission-denied states live on the screen, not in separate files. |

### Fixed file inventory

| File | Screen | Specified in |
|---|---|---|
| `index.html` | Gallery / table of contents | [issue-004.md](issue-004.md) |
| `00-design-system.html` | Colour, type, spacing, components | [issue-004.md](issue-004.md) |
| `01-login.html` | Sign in | [issue-005.md](issue-005.md) |
| `02-register.html` | Create account | [issue-005.md](issue-005.md) |
| `03-register-closed.html` | Registration disabled | [issue-005.md](issue-005.md) |
| `04-forgot-password.html` | Request a reset link | [issue-005.md](issue-005.md) |
| `05-dashboard.html` | Dashboard and period summary | [issue-006.md](issue-006.md) |
| `06-transactions.html` | Transaction list, search and filters | [issue-006.md](issue-006.md) |
| `07-transaction-form.html` | Add / edit a transaction | [issue-006.md](issue-006.md) |
| `08-reports.html` | Day / week / month / year reports | [issue-007.md](issue-007.md) |
| `09-categories.html` | Category management | [issue-007.md](issue-007.md) |
| `10-settings.html` | Profile and settings | [issue-007.md](issue-007.md) |
| `11-install-wizard.html` | First-run installer | [issue-008.md](issue-008.md) |

A **placeholder gallery** (`index.html`) plus a token/shell skeleton
(`assets/tokens.css`, `assets/base.css`, `assets/components.css`,
`assets/mockup.js`) ship with the dev stack so the acceptance test below has
something to assert against. UX replaces and extends them.

---

## 7. Acceptance criteria

- [ ] `docker compose up -d mockup` starts `ft-mockup` and the healthcheck goes **healthy**.
- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:${MOCKUP_PORT:-8089}/` returns **200**.
- [ ] `curl -sS http://localhost:${MOCKUP_PORT:-8089}/` contains the gallery heading.
- [ ] `curl -sS -o /dev/null -w '%{http_code}' http://localhost:${MOCKUP_PORT:-8089}/health` returns **200** with the body `ok`.
- [ ] Every link on the gallery resolves (no 404) — check with `wget --spider` over each file. A screen that has not been delivered yet is rendered as **text**, not as a link, so it does not appear in this check.
- [ ] `curl -o /dev/null -w '%{http_code}' http://localhost:${MOCKUP_PORT:-8089}/.git/config` returns **404** (dotfiles are denied).
- [ ] `curl -o /dev/null -w '%{http_code}' http://localhost:${MOCKUP_PORT:-8089}/../.env` returns **404** (nothing outside the folder is reachable).
- [ ] `docker compose up -d` **does not** start the mockup service (`docker compose ps` shows only `app`, `db`, `phpmyadmin`).
- [ ] `docker compose up -d mockup` **does not** start `app` or `db`.
- [ ] The container mounts are read-only: `docker inspect ft-mockup` shows `RW: false` for both bind mounts.
- [ ] `docker compose down` removes `ft-mockup`.

---

## 8. Verification

````bash
cd /home/zongbao/projects/financial-tracker

# 1. Start only the mockup service
docker compose up -d mockup
docker compose ps mockup

# 2. It serves the gallery
curl -sS -o /dev/null -w "GET /        -> %{http_code}\n" http://localhost:${MOCKUP_PORT:-8089}/
curl -sS -o /dev/null -w "GET /health  -> %{http_code}\n" http://localhost:${MOCKUP_PORT:-8089}/health

# 3. Every linked file resolves (no 404 anywhere)
for f in $(grep -oE 'href="[^"#]+"' docs/mockup/index.html | sed 's/href="//; s/"//'); do
  printf "%-40s " "$f"
  curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:${MOCKUP_PORT:-8089}/$f"
done

# 4. Nothing outside docs/mockup is reachable
for f in .git/config ../.env docker/entrypoint.sh; do
  printf "%-40s " "$f"
  curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:${MOCKUP_PORT:-8089}/$f"
done

# 5. The nginx configuration itself is valid
docker compose exec -T mockup nginx -t

# 6. The application stack was not touched
docker compose ps
````

---

## 9. Operating the mockup viewer

````bash
docker compose up -d mockup       # start
docker compose ps mockup          # status
docker compose logs -f mockup     # follow the access log
docker compose restart mockup     # reload after an nginx.conf change
docker compose down mockup        # stop and remove it
````

> The mockups are **also** reachable through the application container at
> `http://localhost:${APP_PORT}/docs/mockup/` while the app stack is running.
> That path is convenient, but the static container is the sanctioned
> viewer — it is isolated, read-only, and cannot execute application code.

Because `docs/mockup/` is bind-mounted, **editing a mockup is visible
immediately** — no reload of the container is needed. A hard refresh in the
browser may be required for CSS changes.

---

## 10. Rollback

````bash
docker compose down mockup        # remove the container and the port mapping
````

Nothing else changes: no volume, no data, no application state. To also drop
the profile from the compose file, delete the `mockup:` service block and the
`MOCKUP_PORT` entry from `.env` / `.env.example`.

---

## 11. Security notes

| Concern | Mitigation |
|---|---|
| The mockup container could publish the whole repository | Only `./docs/mockup` is mounted. The rest of the tree is not in the container. |
| Dotfiles could leak `.git` metadata | `location ~ /\.` returns 404. |
| A generator script or a `.sql` dump could be downloaded | Explicit deny rules for `_build/` and for source extensions. |
| PHP could be executed | There is no PHP handler in `nginx:alpine` — a `.php` file is served as text, and the deny rule refuses it anyway. |
| The mockup might be mistaken for the application | The gallery states plainly that it is a placeholder, and every screen links back to the issue that specifies it. |
| The mockup is reachable from the host only | The port is published to the host, not to the compose network’s peers beyond the host. Do not bind it to `0.0.0.0` on a public host without a firewall. |

---

## 12. Out of scope

- **Applying the mockups to the application.** The mockups are a specification;
  the implementation is a separate, later task.
- **A CMS or a static-site generator.** The mockups are hand-written HTML.
- **Design assets** (icons, illustrations, fonts). The UX issues allow a CDN
  icon font, but nothing structural may depend on it.
- **Versioning of mockups.** Git history is the versioning mechanism.
