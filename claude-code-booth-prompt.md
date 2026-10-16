# Claude Code Prompt: Build CourtQ (Arena Booth Congestion & Stock App) in a new repo

Reply in Japanese. Commit messages, PR titles and PR descriptions in Japanese with a Conventional Commits prefix (see CLAUDE.md).

## 0. Background (read first)

My ADCP evaluation goal (self-written) defines level 3 as "ブース一覧・詳細・混雑状況投稿などの基本機能が完了し、公開可能な状態".
We are building the app I originally planned in a **new, empty GitHub repository `courtq`** (cloned at `~/courtq`, current working directory).
My previous project **Spovie** (video annotation) lives at `~/spovie` and stays as it is — treat it as a **read-only reference**.
Copy and adapt its proven foundation (Docker setup, Laravel + Sanctum auth — Spovie is on Laravel 10, but **CourtQ uses Laravel 12 / Sanctum 4 / PHP 8.2+** (decided after the B1 bootstrap: Laravel 10 no longer receives security fixes), Service-layer / FormRequest / Policy / Resource rules,
JSON error handling, CI workflow, production Dockerfile + Railway configs, Vercel config, React + TS + Vite setup, API client,
auth store, Echo/Pusher setup with the "disabled when keys are missing" behavior, PWA setup, smoke test, `docs:api` generator, CLAUDE.md rules).
Do NOT copy any Spovie domain code (video, YouTube, annotation, Fabric.js canvas, clip/FFmpeg, share link, comment, team, S3/R2 storage).
Never modify files in `~/spovie`.

**Product:** A web app (PWA) for basketball arena game days (modeled on B.LEAGUE games). Visitors see, in real time,
how crowded each food/merch booth is (4 levels + wait minutes) and the stock status of each menu item —
like the wait-time display of a theme park app.

The app name is **CourtQ** (コートキュー = Court + Queue). Set `APP_NAME=CourtQ` / `VITE_APP_NAME=CourtQ` and keep the name in one constants file. Rename the GitHub-facing texts (README title, PWA manifest name/short_name, page titles) to CourtQ.

**Logo assets** are already in `courtq-logo/` at the repo root (final; do not redesign): `courtq-app-icon.svg`, `courtq-mark.svg`, `courtq-logo.svg`, `courtq-logo-dark.svg`, `courtq-icon-512.png`, `courtq-icon-192.png`. Use them for the header logo, favicon, apple-touch-icon and PWA icons (generate a maskable version with enough padding). Brand colors: navy `#14213D`, orange `#FF7A1A`. The congestion colors (green/yellow/orange/red) are separate status colors; make sure the brand orange is not confused with the 「混雑」 orange (use a different shade for the status). Do NOT use the B.LEAGUE name, team logos or official branding anywhere in the UI or demo data. Demo teams, venues and booths must be fictional.

## 1. Fixed decisions (do not change without asking me)

1. **Games**: modeled on the B.LEAGUE schedule format (season, section/節, date, tip-off time, home team, away team, venue). Admins register games manually or by CSV import. Automatic sync with an external schedule is out of scope (write it as future work; do not scrape any website).
2. **Congestion**: 4 levels — 1 空いている / 2 やや混雑 / 3 混雑 / 4 非常に混雑 — plus wait time in minutes (integer 0–180, nullable).
3. **Only staff (and admins) can write** congestion/stock. Enforce on the server: auth + role middleware + Policy (booth ownership) + FormRequest validation + rate limiting. A visitor calling the write API directly with their own token must get 403. Add feature tests for exactly that.
4. **Stock**: 3 states — 在庫あり / 残りわずか / 売り切れ. Quantity is optional (nullable integer).
5. **Freshness**: every congestion/stock value returns `updated_at`; the UI shows "最終更新 ○分前". If older than 15 minutes, show a "情報が古い可能性があります" hint (threshold in one config constant).
6. **Staff ↔ booth**: `booth_staff` pivot. Staff can update only their own booths. Admin issues an invitation code per booth; a logged-in user enters the code, becomes `staff` and is linked to that booth. Codes: random, single-use, expiry (default 7 days).
7. **Login**: mock login with email + password (Sanctum tokens, existing flow). Design the auth so an external ID provider (SSO/OAuth, e.g. a league account) can be added later: keep login logic inside `AuthService`, add nullable `provider` / `provider_user_id` columns on users, and document it.
8. **History**: every update appends a row to `congestion_logs` / `stock_logs` with the updater's user id. Expose a simple congestion history endpoint for a per-booth trend chart.
9. **Weak connectivity**: PWA caches the last fetched lists (NetworkFirst, short timeout) and shows "最終更新" and an offline banner. When the realtime connection drops and reconnects, refetch the lists. (Operational note for docs: at a real venue, a dedicated local Wi-Fi is recommended.)
10. **Load**: list endpoints return a light payload and use a short server cache (10–30 s, invalidated on update). Pusher: **one channel per game** (`game.{gameId}`).

