# 05 — Frontend Structure & UX Plan (Next.js 16)

**Doc version:** 1.0 · Stack: Next.js 16.3 (App Router) · React 19 · TypeScript · Tailwind 4 · shadcn/ui · TanStack Query 5 · Zustand 5 · react-hook-form + Zod

---

## 1. Page & route structure

```
app/
├── (marketing)/                      # public, static/ISR, no auth
│   ├── page.tsx                      # /            landing: problem → solution → CTA
│   ├── features/page.tsx             # /features
│   ├── pricing/page.tsx              # /pricing      (Free + Pro; Pro flagged until Phase 6)
│   ├── about/page.tsx                # /about
│   ├── contact/page.tsx              # /contact
│   ├── privacy/page.tsx              # /privacy
│   └── terms/page.tsx                # /terms
├── (auth)/                           # centered layout; redirects away if already authenticated
│   ├── layout.tsx
│   ├── login/page.tsx                # /login
│   ├── register/page.tsx             # /register
│   ├── forgot-password/page.tsx      # /forgot-password
│   └── reset-password/page.tsx       # /reset-password?token=…&email=…
├── (onboarding)/                     # post-signup activation (3 steps)
│   ├── layout.tsx                    # wizard shell with progress
│   ├── page.tsx                      # step 1: confirm timezone
│   ├── first-job/page.tsx            # step 2: capture the first job (paste text)
│   └── done/page.tsx                 # step 3: reminder preferences
├── (dashboard)/                      # authenticated app shell
│   ├── layout.tsx                    # server-side session guard + AppShell (sidebar / topbar / bottom nav)
│   ├── dashboard/page.tsx            # /dashboard      overview
│   ├── jobs/
│   │   ├── page.tsx                  # /jobs           list view (filters live in the URL)
│   │   ├── board/page.tsx            # /jobs/board     kanban by status
│   │   ├── new/page.tsx              # /jobs/new       capture flow (4 tabs + manual entry)
│   │   ├── [id]/page.tsx             # /jobs/{id}      job card detail (tabbed)
│   │   └── [id]/edit/page.tsx        # /jobs/{id}/edit full edit form
│   ├── reminders/page.tsx            # /reminders      upcoming list + calendar
│   ├── companies/
│   │   ├── page.tsx                  # /companies
│   │   └── [id]/page.tsx             # /companies/{id}
│   ├── analytics/page.tsx            # /analytics      (Phase 3/5: funnel + trends)
│   ├── career/                       # Phase 5 — nav item hidden until enabled
│   │   ├── page.tsx                  # /career         CV list + primary CV
│   │   ├── cvs/[id]/page.tsx         # /career/cvs/{id} parsed profile + analyses
│   │   └── roadmap/page.tsx          # /career/roadmap
│   └── settings/
│       ├── page.tsx                  # /settings                profile
│       ├── notifications/page.tsx    # /settings/notifications
│       ├── usage/page.tsx            # /settings/usage          AI quota + cost
│       ├── security/page.tsx         # /settings/security       password, sessions, tokens
│       └── danger/page.tsx           # /settings/danger         export, delete account
├── api/                              # empty in v1 (the API is Laravel); reserved for webhooks/BFF
├── layout.tsx                        # root: fonts, providers, theme
├── globals.css                       # Tailwind 4 @theme tokens + shadcn variables
├── error.tsx / global-error.tsx      # error boundaries
├── not-found.tsx
└── proxy.ts                          # optional: cookie-presence redirect only
```

**Route rules**

1. Marketing and auth pages never import dashboard components (keeps the public bundle small).
2. `(dashboard)/layout.tsx` performs the **only** real auth check (server-side) and redirects to `/login?next=…`; `proxy.ts` is a cheap cookie-presence optimization, never the source of truth.
3. Every dashboard route ships `loading.tsx` (skeleton, matching final layout) and `error.tsx` (recoverable boundary with a retry), so there are no blank or frozen screens.
4. Detail routes that can be shared are addressed by id; nothing user-sensitive is put in the URL except filters.

