# Internal Dashboard Frontend — Design (Tawseel Ops Console)

**Date:** 2026-06-23
**Status:** 📝 DRAFT — design under review.
**Repo:** new **separate** repo `delivary-dashboard` (sibling dir `C:\Users\User\Desktop\delivary-dashboard`) + new GitHub remote `iGhoulzz/delivary-dashboard`.
**Design source:** `docs/design/dashboard/` (the built React prototype "Tawseel — Ops Console": `dashboard.html` + `app/*.jsx`).
**Backend contract:** Dashboard Support A (§17.18) + Support B (§17.19) admin API — already merged.
**Process:** `docs/WORKFLOW.md`. Implementation runs under **`anthropic-skills:frontend-components`** (senior clean-code discipline) the way backend work ran under `laravel-backend`.

> The spec + plan live in **this** (backend) repo's `docs/superpowers/`, where the process lives; the
> new frontend repo references them from its README.

---

## 1. Why this milestone exists

The admin API the dashboard binds to is complete and stable (Support A+B, 370 backend tests). The UI is
fully designed as a React prototype (bilingual EN/AR, RTL, dark + "playful" skins). This milestone
**productionizes that prototype** into a real, typed, build-tooled SPA that talks to the live backend —
no visual redesign, just real framework + routing + data + auth.

## 2. Locked decisions

- **Framework:** React 18 + **TypeScript** + **Vite**. (The design is React; the earlier "Vue 3" note is
  superseded — porting JSX→TSX reuses the design directly.)
- **Auth:** **bearer token** (`POST /auth/login` returns a token; the API is token-first, shared with the
  mobile apps). Token in memory + `localStorage`; axios attaches `Authorization: Bearer`. **Admin-only
  SPA** — non-admins can authenticate but get a Forbidden screen, not the dashboard. Guard states
  (401 → login, 403 `password_change_required` → `/change-password`, role check) are detailed in §4.3.
  **No** Sanctum SPA-cookie / CSRF flow.
- **Libraries:** **TanStack Query** (all server state — caching/refetch/invalidation), **react-i18next**
  (EN/AR + RTL), **axios** (API client), **MapLibre GL JS** (ops map), **Tailwind** (the design's styling
  system), **Vitest + React Testing Library + MSW** (tests).
- **Map tiles are configurable, not hardcoded.** `VITE_MAP_STYLE_URL` (a MapLibre style JSON URL) **or**
  `VITE_MAP_TILE_URL_TEMPLATE` (a raster `{z}/{x}/{y}` template) selects the source. **Dev default = OSM
  raster** (`https://tile.openstreetmap.org/{z}/{x}/{y}.png`, no key). **OSM attribution is always
  rendered** (their tile policy requires it). Production can swap to MapTiler / self-hosted vector tiles
  by changing only the env var — no code change.
- **Routing:** **real URL routing** (React Router v6), replacing the prototype's in-page `page` state.
  List pages and detail drawers/modals are **routes** (`/orders` + `/orders/:publicId`, `/drivers` +
  `/drivers/:publicId`, `/users/:publicId`, `/merchants/:publicId`, `/staff/:staff`, …) → deep links,
  refresh-safe, browser back/forward, clean per-route data loading.
