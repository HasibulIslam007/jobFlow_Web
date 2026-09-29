# 06 — Development Roadmap & Execution Order

**Doc version:** 1.0 · Estimates: 1 experienced full-stack dev, full-time (×1.6 for part-time 20 h/week)

---

## 0. Phase overview

| Phase | Name | Focus | Estimate | Exit criteria (demonstrable) |
|---|---|---|---|---|
| **0** | Project foundation | Environment, repos, CI, standards, contracts | 3–4 days | Both apps run locally, CI green, staging deploys automatically |
| **1** | Auth & shell | Accounts, Sanctum, API envelope, app shell | 1 week | Register → login → dashboard → logout end-to-end on staging |
| **2** | Job core (System 2) | Jobs CRUD, statuses, notes, tags, skills, reminders, attachments, search | 1.5–2 weeks | A user can manually create, organize, search and track jobs; reminders scheduled |
| **3** | Capture + AI (System 1) | Text → URL → PDF/image extraction, review flow, quota, realtime | 1.5–2 weeks | Paste a real job post → approve a correct job card, live on staging |
| **4** | Notifications & polish | Reminder dispatcher, digests, in-app centre, dashboard analytics, export | 1 week | Reminder email arrives at the right local time; digest + CSV export work |
| **4.5** | MVP hardening | Security review, load test, backups, a11y pass, bug sweep | 4–5 days | All release criteria in `01-prd.md` §10 pass |
| **5** | AI Career Assistant (System 3) | CV parsing, match score, gaps, cover letters, interview prep, semantic search | 2–2.5 weeks | CV uploaded → per-job match with missing skills, reproducible score |
| **6** | Scale & monetize (optional) | Billing (Cashier), plan gating, mobile token API, share links, push, i18n | 2+ weeks | Only after real users and a pricing decision |

**MVP = Phases 0–4.5 ≈ 6–7 weeks full-time (≈ 10–11 weeks part-time).** Full designed product ≈ 9–10 weeks full-time.

---

## 1. Phase 0 — Project foundation (3–4 days)

**Goal:** remove every environment and deployment surprise before writing product code.

| # | Task | Done when |
|---|---|---|
| 0.1 | Decide the blocking items in `README.md` (runtime, domains, auth transport, providers, quotas) | Decisions recorded in `docs/` |
| 0.2 | Install the PHP runtime (Herd recommended) + Composer; confirm PHP ≥ 8.3 | `php artisan --version` works |
| 0.3 | Create the `jobflow-api` repository layout: Laravel 13 app at the repo root (`laravel new .` on a fresh branch), `docs/` preserved, `.env`, `APP_KEY`, PostgreSQL connection | `php artisan migrate` succeeds on the default schema; `docs/` intact |
| 0.4 | Run Redis (Docker) and install pgvector (`brew install pgvector`), then `CREATE EXTENSION vector` | `SELECT '[1]'::vector;` works |
| 0.5 | Configure Sanctum (`install:api`), Horizon, Reverb, `laravel/ai`, Pest, Pint, Larastan, Scramble | `php artisan about` lists them; `/up` responds |
| 0.6 | Create the R2 bucket + API token; configure the `r2` disk in `config/filesystems.php`; set bucket CORS | A test upload + signed URL round-trip works |
| 0.7 | Create the separate `jobflow-web` repository with Next 16 (TS, Tailwind, App Router, `src/`), init shadcn CLI, add baseline components, and a README linking back to `docs/` | `npm run dev` and `next build` pass |
| 0.8 | Providers (TanStack Query, theme, toaster) + base layout + design tokens in `globals.css` | A placeholder shell renders in light and dark |
| 0.9 | Tooling: ESLint + Prettier + `tsc --noEmit` (web), Pint + Larastan (api), Conventional Commits, branch strategy (`main`, `develop`, `feat/*`, `fix/*`), PR template in both repos | CI runs all checks in both repos |
| 0.10 | CI: `jobflow-api` → `.github/workflows/api.yml` (Pint, Larastan, Pest with Postgres+Redis services, Scramble → `openapi.json` artifact); `jobflow-web` → `.github/workflows/web.yml` (lint, typecheck, build, fetch `openapi.json` + `openapi-typescript`) | Both workflows green on a sample PR in each repo |
| 0.11 | Staging: deploy `jobflow-api` (VPS/Forge → `api-staging.jobflow.ai`) and `jobflow-web` (Vercel preview → staging alias) with env vars; write `docs/deployment.md` | Staging URL loads, `/health` green, cross-origin cookie auth works |
| 0.12 | Observability skeleton: request-id middleware, error envelope, `/api/v1/health`, Horizon access | A forced 500 returns the standard error envelope with a request id |

