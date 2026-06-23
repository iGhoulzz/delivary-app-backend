# Dashboard Frontend — Slice 0 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Also use `anthropic-skills:frontend-components`** for clean, typed, accessible component code throughout.

**Goal:** Stand up the `delivary-dashboard` React+TS+Vite app so an admin can log in against the local backend and land on a themed, navigable shell — with auth guards, i18n/RTL, the design system, the API client, and a green end-to-end login smoke.

**Architecture:** Feature-folder SPA. `shared/` holds theme, ui primitives, i18n, the axios API client, and auth. `app/` wires router + providers. Server state via TanStack Query; auth via a bearer-token `AuthProvider`. No visual redesign — the design's tokens/skins/RTL are ported from `docs/design/dashboard/`.

**Tech Stack:** React 18, TypeScript, Vite, Tailwind, React Router v6, TanStack Query, axios, react-i18next, MapLibre GL (added in Slice 1), Vitest + React Testing Library + MSW, Playwright.

**Spec:** `docs/superpowers/specs/2026-06-23-dashboard-frontend-design.md` (in the backend repo). **Backend must be running** (`php artisan serve` → `http://localhost:8000`, Postgres container up) and seeded (`php artisan db:seed --class=Database\\Seeders\\DemoSeeder`) for the auth tasks + smoke. Admin login: `+218910001000` / `password`.

**Working directory:** `C:\Users\User\Desktop\delivary-dashboard` (the NEW repo — all paths below are relative to it). The backend stays at `C:\Users\User\Desktop\delivary-app`.

---

## File-structure map (Slice 0)

```
delivary-dashboard/
  .env.example                      # VITE_API_BASE_URL, VITE_MAP_* 
  index.html                        # fonts + #root
  vite.config.ts                    # vite + vitest config (jsdom)
  tailwind.config.ts  postcss.config.js
  tsconfig.json  .eslintrc.cjs  .prettierrc
  .github/workflows/ci.yml
  playwright.config.ts
  e2e/login-smoke.spec.ts           # REQUIRED smoke
  src/
    main.tsx                        # mount + providers
    app/
      router.tsx                    # routes + guards
      providers.tsx                 # QueryClient + I18next + Auth + Theme providers
    shared/
      theme/  theme.css  ThemeProvider.tsx  useTheme.ts
      i18n/   index.ts  en.ts  ar.ts  format.ts (num/date)  useDirection.ts
      api/    client.ts  auth.ts (endpoint module)  types.ts  errors.ts (422 mapping)
      auth/   AuthProvider.tsx  useAuth.ts  token.ts  guards.tsx (RequireAuth, RequirePasswordChanged)
      ui/     Icon.tsx  Card.tsx  Avatar.tsx  Button.tsx  Field.tsx  Spinner.tsx  StatusPill.tsx
      test/   setup.ts  msw/server.ts  msw/handlers.ts  renderWithProviders.tsx
    features/
      auth/        LoginPage.tsx  ChangePasswordPage.tsx  ForbiddenPage.tsx
      overview/    OverviewPage.tsx (placeholder shell content for Slice 0)
      shell/       AppLayout.tsx  Sidebar.tsx  TopBar.tsx  nav.ts
```

---

## Task 0.1 — Repo + Vite React-TS scaffold

**Files:** the whole scaffold (Vite generates them).

- [ ] **Step 1 — scaffold** (run in `C:\Users\User\Desktop`):
```bash
npm create vite@latest delivary-dashboard -- --template react-ts
cd delivary-dashboard
npm install
```
- [ ] **Step 2 — init git + first commit:**
```bash
git init -b main
git add -A
git commit -m "chore: vite react-ts scaffold"
```
- [ ] **Step 3 — wire the GitHub remote** (the user created the empty `iGhoulzz/delivary-dashboard`; `gh` is not installed, so add the remote by URL):
```bash
git remote add origin https://github.com/iGhoulzz/delivary-dashboard.git
git push -u origin main
```
- [ ] **Step 4 — verify** `npm run dev` serves on `http://localhost:5173`. Stop it. Commit nothing further here.