### 1.1 Job detail tabs (the busiest screen)

`Overview` (extracted fields + salary + deadline countdown) · `Requirements` (skills split into required/preferred, responsibilities, benefits) · `Notes` · `Timeline` (status history + notes + reminders merged) · `Attachments` · `Reminders` · `Match` (Phase 5, hidden until enabled).

### 1.2 Capture flow (highest-value UX)

`/jobs/new` presents tabs: **Paste text** (default, fastest) · **Upload PDF** · **Upload screenshot** · **From URL** · **Manual entry**. After submit, the same route shows a live progress panel (queued → processing → done) and then the **review form**: extracted fields pre-filled, low-confidence fields badged ("check this date"), missing-fields callout, duplicate warning with a link to the existing card, and primary actions **Save job** / **Discard**. Nothing is saved until the user approves.

---

## 2. Folder structure & conventions

This tree is the root of the **`jobflow-web`** repository (a separate repo from `jobflow-api`; see `docs/README.md` → Repository layout). Nothing here is validated against the backend at build time except through the generated OpenAPI types.

```
src/
├── app/                       # routes (see §1)
├── components/
│   ├── ui/                    # shadcn primitives only (generated; do not hand-edit)
│   ├── layout/                # app-shell, sidebar-nav, topbar, mobile-bottom-nav, page-header, breadcrumbs
│   ├── dashboard/             # stat-card, status-funnel, upcoming-deadlines, activity-chart, quota-meter
│   ├── jobs/                  # job-card, job-list, job-board, board-column, job-filters, job-form,
│   │                          # status-badge, status-select, deadline-badge, skill-chip, salary-display
│   ├── capture/               # capture-tabs, paste-text-form, file-dropzone, url-input, capture-progress,
│   │                          # extraction-review-form, confidence-field, duplicate-warning
│   ├── reminders/             # reminder-list, reminder-form, reminder-item, calendar-view
│   ├── career/                # cv-upload, cv-profile-card, match-score-ring, missing-skills-list,
│   │                          # cover-letter-editor, interview-prep-panel
│   ├── settings/              # profile-form, notification-prefs-form, session-list, token-list, usage-table
│   └── shared/                # empty-state, error-state, confirm-dialog, search-input, tag-picker,
│                              # company-select, skill-picker, copy-button, ai-disclaimer
├── lib/
│   ├── api/
│   │   ├── client.ts          # typed fetch wrapper (credentials, error normalisation, request id)
│   │   ├── server.ts          # server-side apiFetch (forwards cookies)
│   │   ├── jobs.ts            # one module per resource: list/get/create/update/status/timeline/bulk
│   │   └── captures.ts · reminders.ts · notes.ts · tags.ts · companies.ts · skills.ts ·
│   │       notifications.ts · profile.ts · dashboard.ts · cvs.ts · matches.ts · cover-letters.ts
│   ├── hooks/                 # use-jobs, use-job, use-job-mutations, use-capture, use-extraction-status,
│   │                          # use-reminders, use-dashboard, use-notifications, use-debounce, use-media-query
│   ├── schemas/               # zod schemas mirroring API contracts (forms + runtime guards)
│   ├── utils/                 # date.ts (countdown, local/UTC), salary.ts, skills.ts, cn.ts, format.ts,
│   │                          # url.ts (safe external links), errors.ts (API error → user message)
│   ├── constants/             # statuses.ts, employment-types.ts, work-modes.ts, source-types.ts,
│   │                          # nav.ts, query-keys.ts   (enum mirrors of the backend)
│   └── config/                # env.ts (validated env), app.ts (name, links, feature flags)
├── providers/                 # query-provider.tsx, theme-provider.tsx, toast-provider.tsx, socket-provider.tsx
├── stores/                    # ui-store.ts, capture-store.ts, board-store.ts
├── types/                     # api.d.ts (GENERATED from OpenAPI — never hand-edited), domain.ts
└── proxy.ts                   # (optional) cookie-based redirect
```