**Exit demo:** a PR in each repo → both CIs green → both staging apps deploy → health green → the web app can call the API with a cookie session.

## 2. Phase 1 — Auth & shell (1 week)

| # | Task | Layer |
|---|---|---|
| 1.1 | Migrations: `users` + `notification_preferences`; User model, enum casts, factory states | API |
| 1.2 | Auth endpoints: register, login, logout, me, forgot/reset password, verify email, change password, sessions, API tokens | API |
| 1.3 | Sanctum personal access tokens + named rate limiters | API |
| 1.4 | `ApiResponse` envelope + global exception rendering (401/403/404/409/422/429/500) + `AssignRequestId` | API |
| 1.5 | Profile endpoints: name, timezone, avatar upload to R2, notification preferences | API |
| 1.6 | Scramble OpenAPI → `openapi-typescript` → `types/api.d.ts`, wired into CI | Contract |
| 1.7 | Feature tests: auth happy paths, validation, throttling, cross-user isolation | Tests |
| 1.8 | Frontend auth pages (login/register/forgot/reset) with Zod schemas and error mapping | Web |
| 1.9 | App shell: sidebar, mobile bottom nav, topbar (search, notification bell, avatar menu), theme toggle | Web |
| 1.10 | Route guard in `(dashboard)/layout.tsx` + optional `src/proxy.ts`; `/onboarding` skeleton | Web |
| 1.11 | Settings pages: profile, notifications, security (sessions/tokens) wired to the API | Web |

**Exit demo:** a real user registers on staging, sets a timezone, uploads an avatar, logs out, logs back in, revokes a session. Zero fake data.

---

## 3. Phase 2 — Job core, System 2 (1.5–2 weeks)

| # | Task | Layer |
|---|---|---|
| 2.1 | Migrations + models: companies, jobs, job_notes, job_status_histories, skills, job_skills, tags, job_tag, reminders, job_attachments | API |
| 2.2 | `SkillCatalogSeeder` (~300 skills with aliases) + `DemoUserSeeder` (dev only) | API |
| 2.3 | Job CRUD endpoints + `JobResource`/`JobSummaryResource` + policies | API |
| 2.4 | `JobFilterQuery` (filters/sort/search) + cursor pagination + `fields[...]` sparseness | API |
| 2.5 | Search: generated `tsvector` column + GIN index + `whereFullText` (+ trigram fallback) | API |
| 2.6 | `ChangeJobStatusAction`: transition validation, history row, `applied_at` rule | API |
| 2.7 | Notes, tags, skill attach/detach, bulk actions, stats endpoint | API |
| 2.8 | Attachments: R2 upload, metadata, signed download, delete + orphan-sweep command | API |
| 2.9 | Reminder CRUD + `ScheduleDeadlineRemindersAction` (timezone-correct, idempotent); dispatch lands in Phase 4 | API |
| 2.10 | Feature + unit tests: filters, transitions, timezone math, isolation | Tests |
| 2.11 | `/jobs` list: infinite scroll, FilterBar in URL, sort, search, skeletons/empty/error states | Web |
| 2.12 | `/jobs/board` kanban: drag + "Move to…" fallback, optimistic update, undo toast | Web |
| 2.13 | Job detail tabs (Overview/Requirements/Notes/Timeline/Attachments/Reminders) + edit form | Web |
| 2.14 | `/jobs/new` manual-entry form (capture tabs arrive in Phase 3) | Web |
| 2.15 | `/reminders`, `/companies`, `/dashboard` (counts, upcoming deadlines, quota meter) | Web |
| 2.16 | Component tests for formatters/filters; Playwright smoke: create → status change → note → reminder | Tests |

**Exit demo:** with 40 seeded jobs, a user filters to "remote, closing within 7 days, React", searches, opens a card, changes status, adds a note, sees the timeline, and sets a reminder — all against the real API.

---

## 4. Phase 3 — Capture + AI, System 1 (1.5–2 weeks)