## 2. Roles and permissions

`users.role`: `user` (default) / `staff` / `admin`. Admins are created by an artisan command (`php artisan app:create-admin {email}`) and by the demo seeder. Never trust a role sent from the client.

| Action | user | staff | admin |
|---|---|---|---|
| View games, booth lists, booth detail, menu, stock, history | ✓ | ✓ | ✓ |
| Favorites | ✓ | ✓ | ✓ |
| Update congestion of own booth | – | ✓ | ✓ (any booth) |
| Update stock / manage menu of own booth | – | ✓ | ✓ (any booth) |
| Create/edit games, CSV import, assign booths to a game | – | – | ✓ |
| Create/edit booths, issue invitation codes, remove staff | – | – | ✓ |

## 3. Data model (fresh migrations in the new repo)

- `users`: add `role` (enum, default `user`), `provider`, `provider_user_id` (nullable).
- `venues`: name, address (nullable), map_note (nullable).
- `games`: season, section (nullable), home_team, away_team, venue_id, tipoff_at (datetime), status (`scheduled`/`live`/`finished`).
- `booths`: name, category (`food`/`merch`/`both`), location_label (e.g. "2F コンコース東"), description (nullable).
- `game_booths`: game_id, booth_id, is_open, congestion_level (nullable 1–4), wait_minutes (nullable), congestion_updated_at, congestion_updated_by. Unique (game_id, booth_id).
- `menu_items`: booth_id, name, kind (`food`/`merch`), price (int, yen), description (nullable), is_active.
- `item_stocks`: game_id, menu_item_id, status (`in_stock`/`low`/`sold_out`), quantity (nullable), updated_at, updated_by. Unique (game_id, menu_item_id).
- `booth_staff`: booth_id, user_id. Unique pair.
- `staff_invitations`: booth_id, code (unique), expires_at, used_at, used_by, created_by.
- `congestion_logs`: game_booth_id, level, wait_minutes, user_id, created_at.
- `stock_logs`: item_stock_id, status, quantity, user_id, created_at.
- `favorites`: user_id, booth_id. Unique pair.

Images are out of scope for the MVP (use a category icon), so R2/S3 is not needed. Do not include FFmpeg in the Dockerfile.

## 4. API (all under `/api`, all require `auth:sanctum` except register/login/health)

Use these IDs in docs and tests.