**Naming conventions**

| Thing | Convention | Example |
|---|---|---|
| Files / folders | kebab-case | `job-card.tsx`, `use-jobs.ts` |
| Components | PascalCase named export | `export function JobCard()` |
| Hooks | `use-*.ts` in `lib/hooks`, export `useXxx` | `useJobsInfinite` |
| API modules | one file per resource, verb-named functions | `listJobs`, `updateJobStatus` |
| Query keys | single central factory | `queryKeys.jobs.list(filters)` |
| Zod schemas | `*Schema` | `jobFormSchema` |
| Zustand stores | `use*Store` | `useUiStore` |

Rules: no default exports except route files (`page.tsx`, `layout.tsx`); no barrel `index.ts` re-export chains (slower + circular-import prone); components stay under ~250 lines — split rather than nest; all user-facing strings live inline (single language, `en`) with a documented plan for `next-intl` if Bangla UI is added later.

---

## 3. State & data strategy

| Concern | Mechanism | Notes |
|---|---|---|
| Initial page data | Server Component `apiFetch` | Real data on first paint, no loading flash |
| Client cache | TanStack Query | `staleTime` 30 s for lists, 5 min for skills/companies; `gcTime` default |
| Lists | `useInfiniteQuery` + cursor | Infinite scroll on mobile, pagination controls on desktop |
| Mutations | `useMutation` + `invalidateQueries` / `setQueryData` | Optimistic status change with rollback on error |
| Extraction progress | `refetchInterval: 2000` until terminal, then WebSocket subscription | Polling always works, socket is an enhancement |
| Filters | URL search params | Shareable + back-button correct; parsed by one `parseJobFilters()` helper |
| Forms | react-hook-form + Zod (shadcn `Form`) | Same Zod schema reused for client validation and typed payloads |
| Global UI | Zustand (`useUiStore`: sidebar, command palette; `useCaptureStore`: in-progress draft; `useBoardStore`: drag state) | Never stores server data |
| Session | Server-read on layout + `useQuery(['me'])` for client islands | Logout clears the Query cache |

**Query-key factory (excerpt):**

```ts
export const queryKeys = {
  me: ['me'] as const,
  jobs: {
    all: ['jobs'] as const,
    list: (f: JobFilters) => ['jobs', 'list', f] as const,
    detail: (id: number) => ['jobs', 'detail', id] as const,
    stats: ['jobs', 'stats'] as const,
    timeline: (id: number) => ['jobs', id, 'timeline'] as const,
  },
  captures: { detail: (id: number) => ['captures', id] as const, list: ['captures'] as const },
  reminders: { upcoming: ['reminders', 'upcoming'] as const, calendar: (m: string) => ['reminders', 'calendar', m] as const },
  dashboard: ['dashboard'] as const,
} as const;
```

**Invalidation map (must be explicit, not guesswork):** status change → `jobs.all`, `jobs.detail(id)`, `jobs.stats`, `dashboard`, `reminders.upcoming`; job create/approve → the same set plus `captures.list`; note/reminder/tag writes → the job detail + its timeline only.

---

## 4. API client & error handling

```ts
// lib/api/client.ts (shape, not final code)
const API_URL = env.NEXT_PUBLIC_API_URL;              // https://api.jobflow.ai

export class ApiError extends Error {
  constructor(readonly status: number, readonly code: string,
              message: string, readonly errors?: Record<string, string[]>) { super(message); }
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(`${API_URL}${path}`, {
    ...init,
    credentials: 'include',                            // Sanctum SPA cookie
    headers: { Accept: 'application/json', 'Content-Type': 'application/json',
               'X-Requested-With': 'XMLHttpRequest', ...init.headers },
  });
  if (!res.ok) throw await toApiError(res);             // code + field errors preserved
  return res.json() as Promise<T>;
}
```

Rules:

1. **One** place knows the base URL, credentials mode and CSRF handling (`GET /sanctum/csrf-cookie` is called once before the first mutating request in a session).
2. `ApiError` carries `code`, so components branch on codes (`quota_exceeded` → upgrade CTA, `validation_failed` → field errors on the form, `unauthenticated` → redirect to `/login`).
3. 422 responses are mapped into react-hook-form via `setError(field, …)` — never shown as a generic toast.
4. Toasts are reserved for mutation outcomes ("Status updated", "Reminder created") and for unexpected failures; validation problems stay next to the field.
5. `errors.ts` owns the code → message map so wording changes happen in one place.
6. Timeouts: 20 s for normal calls, 60 s for uploads (progressive indicator after 3 s).

---

## 5. Design system & mobile-first rules

- **Tokens:** Tailwind 4 `@theme inline` in `globals.css` defines the palette, radius, spacing scale and typography, consumed by shadcn variables — one source for both light and dark mode.
- **shadcn usage:** `npx shadcn@latest add button card dialog sheet form select badge table tabs dropdown-menu command toast skeleton` — components are added deliberately, not wholesale; `components/ui` stays untouched so future `shadcn add --overwrite` upgrades are safe.
- **Base components we standardise on (never re-implement per page):** `PageHeader`, `EmptyState`, `ErrorState`, `ConfirmDialog`, `StatusBadge`, `DeadlineBadge`, `JobCard`, `FilterBar`, `SearchInput`, `Skeleton` variants, `AiDisclaimer`.
- **Layout:** sidebar (≥1024px) → collapses to a top bar + bottom tab bar on mobile with 4 items: Dashboard · Jobs · Capture (centre action) · Reminders. Bottom nav items use ≥44px touch targets.
- **Breakpoints:** design at 360px first, verify 390 / 768 / 1280 / 1536. No horizontal scrolling anywhere; tables become cards under `md`.
- **Colour semantics (used consistently):** saved = slate, preparing = amber, applied = blue, interview = violet, offer = emerald, rejected = rose; overdue deadline = red text + icon (not colour alone, for accessibility).
- **Density:** job cards show title, company, status, deadline countdown — nothing else; details are one tap away.
- **Motion:** 150 ms transitions; skeletons instead of spinners for content; optimistic board moves animate only after the server confirms.

### 5.1 Accessibility baseline

Keyboard-reachable drag-and-drop (board offers a "Move to…" menu as an alternative), visible focus rings, `aria-live` region for extraction progress and toasts, labelled form fields with inline error text, 4.5:1 contrast in both themes, and a Playwright axe check on the six most important routes.

---

## 6. Key UX flows (screen by screen)

**A. First-run activation.** `/register` → `/onboarding` (timezone confirmed, not asked twice) → paste the first job → extraction review → **Save job** → success screen shows the deadline countdown and the created reminder, with a single CTA to the dashboard. Target: under 5 minutes, no empty-state dead end.

**B. Capture (repeat use).** Jobs list → centre "+" → paste → progress → review → save → the new card animates into the list. The capture form keeps the last used tab as the default so repeat users are one tap from pasting.

**C. Daily check-in.** `/dashboard` answers three questions in one glance: *what's closing soon*, *what needs action today*, *how is my pipeline doing*. The next deadline is the largest element on the page.

**D. Status pipeline.** Board view with drag (or "Move to…" on mobile); each move writes history and shows an undo toast for 5 seconds.

**E. Deadline rescue.** When a deadline is within 7 days, the job card shows a countdown chip; dashboard groups these into "Closing this week" with a direct "Apply now" external link (opens in a new tab with `rel="noopener noreferrer"`).

**F. Quota and AI honesty.** Every AI surface states what it will do before it runs ("This uses 1 of your 30 monthly extractions"), shows the actual provider-agnostic result, and labels generated content with an `AiDisclaimer` ("AI-generated — review before using").

**G. Failure honesty.** Failed extraction shows the reason, the raw input, and two buttons: **Retry** and **Enter manually**. Failed URL fetch offers **Paste the text instead** with whatever was scraped pre-filled.

---

## 7. Forms & validation