**Build order inside this phase matters: text first (fastest feedback on prompt quality), then URL, then files.**

| # | Task | Layer |
|---|---|---|
| 3.1 | `POST /v1/captures` (text) + `job_extractions` row + quota middleware/service + idempotency by input hash | API |
| 3.2 | `ExtractJobDetailsJob` (queue `ai`), `JobExtractionAgent` with `HasStructuredOutput` + prompt v1 + version storage | API |
| 3.3 | `JobCardMapper` + `JobNormalizer` (dates→UTC, salary parsing, ISO currency) + `SkillResolver` against the catalog | API |
| 3.4 | Status lifecycle (queued/processing/completed/needs_review/failed) + `review_reason` + `missing_fields` | API |
| 3.5 | Approve/discard/retry endpoints + `CreateJobFromExtractionAction` (idempotent) + company resolution + auto reminders | API |
| 3.6 | `AiUsageRecorder` + `AiCostCalculator` + `/profile/usage` + 80%/exhausted quota responses | API |
| 3.7 | Duplicate detection (`JobDuplicateDetector`) with similarity score in the response | API |
| 3.8 | URL capture: `UrlFetchService` with SSRF guards, HTML→text, degrade path when blocked | API |
| 3.9 | PDF/image capture: upload validation, R2 storage, `Files\Document`/`Files\Image` attachments to the agent | API |
| 3.10 | Provider failover config (`[Lab::OpenAI, Lab::Gemini]`) + failure → `needs_review` handling | API |
| 3.11 | `Agent::fake()` tests for: complete extraction, partial extraction, invalid JSON, provider failure, failover, duplicate input | Tests |
| 3.12 | `/jobs/new` capture tabs: paste, PDF dropzone, image dropzone, URL input (progress + retry + manual fallback) | Web |
| 3.13 | Extraction review form: pre-filled fields, confidence badges, missing-field callout, duplicate warning, Save/Discard | Web |
| 3.14 | Realtime: Reverb channel + `ExtractionCompleted` broadcast, with polling fallback wired into TanStack Query | Web/API |
| 3.15 | `/settings/usage` page (quota, tokens, cost per feature) | Web |
| 3.16 | Prompt-accuracy loop: store user corrections diff; grow `tests/Fixtures/extractions/`; document prompt v2 process | Both |