| ID | Method & path | Role |
|---|---|---|
| AUTH-01..04 | register / login / logout / `GET /me` (includes role and staff booths) | – |
| GAME-01 | `GET /games?date=&from=` (today first) | any |
| GAME-02 | `GET /games/{game}` | any |
| BOOTH-01 | `GET /games/{game}/booths?category=&sort=congestion\|wait\|name&in_stock=1&favorites=1` (light payload, cached) | any |
| BOOTH-02 | `GET /games/{game}/booths/{booth}` (menu + stock + freshness) | any |
| BOOTH-03 | `GET /games/{game}/booths/{booth}/congestion-history` | any |
| FAV-01..03 | `GET /me/favorites`, `PUT /booths/{booth}/favorite`, `DELETE /booths/{booth}/favorite` | any |
| STAFF-01 | `GET /staff/booths` | staff, admin |
| STAFF-02 | `PUT /staff/games/{game}/booths/{booth}/congestion` | staff (own), admin |
| STAFF-03 | `PUT /staff/games/{game}/menu-items/{item}/stock` | staff (own), admin |
| STAFF-04 | `POST/PUT/DELETE /staff/booths/{booth}/menu-items[/{item}]` | staff (own), admin |
| STAFF-05 | `POST /staff/invitations/accept` (body: code) | any logged-in |
| ADMIN-01 | `/admin/games` CRUD | admin |
| ADMIN-02 | `POST /admin/games/import` (CSV) | admin |
| ADMIN-03 | `/admin/booths` CRUD | admin |
| ADMIN-04 | `PUT /admin/games/{game}/booths` (assign booths to a game) | admin |
| ADMIN-05 | `POST /admin/booths/{booth}/invitations` | admin |
| ADMIN-06 | `GET /admin/booths/{booth}/staff`, `DELETE /admin/booths/{booth}/staff/{user}` | admin |

Security rules:
- Route groups: `auth:sanctum` → `role:staff,admin` or `role:admin` (new `EnsureRole` middleware) → Policy check (`BoothPolicy@manage`: admin, or user is in `booth_staff`).
- `throttle` on STAFF-02/03 (e.g. 30/min per user) and on login.
- Explain in the docs: CORS does not stop direct API calls; authorization on the server is what protects the write APIs.

## 5. Realtime

- Public channel `game.{gameId}` (data is not sensitive, avoids per-viewer auth load). Events: `BoothCongestionUpdated` (game_booth id, level, wait_minutes, updated_at) and `ItemStockUpdated` (item id, status, quantity, updated_at). Small payloads only. Broadcast via the queue (existing worker).
- Frontend subscribes to `game.{gameId}` on the booth list and detail screens, patches local state, and refetches on reconnect. Keep the existing "リアルタイム接続中" badge and the "realtime disabled when keys are missing" behavior.

## 6. Screens (React, mobile-first)

| ID | Screen | Role |
|---|---|---|
| S01 / S02 | Login / Register | – |
| S03 | Game list (today first) | any |
| S04 | Booth list for a game: congestion badge + wait minutes + 最終更新, filter/sort, favorites | any |
| S05 | Booth detail: menu split into 飲食 / 物販 with stock badges, congestion trend chart | any |
| S06 | Favorites | any |
| S07 | Staff: my booths | staff |
| S08 | Staff: quick update — 4 big congestion buttons + wait-minutes stepper; per-item stock toggles (2 taps max) | staff |
| S09 | Staff: menu management | staff |
| S10 | Enter invitation code | logged-in |
| S11–S14 | Admin: games (+CSV import), booths, assign booths to game, invitations & staff | admin |

Design rules (keep it simple and easy to read):
- Mobile-first, large tap targets, at most one primary action per screen.
- Congestion colors: green / yellow / orange / red, **always with a text label** (never color alone).
- Stock badges: 在庫あり / 残りわずか / 売り切れ (売り切れ greyed out).
- Show "最終更新 ○分前" next to every live value.
- Reuse the existing theme tokens; one accent color; no decorative clutter.

## 7. What to bring over from `~/spovie` (bootstrap)

Bring over and adapt (rename everything to CourtQ; remove Spovie wording):
- Repo layout (`backend/`, `frontend/`, `docker/`, `scripts/`, `docs/`), `docker-compose.yml` (drop the FFmpeg-specific parts), `.gitignore`, `.gitattributes`, `.env.example` files without real secrets.
- `CLAUDE.md` (layering rules, service-first, Japanese Conventional Commits, Git workflow, testing rules) rewritten for CourtQ.
- Backend: auth (register/login/logout/me), `AuthService`, `ServiceException` + JSON error handler (401/403/404/422/429 in Japanese), CORS/TrustProxies/health endpoint, sqlite in-memory test setup, `docs:api` command, production `Dockerfile`, `railway.json` / `railway.worker.json`, start scripts.
- Frontend: Vite + React + TS strict setup, router guards, Axios client with Bearer token and 401 handling, auth store, Echo setup + connection badge, PWA config, offline banner, base styles (re-theme to the CourtQ colors), Playwright e2e setup with the fake Pusher server.
- CI: `.github/workflows/ci.yml` (backend test, frontend build, e2e).
- Reuse the team-invitation token logic as the base for staff invitation codes where it helps.