## Task 0.2 — Tooling: TanStack Query, Router, i18n, axios, Tailwind, test libs

- [ ] **Step 1 — install deps:**
```bash
npm install @tanstack/react-query react-router-dom axios i18next react-i18next i18next-browser-languagedetector
npm install -D tailwindcss postcss autoprefixer @types/node \
  vitest @testing-library/react @testing-library/user-event @testing-library/jest-dom jsdom \
  msw eslint prettier eslint-config-prettier eslint-plugin-react-hooks @playwright/test
npx tailwindcss init -p
```
- [ ] **Step 2 — `tailwind.config.ts`** (content globs + the design's font families):
```ts
import type { Config } from 'tailwindcss';
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['IBM Plex Sans', 'IBM Plex Sans Arabic', 'system-ui', 'sans-serif'],
        mono: ['IBM Plex Mono', 'ui-monospace', 'monospace'],
      },
    },
  },
  plugins: [],
} satisfies Config;
```
- [ ] **Step 3 — `vite.config.ts`** (add vitest + a `@` alias):
```ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': path.resolve(__dirname, 'src') } },
  test: { environment: 'jsdom', globals: true, setupFiles: ['./src/shared/test/setup.ts'] },
});
```
- [ ] **Step 4 — `tsconfig.json`** add the path alias under `compilerOptions`: `"baseUrl": ".", "paths": { "@/*": ["src/*"] }`.
- [ ] **Step 5 — add scripts to `package.json`:**
```json
"scripts": {
  "dev": "vite",
  "build": "tsc -b && vite build",
  "lint": "eslint . --max-warnings 0",
  "format:check": "prettier --check .",
  "typecheck": "tsc --noEmit",
  "test": "vitest run",
  "e2e": "playwright test"
}
```
- [ ] **Step 6 — `.prettierrc`** `{ "singleQuote": true, "semi": true, "printWidth": 100 }` and `.eslintrc.cjs` extending `eslint:recommended`, `plugin:react-hooks/recommended`, `prettier`.
- [ ] **Step 7 — commit** `chore: tooling (tailwind, router, query, i18n, axios, vitest, msw, playwright)`.

## Task 0.3 — Theme: tokens, skins, RTL, fonts (ported from the design)

**Files:** Create `index.html`, `src/shared/theme/theme.css`, `src/shared/theme/ThemeProvider.tsx`, `src/shared/theme/useTheme.ts`. Test: `src/shared/theme/ThemeProvider.test.tsx`.

- [ ] **Step 1 — `index.html`**: add the Google Fonts `<link>` from `docs/design/dashboard/dashboard.html` (IBM Plex Sans / Arabic / Mono, Baloo, Fredoka) and keep `<div id="root">`.
- [ ] **Step 2 — `theme.css`**: paste the design's `<style>` block from `dashboard.html` (CSS variables under `:root`, `[dir="rtl"]`, `[data-skin="playful"]`, `[data-theme="dark"]` token retints, scrollbars, keyframes) verbatim, then `@tailwind base; @tailwind components; @tailwind utilities;` at the top. Import it in `main.tsx`.
- [ ] **Step 3 — failing test** (`ThemeProvider.test.tsx`): asserts `<ThemeProvider>` sets `document.documentElement` attributes from state.
```tsx
it('applies theme/skin/lang/density to <html> and persists', () => {
  renderWithProviders(<button onClick={() => useTheme().setTheme('dark')}>x</button>);
  // toggling theme sets data-theme="dark"; lang 'ar' sets dir="rtl"
  // (full assertions written against the real ThemeProvider API below)
});
```
- [ ] **Step 4 — implement `ThemeProvider`**: React context holding `{ theme:'light'|'dark', skin:'default'|'playful', density:'comfortable'|'dense', lang:'en'|'ar' }`, persisted to `localStorage`. An effect writes to `document.documentElement`: `data-theme`, `data-skin`, `data-density`, `lang`, and `dir` (`ar`→`rtl`, else `ltr`). `useTheme()` exposes the values + setters. Keep `lang` here as the single source of truth and have i18n subscribe (Task 0.4).
- [ ] **Step 5 — run test → pass. Commit** `feat: theme provider with tokens/skins/RTL`.

## Task 0.4 — i18n: react-i18next + RTL + number/date formatting

**Files:** Create `src/shared/i18n/{index.ts,en.ts,ar.ts,format.ts,useDirection.ts}`. Test: `src/shared/i18n/format.test.ts`.

- [ ] **Step 1 — failing test** (`format.test.ts`):
```ts
import { num } from '@/shared/i18n/format';
it('formats numbers per language', () => {
  expect(num(1234, 'en')).toBe('1,234');
  expect(num(1234, 'ar')).toBe('١٬٢٣٤'); // ar-LY/ar digits
});
```
- [ ] **Step 2 — implement `format.ts`**: `num(n, lang)` via `new Intl.NumberFormat(lang === 'ar' ? 'ar-LY' : 'en-US').format(n)`; `date(d, lang)` via `Intl.DateTimeFormat`. (These replace the prototype's `num()`/date helpers.)
- [ ] **Step 3 — `en.ts` / `ar.ts`**: translation dictionaries. Seed with `nav.*` (overview/orders/drivers/users/merchants/finance/settlements/staff/settings), `common.*` (logout, collapse, search…), and `auth.*` (phone, password, signIn, changePassword, forbidden…). Keys mirror the design's `tt({ar,en})` strings — copy the EN/AR pairs from `shell.jsx` `NAV_SECTIONS`/`PAGE_TITLES`.
- [ ] **Step 4 — `index.ts`**: init i18next with `en`/`ar` resources, `lng` from the ThemeProvider lang (default `ar` to match the design's `dir="rtl"` default — confirm with product; spec says Arabic-primary). `useDirection()` returns `i18n.dir()`.
- [ ] **Step 5 — run test → pass. Commit** `feat: i18n (en/ar) + Intl number/date formatting`.

## Task 0.5 — Token storage + API client (axios) with 401/403 interceptors

**Files:** Create `src/shared/auth/token.ts`, `src/shared/api/{client.ts,errors.ts,types.ts}`. Test: `src/shared/api/client.test.ts`.

- [ ] **Step 1 — `token.ts`**: `getToken()/setToken()/clearToken()` backed by `localStorage` key `tawseel.token` + an in-memory cache. No React here (usable from the axios interceptor).
- [ ] **Step 2 — `errors.ts`**: `parseValidationErrors(error): Record<string,string>` — maps a Laravel 422 body `{ errors: { field: [msg] } }` to `{ field: firstMsg }`. `apiErrorCode(error): string | null` reads `error.response.data.error`.
- [ ] **Step 3 — failing test** (`client.test.ts`, MSW): a 401 response triggers `clearToken()` + an `auth:unauthorized` window event; a 403 with `{error:'password_change_required'}` dispatches `auth:password-change-required`.
```ts
it('clears token and emits on 401', async () => {
  setToken('t');
  server.use(http.get('*/admin/overview', () => HttpResponse.json({}, { status: 401 })));
  const spy = vi.fn(); window.addEventListener('auth:unauthorized', spy);
  await expect(api.get('/admin/overview')).rejects.toBeTruthy();
  expect(getToken()).toBeNull(); expect(spy).toHaveBeenCalled();
});
```
- [ ] **Step 4 — implement `client.ts`**: `axios.create({ baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api', headers: { Accept: 'application/json' } })`. **Request interceptor** attaches `Authorization: Bearer ${getToken()}` when present. **Response interceptor**: on `401` → `clearToken()` + `window.dispatchEvent(new Event('auth:unauthorized'))`; on `403` with code `password_change_required` → `window.dispatchEvent(new Event('auth:password-change-required'))`; rethrow.
- [ ] **Step 5 — run test → pass. Commit** `feat: axios client with bearer + 401/403 interceptors`.

## Task 0.6 — Auth endpoint module + AuthProvider

**Files:** Create `src/shared/api/auth.ts`, `src/shared/auth/{AuthProvider.tsx,useAuth.ts}`. Test: `src/shared/auth/AuthProvider.test.tsx`.

- [ ] **Step 1 — `api/auth.ts`** typed calls (paths WITHOUT `/api`, the baseURL has it):
```ts
export const login = (phone: string, password: string) =>
  api.post<{ token: string }>('/auth/login', { phone_number: phone, password }).then(r => r.data);
export const me = () => api.get<MeResponse>('/auth/me').then(r => r.data);
export const logout = () => api.post('/auth/logout');
export const changeFromTemp = (current: string, next: string) =>
  api.post('/me/password/change-from-temp', { current_password: current, password: next, password_confirmation: next });
```
> Verify the exact request field names against the backend FormRequests during TDD (the backend uses `phone_number`; confirm the login + change-from-temp field names). `MeResponse` type mirrors `/auth/me`: `{ user, roles: string[], must_change_password: boolean, is_driver, office_assignments, counts: { pending_orders, unread_notifications } }`.
- [ ] **Step 2 — failing test** (`AuthProvider.test.tsx`, MSW): mounting with a stored token calls `/auth/me` and exposes `user`+`roles`; `logout()` clears token + calls `/auth/logout`; the `auth:unauthorized` event clears state.
- [ ] **Step 3 — implement `AuthProvider`**: on mount, if `getToken()`, fetch `me()` (TanStack Query `useQuery(['me'])`); expose `{ user, roles, counts, mustChangePassword, isLoadingSession, login(phone,pwd), logout() }`. `login` sets the token then refetches `me`. Subscribe to `auth:unauthorized` (clear + redirect handled by guard) and `auth:password-change-required`.
- [ ] **Step 4 — run test → pass. Commit** `feat: auth provider (bearer, /auth/me bootstrap)`.

## Task 0.7 — Router + guards (RequireAuth, RequirePasswordChanged, Forbidden)

**Files:** Create `src/shared/auth/guards.tsx`, `src/app/router.tsx`, `src/features/auth/ForbiddenPage.tsx`. Test: `src/shared/auth/guards.test.tsx`.

- [ ] **Step 1 — failing tests** (`guards.test.tsx`): no token → `RequireAuth` redirects to `/login`; authed non-admin (`roles` lacks `admin`) → renders `ForbiddenPage`; `mustChangePassword` → `RequirePasswordChanged` redirects to `/change-password`.
- [ ] **Step 2 — implement `guards.tsx`**: `RequireAuth` — if no token → `<Navigate to="/login">`; while `isLoadingSession` → `<Spinner>`; if loaded and `roles` excludes `admin` → `<ForbiddenPage>`; else `<Outlet>`. `RequirePasswordChanged` — if `mustChangePassword` → `<Navigate to="/change-password">`; else `<Outlet>`.
- [ ] **Step 3 — implement `router.tsx`** (createBrowserRouter): public `/login`; `/change-password` (auth required, no admin gate); protected tree under `RequireAuth`→`RequirePasswordChanged`→`AppLayout` with children `/` (Overview) + placeholder routes for the nav ids (each a stub page for now). A top-level `auth:unauthorized` listener calls `router.navigate('/login')`.
- [ ] **Step 4 — run tests → pass. Commit** `feat: router + auth/admin/password guards`.

## Task 0.8 — Design-system primitives (shell + auth subset)

**Files:** Create `src/shared/ui/{Icon.tsx,Card.tsx,Avatar.tsx,Button.tsx,Field.tsx,Spinner.tsx,StatusPill.tsx}`. Test: `src/shared/ui/ui.test.tsx`.

- [ ] **Step 1 — `Icon.tsx`**: port the icon set from `docs/design/dashboard/app/icons.jsx` into a typed `Icon` (`name` union, `size`, `strokeWidth`). Only port the icons the shell + auth use (route, overview/orders/drivers/users/merchants/finance/settlements/staff/settings, globe, bell, shield, logout, chevronL/R, spinner). Remaining icons added in Slice 1.
- [ ] **Step 2 — `Card`, `Avatar`, `Button`, `Field` (label+input+error), `Spinner`, `StatusPill`**: port the design's markup/classes into typed, accessible components (semantic elements, `aria-*`, focus styles already in `theme.css`). Keep each file one component.
- [ ] **Step 3 — failing test** (`ui.test.tsx`): `Button` renders children + fires `onClick`; `Field` shows its error text with `role="alert"`; `Icon name="route"` renders an `<svg>`.
- [ ] **Step 4 — implement to green. Commit** `feat: design-system primitives (shell+auth subset)`.

## Task 0.9 — App shell: AppLayout + Sidebar + TopBar

**Files:** Create `src/features/shell/{AppLayout.tsx,Sidebar.tsx,TopBar.tsx,nav.ts}`. Test: `src/features/shell/shell.test.tsx`.

- [ ] **Step 1 — `nav.ts`**: the nav model ported from `shell.jsx` `NAV_SECTIONS` (sections Operations/Directory/Admin; items with `id`, i18n key, icon, optional `badge`), mapping each `id` to its route path.
- [ ] **Step 2 — `Sidebar.tsx`**: render `nav.ts` with `<NavLink>` (active styling from the design), collapse toggle (persisted), and the `orders` badge from `useAuth().counts.pending_orders`.
- [ ] **Step 3 — `TopBar.tsx`**: page title (from route), language toggle (`useTheme().setLang`), skin toggle, the user block (name + Admin chip + office from `useAuth().user`/`office_assignments`), and logout (`useAuth().logout()` → navigate `/login`).
- [ ] **Step 4 — `AppLayout.tsx`**: `Sidebar` + `TopBar` + `<Outlet>` in the design's flex layout; `app-bg` class for the playful texture.
- [ ] **Step 5 — failing test** (`shell.test.tsx`): renders all nav items; clicking the language toggle flips `<html dir>`; the orders badge shows the count from a mocked `useAuth`.
- [ ] **Step 6 — implement to green. Commit** `feat: app shell (sidebar, topbar, layout)`.

## Task 0.10 — Auth pages + Overview placeholder

**Files:** Create `src/features/auth/{LoginPage.tsx,ChangePasswordPage.tsx}`, `src/features/overview/OverviewPage.tsx`. Test: `src/features/auth/LoginPage.test.tsx`.

- [ ] **Step 1 — failing test** (`LoginPage.test.tsx`, MSW): submitting valid creds calls `/auth/login`, stores the token, and navigates to `/`; a 422 renders the field error under the input via `Field`.
- [ ] **Step 2 — `LoginPage.tsx`**: bilingual centered card; phone + password `Field`s; submit → `useAuth().login()`; on success navigate `/`; on 422 map errors via `parseValidationErrors`. Disable button while pending.
- [ ] **Step 3 — `ChangePasswordPage.tsx`**: current + new + confirm; calls `changeFromTemp()`; on success refetch `me` (clears `mustChangePassword`) and navigate `/`.
- [ ] **Step 4 — `OverviewPage.tsx`**: Slice-0 placeholder — a `Card` reading `t('overview.placeholder')` (real KPIs/map land in Slice 1). This is what the smoke asserts.
- [ ] **Step 5 — run test → pass. Commit** `feat: login + change-password pages + overview placeholder`.

## Task 0.11 — MSW test harness + providers test util

**Files:** Create `src/shared/test/{setup.ts,msw/server.ts,msw/handlers.ts,renderWithProviders.tsx}`.

- [ ] **Step 1 — `msw/handlers.ts`**: default handlers for `/auth/login` (returns `{token}`), `/auth/me` (admin `me` payload), `/auth/logout`, `/me/password/change-from-temp`. **Step 2 — `msw/server.ts`** sets up `setupServer(...handlers)`. **Step 3 — `setup.ts`** wires `@testing-library/jest-dom`, starts/stops/reset the MSW server around tests. **Step 4 — `renderWithProviders.tsx`** wraps UI in QueryClientProvider + I18nextProvider + ThemeProvider + MemoryRouter + AuthProvider.
- [ ] **Step 5 — `npm test` green across all suites. Commit** `test: msw harness + provider render util`.

> Note: earlier tasks reference `renderWithProviders` / `server`; if executing strictly top-down, create thin stubs of this harness in Task 0.3 and fill them here — or do Task 0.11 first. The reviewer should confirm test-util availability before each test task.

## Task 0.12 — Required Playwright login smoke (live backend)

**Files:** Create `playwright.config.ts`, `e2e/login-smoke.spec.ts`, `.env.example`.

- [ ] **Step 1 — `.env.example`**: `VITE_API_BASE_URL=http://localhost:8000/api` and `# VITE_MAP_TILE_URL_TEMPLATE=https://tile.openstreetmap.org/{z}/{x}/{y}.png`. Copy to `.env`.
- [ ] **Step 2 — `playwright.config.ts`**: `webServer` runs `npm run dev` (port 5173, reuseExistingServer), `baseURL: 'http://localhost:5173'`.
- [ ] **Step 3 — `e2e/login-smoke.spec.ts`** (REQUIRED): 
```ts
test('admin logs in and lands on overview', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel(/phone/i).fill('+218910001000');
  await page.getByLabel(/password/i).fill('password');
  await page.getByRole('button', { name: /sign in|دخول/i }).click();
  await expect(page).toHaveURL('http://localhost:5173/');
  await expect(page.getByRole('heading', { name: /overview|الرئيسية/i })).toBeVisible();
});
```
- [ ] **Step 4 — run the smoke** with the **backend running + seeded**:
```bash
# backend (separate terminal): cd ../delivary-app && php artisan serve   (+ DemoSeeder seeded, Postgres up)
npx playwright install chromium
npm run e2e
```
Expected: 1 passed. This proves CORS + token + `/api` base URL + the auth guards all work end-to-end.
- [ ] **Step 5 — commit** `test(e2e): required login smoke (login -> /auth/me -> overview)`.

## Task 0.13 — CI + README + final gate

**Files:** Create `.github/workflows/ci.yml`, `README.md`.

- [ ] **Step 1 — `ci.yml`**: on push/PR — `npm ci` → `npm run lint` → `npm run format:check` → `npm run typecheck` → `npm test` → `npm run build`. (The Playwright smoke needs a live backend, so it runs locally / in a later integration job, not this unit CI — note that in the workflow comment.)
- [ ] **Step 2 — `README.md`**: prerequisites (Node, the backend running + DemoSeeder), `cp .env.example .env`, `npm i`, `npm run dev`, the demo admin creds, and a link to the spec `delivary-app/docs/superpowers/specs/2026-06-23-dashboard-frontend-design.md`.
- [ ] **Step 3 — final gate (all green):**
```bash
npm run lint && npm run format:check && npm run typecheck && npm test && npm run build
```
- [ ] **Step 4 — commit + push** `chore: CI + README; Slice 0 foundation complete`.

---

## Verification (Slice 0 done)
- [ ] `npm run lint`, `format:check`, `typecheck`, `test`, `build` all green.
- [ ] **Required smoke green:** `npm run e2e` (backend up + seeded) — admin logs in → overview shell.
- [ ] Manual: language toggle flips LTR/RTL; dark + playful skins apply; non-admin login → Forbidden; a `must_change_password` admin is forced to `/change-password`.
- [ ] Pushed to `origin/main`; CI green.

## Self-review notes (spec coverage)
- Stack (React/TS/Vite/Tailwind/Query/Router/i18next/axios) → 0.1–0.2 ✓; theme/skins/RTL → 0.3 ✓; i18n + Intl → 0.4 ✓; API base `/api` + bearer + 401/403 → 0.5 ✓; auth bootstrap → 0.6 ✓; guards (auth/admin-only/password-change) → 0.7 ✓; design-system port → 0.8 ✓; shell → 0.9 ✓; login + change-password + overview placeholder → 0.10 ✓; required Playwright smoke → 0.12 ✓; CI + repo/remote → 0.1, 0.13 ✓. MapLibre is **Slice 1** (Overview map) — not Slice 0. Feature screens (orders/drivers/etc.) are later slices.