| Form | Schema highlights | Behaviour |
|---|---|---|
| Register | name 2–120, email, password ≥ 8 + confirmation, timezone from a searchable list | Inline errors, submit disabled until valid, no auto-login surprise |
| Login | email + password | Generic error message; rate-limit message shows when 429 |
| Capture (paste) | text 1–20,000 chars | Live char counter, warn over 15k, paste button focuses the textarea on mobile |
| Capture review | every extracted field editable; `deadline_at` shows local time with the user's timezone; skills editable as chips | Low-confidence fields badged; missing fields highlighted; save disabled while a required field is empty |
| Job create/edit | title required; salary min ≤ max; currency 3-letter; deadline in the future (edit may allow past with a warning); apply URL must be http(s) | Numeric money as string-backed inputs (no float rounding bugs) |
| Reminder | remind_at in the future; title required; channels ≥ 1 | Quick presets (tomorrow 09:00, in 3 days, next Monday) |
| Notification prefs | offsets subset of [7,3,1,0], digest hour 0–23 | Shows exactly when the next reminder will be sent ("next: 1 Oct, 9:00 AM Dhaka") |
| Settings/profile | timezone, name, avatar | Timezone change warns that future reminders will be rescheduled |
| Delete account | password + typed confirmation | Requires re-auth; explains exactly what is deleted |

Rules: money never parsed as float; dates handled with explicit timezone helpers (`date.ts`) — no ad-hoc `new Date(string)` in components; every destructive action requires an explicit confirm dialog naming the affected item.

---

## 8. Environment, config and feature flags

```bash
# jobflow-web/.env.local
NEXT_PUBLIC_API_URL=http://localhost:8000        # https://api.jobflow.ai in prod
NEXT_PUBLIC_APP_URL=http://localhost:3000
NEXT_PUBLIC_REVERB_KEY=                          # empty disables websockets (polling fallback)
NEXT_PUBLIC_FEATURES=job_board,capture_pdf,capture_image,capture_url   # explicit allow-list
# Phase 5: NEXT_PUBLIC_FEATURES=…,career_assistant
```

`lib/config/app.ts` exports a typed `features` object parsed from `NEXT_PUBLIC_FEATURES`; navigation and routes read flags, so a half-built surface is **absent** from the UI rather than fake. `env.ts` validates the env with Zod at startup and fails the build if a required value is missing.

---

## 9. Performance & quality gates

| Gate | Tool / budget |
|---|---|
| Types | `tsc --noEmit` — zero errors; generated API types only (`types/api.d.ts`) |
| Lint | ESLint (next/core-web-vitals + TS rules) + Prettier; no `any` in `lib/` |
| Build | `next build` must pass with Turbopack; CI fails on warnings that indicate real issues |
| Bundle | Route-level code splitting by default; the marketing bundle must stay small (no chart libs imported there); charts are lazy-loaded in `/analytics` |
| Server Components first | Client components only where interactivity/data-fetch-on-client is required (`'use client'` justified in review) |
| Images | `next/image` with explicit dimensions for logos/avatars; no layout shift |
| Lists | Cursor pagination + virtualisation only if a list exceeds ~200 rendered rows (measure first, don't pre-optimise) |
| Lighthouse | ≥ 90 performance and ≥ 95 accessibility on `/`, `/login`, `/dashboard` (mobile profile) |
| E2E | Playwright smoke suite green on staging before every release |

---

## 10. Frontend definition of done

A screen is done when:

1. It renders real API data (no hardcoded arrays, no `mockData` imports outside tests).
2. It has loading (skeleton), empty (guidance + primary CTA), and error (retry) states.
3. It is usable at 360px width, keyboard-only, and in both light and dark themes.
4. Mutations give immediate feedback, handle failure visibly, and keep the cache consistent (explicit invalidation).
5. Its API contract comes from generated types; a backend field rename breaks the build.
6. It has at least one component test (logic) or is covered by an E2E flow (interaction).
7. AI-generated content on it is labelled and never auto-applied.