Start the git history with an initial commit like `chore: Spovieの基盤を流用してCourtQを初期化`, then work in feature branches.

## 8. Work packages (one branch + PR each, CI green before merge)

- **WP-B0**: Plan only. Read `~/spovie` and this prompt, list exactly which files you will copy/adapt and what you will create new, and wait for my OK.
- **WP-B1**: Bootstrap the new repo from the Spovie foundation (section 7), rename to CourtQ, apply the logo assets, CI green on an empty-feature app (auth + health only). Create `main` and `develop` branches.
- **WP-B2**: Migrations, models, factories, demo seeder (fictional venue, 2 games incl. one today, 8 booths, menus, 1 admin, 2 staff, 2 users; demo passwords documented in README).
- **WP-B3**: Read APIs (GAME, BOOTH, FAV) + caching + tests.
- **WP-B4**: Roles, `EnsureRole`, Policies, STAFF APIs, invitations, logs, throttling + tests (including "visitor calls write API directly → 403").
- **WP-B5**: User screens S01–S06.
- **WP-B6**: Staff screens S07–S10.
- **WP-B7**: Admin screens S11–S14 + CSV import.
- **WP-B8**: Realtime events + reconnect refetch + e2e with the fake Pusher server.
- **WP-B9**: PWA caching/freshness/offline banner; Lighthouse.
- **WP-B10**: Docs in `docs/` (requirements, ER diagram in Mermaid, permission table, API list regenerated by `php artisan docs:api`, screen list), README, and rewrite `docs/self-assessment.md` mapping each rubric line to evidence.

Priority if time runs short: B1–B6 + B8 first (that covers rubric level 3 and real-time), then B9, B7, B10. Never skip tests for authorization.

## 9. Acceptance criteria

- A visitor can open today's game, see every booth with congestion level, wait minutes and freshness, and open a booth to see menu and stock.
- A staff user updates congestion on a phone in 2 taps, and another browser sees it within a few seconds without reload.
- Visitor tokens get 403 on every write API (tested).
- Admin can create a game, assign booths, and issue an invitation code that turns a user into staff for that booth.
- CI green; deployed to **new** Railway (web + worker + MySQL) and Vercel projects connected to the `courtq` repo; smoke test updated and passing. Write the exact manual dashboard steps and environment variable table in `docs/deployment.md` (no real secrets).
  Lessons from the Spovie deployment to write into that document (they cost me hours):
  Railway web service Root Directory = `/backend`; target port must equal the `PORT` variable (e.g. 8080);
  DB variables must be Railway reference variables such as `${{MySQL.MYSQLHOST}}` / `${{MySQL.MYSQLPASSWORD}}` (an empty `DB_PASSWORD` crashes migrate in a restart loop);
  do not generate a public domain / TCP proxy for the MySQL service; do not click "Add" on Railway's auto-suggested variables (they are local dev values);
  `APP_URL` = Railway web URL, `APP_FRONTEND_URL` and `CORS_ALLOWED_ORIGINS` = the Vercel URL without a trailing slash (a wrong value causes a CORS error in the browser);
  Vercel Root Directory = `frontend`, `VITE_API_BASE_URL` = Railway URL + `/api`, and Vercel must be redeployed after changing any `VITE_*` variable;
  generate a fresh `APP_KEY` with `php artisan key:generate --show` and never paste secrets into chats or commits;
  make `/api/health` check the database so a broken DB config is visible immediately.

Start with WP-B0 and wait for my OK.