**Exit demo (the product's "wow" moment):** paste a real LinkedIn-style post and a photographed Facebook circular, get two correct, editable job cards with deadlines and reminders — on staging, in front of the user.

---

## 5. Phase 4 — Notifications & polish (1 week)

| # | Task | Layer |
|---|---|---|
| 4.1 | `reminders:dispatch-due` command (every minute, `withoutOverlapping`, `FOR UPDATE SKIP LOCKED`, idempotent) | API |
| 4.2 | `SendDeadlineReminderJob` + mail templates (deadline, plan-for-the-day, quota warning) + database notifications | API |
| 4.3 | Notification centre endpoints + unread badge + `notification.created` broadcast | API |
| 4.4 | `jobs:send-daily-digest` (08:00 user-local; skipped when empty; respects preferences) | API |
| 4.5 | Gap alerts: "deadline passed with no action", "interview in 24 h" | API |
| 4.6 | Scheduler tests: timezone correctness (Asia/Dhaka + a DST region), no duplicate sends on re-run, failure retry | Tests |
| 4.7 | Notification bell + drawer, realtime toasts, `/reminders` calendar view, snooze/dismiss | Web |
| 4.8 | Dashboard upgrade: funnel chart, 30-day activity, "needs action today", next-deadline hero | Web |
| 4.9 | CSV/JSON export (streamed) + account-deletion flow with `PurgeAccountJob` | Both |
| 4.10 | Polish pass: one-handed mobile layout, dark-mode audit, skeletons everywhere, empty/error copy review | Web |
| 4.11 | Accessibility pass: keyboard paths, focus rings, axe checks, contrast in both themes | Web |

**Exit demo:** a deadline 7 days out produces an email at 09:00 local; the in-app bell shows it instantly in a second tab; the digest lists this week's closings; export downloads a real CSV.

---

## 6. Phase 4.5 — MVP hardening (4–5 days)

| # | Task | Done when |
|---|---|---|
| H1 | Security review against `02-architecture.md` §7 (uploads, SSRF, authorization, rate limits, CORS, secrets) | Checklist signed; items fixed or ticketed |
| H2 | Cross-user isolation sweep: automated negative test per resource | All green |
| H3 | Load test: 500 jobs/user, 1,000 list requests, capture bursts | p95 < 300 ms reads; queue drain documented |
| H4 | Backup + restore rehearsal (Postgres → R2, restore into a scratch DB) | Restore verified with real data |
| H5 | AI failure drills: revoke the API key mid-queue, force a provider 429, kill a worker mid-extraction | Users see `needs_review`/retry paths, no data loss, no duplicate jobs |
| H6 | Cost sanity: review `ai_usage_logs`; confirm or adjust quota defaults | Cost per extraction and per CV match documented |
| H7 | Verify every MVP release criterion in `01-prd.md` §10 | All pass |
| H8 | Deployment runbook + rollback procedure + on-call notes | `docs/deployment.md` complete |

---

## 7. Phase 5 — AI Career Assistant (2–2.5 weeks)

| # | Task | Layer |
|---|---|---|
| 5.1 | `cvs` + `cv_analyses` + `cover_letters` migrations; pgvector extension + HNSW indexes | API |
| 5.2 | CV upload endpoint (PDF; DOCX converted to PDF) + private storage + checksum dedupe | API |
| 5.3 | `CvProfileAgent` (structured profile extraction) + parsing status lifecycle | API |
| 5.4 | CV editor UI: correct extracted skills/experience before they are used (human-in-the-loop again) | Web |
| 5.5 | `MatchScoreCalculator` (deterministic weights from config) + exhaustive unit tests | API |
| 5.6 | `JobMatchAgent` (narrative: strengths, ranked gaps, ATS keywords, 3 resume bullets) | API |
| 5.7 | Match endpoints (`POST/GET /jobs/{job}/matches`, `GET /cvs/{cv}/matches`) + result caching | API |
| 5.8 | Match UI: score ring, breakdown, matched/missing skill chips, suggestions, "why this score" panel | Web |
| 5.9 | Cover letter generation + editor (tone, copy/download, always user-approved) | Both |
| 5.10 | Interview prep pack per job | Both |
| 5.11 | Career roadmap toward a target role | Both |
| 5.12 | Semantic search (`mode=semantic`) + "jobs like this" | Both |
| 5.13 | `/analytics` upgrade: skill demand from the user's own pipeline, match-score distribution | Web |
| 5.14 | Tests: `Agent::fake()` for profile/match/letter; deterministic score fixtures; cache-hit assertions | Tests |

**Exit demo:** upload a real CV, open a real saved job, and see a reproducible match score with matched skills, missing skills and three concrete improvements.

---

## 8. Phase 6 — Scale & monetize (optional, only after real usage)

Billing (Stripe via Cashier, plan gating of quota/features, invoices, dunning) · mobile API polish (token flows, push notifications) · read-only public share links for a job card (`share_token`) · browser push · Bangla/English i18n (`next-intl`) · company-level insights · coach/shared pipeline (the only genuine multi-tenancy case) · one-tap capture via browser extension or share target.

**Gate:** do not start Phase 6 until Phases 0–4.5 are stable in production with real users — otherwise billing complexity masks product problems.

---

## 9. Recommended development order (and the reasoning)

```
Phase 0  foundations
   ↓
Phase 1  thin vertical slice: auth          (DB → API → UI → tests → deployed)
   ↓
Phase 2  job core                            (the domain everything depends on)
   ↓
Phase 3  AI capture                          (only meaningful once job cards exist and are searchable)
   ↓
Phase 4  notifications                       (needs working jobs + deadlines first)
   ↓
Phase 4.5 hardening                          (before users, not after)
   ↓
Phase 5  career assistant                    (needs normalized skills + CV data model)
   ↓
Phase 6  scale / monetize
```

**Why this order and not another**

1. **Vertical slices first, breadth later.** Auth is the smallest complete slice (migration → endpoint → page → test → deploy). Finishing it end-to-end proves the whole toolchain — Sanctum cookies across two origins, error envelope, generated types, CI, staging — with minimal surface area. Every later phase then repeats a proven pattern instead of inventing architecture.
2. **The domain before the AI.** Extraction writes into the job-card schema. Building capture first would freeze that schema on guesses; building the job core first gives the AI a precise, already-tested target (`JobCardMapper` maps into fields that already exist, are indexed and are searchable).
3. **Manual before automatic.** `/jobs/new` manual entry (Phase 2) is the fallback that must exist anyway for AI failures and offline-known jobs, and it is the honest way to validate the job-card UX before AI quality is proven.
4. **Text capture before files.** Text is the cheapest and most common input, so prompt iteration is fastest there. URL fetching adds SSRF/robots complexity and file uploads add MIME/vision complexity — both are isolated refinements once the pipeline (queue, statuses, review, quota) already works.
5. **Notifications after data exists.** Reminder scheduling is only testable with real deadlines and statuses; building it earlier produces dead code that drifts.
6. **Deterministic before generative in Phase 5.** `MatchScoreCalculator` (pure PHP, unit-tested) ships before the AI narrative, so the score is explainable and stable and the agent only adds language. This avoids the classic failure mode of a wrapper around a chat model returning an unexplainable number.
7. **Contract-first inside every slice.** Backend endpoint + tests → OpenAPI regenerated → frontend consumes generated types. This is what makes "no hardcoded data" enforceable rather than aspirational: the frontend cannot reference fields that do not exist.
8. **Deploy from Phase 0, not Phase 4.** Staging exists on day three, so every phase ends in something demonstrable and deployment risk is spread thin instead of concentrated at launch.
9. **Hardening before monetization.** AI cost, queue behaviour and security must be observed with real usage before pricing tiers are guessed.

**Working rules that keep the codebase professional (effective from Phase 0)**

| Rule | Why |
|---|---|
| No PR merges without tests for new backend logic | Prevents the drift that makes refactors dangerous |
| No frontend screen merged without a real endpoint behind it | Eliminates fake features and rework |
| One migration per logical change, forward-only in production | Safe deploys, no data-loss edits |
| Every AI call logs usage, tokens and cost | Cost surprise is the biggest risk in this product |
| Every user-facing failure has a manual fallback path | The product must work when AI vendors do not |
| Prompts are versioned files, never string literals in services | Compare, roll back and A/B without code archaeology |
| `docs/` updated in the same PR when behaviour changes | The blueprint stays true instead of becoming a museum piece |

---

## 10. Delivery risk register

| Risk | Likelihood | Mitigation (phase) |
|---|---|---|
| Local PHP environment friction (nothing installed yet) | High | Decide the runtime in Phase 0.1 and prove it before product code (0) |
| Extraction accuracy below expectations on messy posts | Medium | Fixture-driven prompt iteration; confidence flags + human approval make imperfection safe (3) |
| LinkedIn/Facebook URL fetches blocked | High | Text is the default capture path; URL is an enhancement with an explicit degrade message (3) |
| Scope creep into auto-apply or scraping | Medium | Declared out of scope in the PRD; requests become Phase 6 tickets with a legal note |
| Timezone/deadline bugs | Medium | Dedicated unit tests incl. DST regions; timezone confirmed during onboarding (2, 4) |
| AI spend per user exceeding plan value | Medium | Quota + cost log + cheap-model routing; measured in Phase 4.5 before pricing (3, 4.5) |
| Frontend/backend contract drift | Low | Generated types + contract tests in CI (0, 1) |
| Solo-dev burnout / phase sprawl | Medium | Phase exit demos as hard gates; each phase ships something usable |

---

## 11. Immediate next action (the moment this plan is approved)

1. Confirm the decisions in `docs/README.md` → "Decisions needed before Phase 0".
2. Execute Phase 0 tasks 0.2–0.6: PHP runtime, Laravel 13 skeleton, PostgreSQL + pgvector, Redis, R2 bucket — each verifiable in minutes.
3. Create the `jobflow-api` skeleton **in this repository** (Laravel 13 app at the repo root, `docs/` untouched) with Sanctum, Horizon, Reverb, `laravel/ai`, Pest, Pint, Larastan, Scramble — plus the users/preferences migrations only.
4. Create the separate `jobflow-web` repository (Next 16, Tailwind 4, shadcn, TanStack Query, Zustand providers) with the auth pages as the first real screens, and make its CI consume the `openapi.json` artifact produced by this repo's CI.
5. Push both skeletons through CI and deploy them to staging so the toolchain is proven before any feature work begins.

**Then stop, review the first vertical slice with the product owner, and only then continue into Phase 2.**