- **Map:** real geographic map (MapLibre + OSM) plotting **real** office + live-driver coordinates from
  `GET /admin/map/overview`. The stylized SVG map is dropped (can't plot true lat/lng).
- **No visual redesign.** The CSS-variable token system, **dark** + **playful** skins (`data-theme` /
  `data-skin`), **RTL** (Arabic-primary), IBM Plex (+ Arabic) fonts, density toggle, status pills — all
  preserved from the prototype.
- **API base URL (includes `/api`):** `VITE_API_BASE_URL` defaults to **`http://localhost:8000/api`**
  (or `http://delivary-app.test/api` for Herd). The axios instance uses this as `baseURL`, so endpoint
  modules call paths **without** the `/api` prefix — `/auth/login`, `/admin/overview`, etc. (Backend
  routes live under `/api/...`; the prefix belongs in the base URL, not the call sites.)
- **Local dev:** backend CORS already allows `localhost:5173` (Vite). Login uses the **DemoSeeder** admin
  (`+218910001000` / `password`).

## 3. Out of scope

- **Admin-created orders** — backend does not support it yet; the design's "New order" button stays
  inert / hidden. (Revisit when the backend adds it.)
- **Office-staff (non-admin) dashboard** — Support A/B are admin-only; office-context is a later backend
  cut. This SPA is **admin-only**.
- **Offline/PWA, SSR, micro-frontends** — YAGNI for an internal admin tool.

## 4. Architecture

### 4.1 Project structure (feature-folders; small, focused files)
```
src/
  app/            # router, providers (QueryClient, I18nextProvider, AuthProvider), root layout, error boundary
  shared/
    ui/           # ported design system: Card, Icon, Avatar, StatusPill, Badge, Table, Drawer, Modal,
                  #   Button, Field/Input/Select, Toast, RangeTabs, Sparkline, EmptyState, Skeleton…
    theme/        # tokens.css (CSS vars), skins (light/dark/playful), RTL, density; ThemeProvider
    i18n/         # i18next init, en.ts / ar.ts dictionaries, num()/date() (Intl, ar-LY ↔ en)
    api/          # axios instance + bearer interceptor + 401 handling; typed endpoint modules; shared DTO types
    auth/         # AuthProvider (token + /auth/me user), RequireAuth + RequirePasswordChanged guards, useAuth
  features/
    overview/ orders/ drivers/ users/ merchants/ finance/ settlements/ staff/ settings/
                  # each: routes (list + detail), components, hooks (useXQuery/useXMutation), feature-local types
```
Each **shared/ui** primitive is dumb + reusable (one responsibility, typed props, accessible). Each
**feature** owns its screens, detail drawers, forms, and its typed API hooks. Files stay focused — when
one grows large, split it (the prototype's giant `*.jsx` files are decomposed, not copied wholesale).

### 4.2 Data flow
- **Server state = TanStack Query only.** Each feature exposes `useXyzQuery`/`useXyzMutation` hooks
  wrapping the typed `shared/api` modules; query keys encode filters; mutations invalidate the right keys
  (e.g. an order action invalidates the orders list + that order's detail, and the nav badge counts).
- **Client/UI state** (theme, skin, density, lang, sidebar-collapsed) = small React contexts persisted to
  `localStorage`. **Auth state** = `AuthProvider` (token + `me`).
- **No prop-drilling of server data** — components read via hooks.

### 4.3 Auth & guards
`AuthProvider` bootstraps from a stored token by calling `GET /auth/me`. `useAuth()` exposes `user`,
`roles`, `counts` (nav badges), `login`, `logout`. Explicit guard behaviour:

- **`401` (any request)** → clear the stored token + redirect to `/login` (axios interceptor; covers
  expired/invalid token).
- **`403` with body `{ error: 'password_change_required' }`** → redirect to `/change-password`
  (the backend `EnsurePasswordChanged` middleware returns exactly this). The same gate fires proactively
  when `/auth/me` reports `must_change_password: true`, so the user is never stuck behind a 403 loop;
  only `/me/password/change-from-temp` + `/auth/logout` are reachable until they change it.
- **Admin-only SPA.** Login can succeed for non-admin users (the token endpoint is shared), but this
  dashboard is admin-only. After `/auth/me`, if `roles` does **not** include `admin`, render a
  **Forbidden** screen with a logout action — do **not** mount the dashboard. `RequireAuth` enforces
  token + admin-role; `RequirePasswordChanged` enforces the change-password gate.

### 4.4 API layer & error handling
Typed endpoint modules per domain map to the Support A/B surface (§5). Axios response interceptor:
**401 → force logout**; Laravel **422** → a normalized `{ field: message }` map surfaced on forms;
domain error codes → toast. A root **error boundary** + per-route query error/empty/loading states
(Skeleton / EmptyState).

## 5. Screen → endpoint contract

| Screen (route) | Primary endpoints |
|---|---|
| Login / Change-password | `POST /auth/login`, `GET /auth/me`, `POST /me/password/change-from-temp`, `POST /auth/logout` |
| Overview (`/`) | `GET /admin/overview`, `GET /admin/map/overview` |
| Orders (`/orders`, `/orders/:publicId`) | `GET /admin/orders` (+search/driver/merchant filters), `GET /admin/orders/{id}`, `POST …/assign,unassign,cancel,mark-delivery-failed,redirect-return,waive-retrieval-fees` |
| Drivers (`/drivers`, `/drivers/:publicId`) | `GET /admin/drivers` (+activity_status), `GET /admin/drivers/{id}`, `…/account`, `…/account/adjust`, `…/strikes` (GET/POST), `…/strikes/{}/void`, `…/{approve,reject,suspend,reinstate}`, onboarding (`lookup,onboard,verify-phone,documents,submit`) |
| Users (`/users`, `/users/:publicId`) | `GET /admin/users`, `GET /admin/users/{user}`, `…/{suspend,ban,reinstate}`, `…/moderation-history`, **`PATCH …/notification-preferences`** |
| Merchants (`/merchants`, `/merchants/:publicId`) | `GET /admin/merchants` (+lookup), `GET/{merchant}`, `POST` (create), `PATCH` (update), `…/{suspend,reactivate,ban}` |
| Finance (`/finance`) | `GET /admin/finance/report?range=&office_id=` |
| Settlements (`/settlements`, `/settlements/:publicId`) | `GET /admin/settlements` (+`{id}`), `POST …/reverse`, `GET /admin/seller-payouts` |
| Staff (`/staff`, `/staff/:staff`) | `GET /admin/staff` (+`{staff}`), `POST`, `PATCH`, `…/{suspend,reinstate,deactivate,reset-temp-password}`, office-assignments (POST/DELETE), `GET …/activity` |
| Settings (`/settings`) | `GET /admin/settings`, `PATCH /admin/settings` |
| Shared (dropdowns/enums) | `GET /admin/reference` (offices, regions, enum catalogs) |

All ids in URLs/payloads are **`public_id`** (the backend never exposes internal ids), with **one
documented exception**: `/admin/reference` returns **`regions[]` (and `service_areas`) with numeric
`id`** — these are stable, non-sensitive reference data and deliberately have no `public_id` (backend
Critical Rule 11 exception). So region/service-area selectors send the numeric `id`; **everything else
route-facing uses `public_id`.** Bilingual display is **frontend-owned**, keyed off enum **values** from
`/admin/reference` (backend sends values + an English convenience label only).

## 6. Slicing (foundation first, then nav sections)

| Slice | Scope | Done when |
|---|---|---|
| **0 — Foundation** | Vite+TS+Tailwind scaffold; theme/skins/RTL; design-system primitives; app shell (sidebar/topbar/nav + badges); router; i18n (en/ar); axios API client + **auth** (login, admin-only guard, must-change-password, 401, logout); **required Playwright smoke** | You log in against the local backend, see the empty themed shell with working nav + language/skin toggles, and the **login → `/auth/me` → overview smoke is green** |
| **1 — Operations** | Overview (KPIs + real MapLibre map + activity feed); Orders (list+filters, detail drawer, admin actions); Drivers (roster, detail, account/strikes, onboarding form) | Operations section is fully data-bound |
| **2 — Directory** | Users (directory, detail, moderation, **notification-pref editing**); Merchants (roster, detail, create/edit form, lifecycle) | Directory section fully data-bound |
| **3 — Admin** | Finance report; Settlements (read + reverse); Staff (CRUD + activity tab); Settings (platform-settings editor). **Split if heavy → 3A: Finance+Settlements (financially sensitive), 3B: Staff+Settings (admin-management).** | Admin section fully data-bound |

Each slice gets its **own plan** when reached; we plan + build **Slice 0 first**. Within a slice, each
screen is a reviewable unit (list, then detail, then actions).

## 7. Testing & quality

- **Vitest + React Testing Library + MSW** — component/hook tests run against mocked endpoints (no live
  backend needed). Cover: auth flow (login, 401 redirect, must-change gate), a representative list+detail+
  mutation per feature, i18n/RTL rendering, and form 422-mapping.
- **Required Playwright smoke (Slice 0):** **login → `/auth/me` → overview shell loads** against the
  **live local backend**. This is a real end-to-end check — it catches CORS, token handling, the
  `VITE_API_BASE_URL` (`/api`) wiring, and the auth guards early, before any feature work. Kept green
  thereafter.
- **CI** (GitHub Actions): install → **ESLint + Prettier check** → **`tsc --noEmit`** → Vitest → `vite build`.
- **Clean-code discipline** via `anthropic-skills:frontend-components`: typed props, accessible
  semantics/ARIA, reusable primitives, no dead prototype code, focused files.

## 8. Repo & CI setup (Slice 0, first tasks)

`git init` in `delivary-dashboard`, push to the new GitHub remote (user creates the empty repo; `gh` is
not installed locally, so the remote is wired + pushed manually). `.env.example` with
`VITE_API_BASE_URL`. README points to this spec + the backend's API. GitHub Actions workflow as in §7.

## 9. Open questions

- None blocking. (Map tiles are env-configurable — §2 — so a production source swap needs no code change.)
