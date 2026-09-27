# 01 — Product Requirement Document (PRD)

**Product:** JobFlow AI · **Doc version:** 1.0 · **Date:** 2026-09-28

---

## 1. Product vision

Turn scattered, disposable job posts into a single, structured, actively managed job pipeline.

A job seeker finds opportunities across Facebook groups, LinkedIn, Bdjobs, company career pages and email — then stores them in screenshots, notes apps, PDFs and memory. The result: missed deadlines, lost posts, duplicate applications, and a CV that is not tailored to any job.

**JobFlow AI** is a personal job workspace: paste/drop/upload anything → AI converts it into a clean, editable job card → deadline reminders and a status pipeline keep the user moving → later, AI compares the CV against each job to show exactly what is missing.

**Positioning:** *"Every job post becomes an organized opportunity with a deadline, a status, and a plan."*

**3-year vision:** the default operating system for a job search in emerging markets — capture, track, prepare, improve — with AI that survives messy real-world input (a Facebook screenshot, a mixed Bangla/English circular, a 12-page PDF).

---

## 2. Target users

| Persona | Description | Primary need | Success looks like |
|---|---|---|---|
| **P1 — Fresh graduate** (primary) | 21–26, 20–60 applications in 3–6 months, mostly Bdjobs/LinkedIn/Facebook groups, applying from a phone | Never miss a deadline; know what is still open | More relevant applications, zero missed deadlines |
| **P2 — Mid-level switcher** (primary) | 2–8 yrs experience, passive search, 5–15 high-value targets, compares salary and fit | Organize a small pipeline; tailor the CV per job | Interviews with a tailored CV, trackable funnel |
| **P3 — Career changer / bootcamp grad** | Skills gap is the blocker; CV poorly matched to ads | Understand the gap and how to close it | Clear missing-skills list + roadmap |
| **P4 — Career coach / power user** (later) | Advises others; needs exports and reports | Share a pipeline, export data | (Phase 6+, pro tier trigger) |

**Non-users (v1):** recruiters/employers, agencies doing bulk auto-apply, teams. This is a **single-person tool** — no multi-tenant orgs in v1.

**Context of use:** ~70% mobile (Android Chrome), intermittent connectivity, mixed English/Bangla posts, a mix of well-structured (LinkedIn) and messy (Facebook threads, photographed circulars) sources.

---

## 3. Problems

| # | Problem | Today's workaround | Cost to user |
|---|---|---|---|
| 1 | Job posts are scattered across 6+ sources with no common format | Screenshots, Keep, WhatsApp self-chat | Posts get lost; re-finding takes minutes |
| 2 | Deadlines are buried inside a screenshot or PDF, unsearchable | Mental notes, occasional calendar entry | Missed deadlines — the most common failure |
| 3 | Saved posts are unsearchable/unfilterable | Scroll the gallery/notes | Wasted time, duplicated effort |
| 4 | No view of application progress | Nothing, or a desktop-only spreadsheet | Pipeline blindness, missed follow-ups |
| 5 | CV is generic because tailoring per job is slow | Same CV everywhere | Lower callback rate |
| 6 | Requirements are unclear → skills gap invisible | Guesswork | Applying to wrong jobs / under-applying |
| 7 | Structured job data exists only on some platforms | Manual copy-paste of fields | ~10 minutes of data entry per job |

**Core insight:** the bottleneck is not *finding* jobs; it is the **10 minutes of manual transcription** and **the missing deadline memory**. AI extraction + reminders remove both.

## 4. Solution overview

Three systems, built in this order:

1. **AI Job Capture** — accept text / PDF / image / URL, extract a strict structured schema, produce an editable job-card draft (human-in-the-loop).
2. **Job Application Management** — statuses, notes, reminders, search/filter/sort, attachments, timeline, analytics.
3. **AI Career Assistant (Phase 5)** — CV parsing, per-job match score, missing skills, improvement suggestions, cover letters, interview prep, roadmap.

### Product principles

1. **Capture must beat the current workaround.** Target: paste → reviewed job card in under 20 seconds.
2. **Never block the user.** If AI fails or a URL is unfetchable, degrade to a prefilled manual card and explain why — no dead ends.
3. **AI output is a draft** — always editable, never trusted blindly; low-confidence fields are visually flagged.
4. **Deterministic before generative.** Scores, reminders and filters are computed with testable logic; AI adds extraction and language, not invisible magic.
5. **Mobile-first.** Every core flow works one-handed on a 360px viewport.
6. **No fake features.** A screen exists only if its endpoint exists; unfinished work sits behind flags and is absent from navigation.
7. **Own the data.** Export (CSV/JSON) and delete (account purge) are first-class, not afterthoughts.

---

## 5. Feature scope

### 5.1 MVP (Phases 1–4) — must ship

**A. Accounts & profile**
- Email + password registration, login, logout, forgot/reset password, email verification.
- Profile: name, avatar, timezone (deadline correctness depends on it), locale.
- Notification preferences: deadline offsets (default 7/3/1 days), email on/off, daily digest on/off.
- Session management (revoke own sessions/tokens).

**B. Capture (System 1)**
- Four input modes: **text paste**, **PDF upload**, **image/screenshot upload**, **URL**.
- Async extraction with live status (queued → processing → completed / failed / needs_review).
- Strict extracted schema: title, company, location, work mode, employment type, salary (+currency/period), deadline, posted date, experience (level + years), education, skills (required/preferred), responsibilities, requirements, benefits, apply URL, apply email/contact.
- Editable review screen with per-field confidence flags; approve → creates job; discard → source optionally retained.
- Duplicate detection (same user + company + similar title) with an explicit "possible duplicate" warning.
- Idempotent re-submission: identical input hash is not reprocessed or charged twice.
- Quota-aware: per-user monthly extraction limit, enforced server-side (not just in UI).

**C. Job management (System 2)**
- Job card CRUD; soft delete + restore.
- Status pipeline `saved → preparing → applied → interview → offer`, plus `rejected`; every change recorded with timestamp and optional note.
- Kanban board (by status) + list view with server-side filters/sort/pagination.
- Filters: status, source type, company, work mode, employment type, deadline range, applied range, has-deadline, skills; sort by deadline/created/updated/status.
- Full-text search across title, company, description, notes and skills.
- Notes (multiple per job), tags, reminders, attachments.
- Timeline per job (status changes + notes + reminders + attachments, merged chronologically).
- Dashboard: counts per status, upcoming deadlines (7/30 days), weekly activity, conversion funnel.

**D. Reminders & notifications**
- Manual reminders (date/time + note) and auto-suggested deadline reminders (7/3/1 days before, 09:00 user-local).
- Channels in v1: email + in-app notification centre; realtime in-app toast over WebSocket.
- Daily digest email: "closing this week / follow-ups due".
- Snooze/dismiss, per-type preferences, timezone-correct scheduling, failed-send retry.

**E. Files**
- Attachments per job and raw capture files retained on **private** R2 storage.
- Size/MIME validation, checksum dedupe, signed time-limited download URLs, delete cascade on job purge.

### 5.2 Phase 5 — AI Career Assistant

- CV upload + structured profile extraction (skills, experience, projects, education).
- Per-job **match score** (weighted, deterministic) + AI explanation: strong matches, missing skills (prioritised), experience/education gaps.
- CV improvement suggestions targeted at one job; ATS keyword check.
- Cover letter generation (per job, tone selection, editable).
- Interview prep pack per job (likely questions, topics to revise, company context).
- Career roadmap toward a target role (skills → projects → timeline).
- Semantic search ("jobs like this") and CV↔job embedding similarity as a secondary signal.

### 5.3 Explicitly out of scope (v1)

- **Auto-apply / bot submissions** on third-party sites (ToS violations, brittle, unsafe).
- Bulk scraping of LinkedIn/Bdjobs (only user-initiated single-URL fetches).
- Recruiter-side tools, team collaboration, shared pipelines, multi-tenant orgs.
- Native mobile apps (Phase 6+; the API stays token-ready).
- Payments/billing (Phase 6, flagged off) — AI quota is still enforced in MVP.

## 6. User stories with acceptance criteria (MVP)

Format: **As a … I want … so that …**, followed by testable acceptance criteria (AC). These become Pest feature tests and Playwright smoke tests.

### US-01 Register & verify
As a new user I want to create an account so my pipeline is private to me.
- AC1 `POST /v1/auth/register` with name/email/password/timezone returns 201 and starts an authenticated session.
- AC2 Password rules enforced (min 8); email unique case-insensitively; 422 with field-level errors otherwise.
- AC3 Verification email queued; the user can log in but sees "verify your email" until verified.
- AC4 Rate limit 5 requests/min per IP+email, then 429 with `Retry-After`.

### US-02 Login / logout / reset
As a returning user I want a fast, safe login.
- AC1 Wrong credentials → 422 with a generic message (no user-existence leak); failures throttled per IP+email.
- AC2 Logout invalidates session + cookie; protected endpoints then return 401.
- AC3 Reset link is single-use, expires in 60 min, and invalidates other sessions on success.

### US-03 Capture by text (core flow)
As a user with a copied job post I want to paste it and get a job card.
- AC1 Text up to 20,000 chars; empty/whitespace-only → 422.
- AC2 `POST /v1/captures` returns **202** with `{ extraction_id, status: "queued" }` in under 500 ms (no AI call inside the request cycle).
- AC3 Duplicate paste (same user + same input hash) returns the existing extraction instead of creating a second billable one.
- AC4 Progress is observable: private WebSocket event **or** polling `GET /v1/captures/{id}` every 2 s reaches a terminal state within 60 s for typical input.
- AC5 Success payload contains every schema field; unknown values are `null`, never invented (verified by a fixture test with an incomplete ad).
- AC6 Every field is editable before approval; approval creates exactly one job; re-approving is idempotent.
- AC7 Quota exceeded → 429 with a clear message and the capture is not queued.

### US-04 Capture by PDF, image and URL
- AC1 PDF ≤ 10 MB; image ≤ 8 MB (jpg/png/webp/heic); validated by MIME sniffing, not file extension.
- AC2 A scanned/photographed circular still yields title + company + deadline (vision path).
- AC3 URL fetch runs server-side with SSRF protection: private/link-local IP ranges blocked, ≤ 3 redirects, 10 s timeout, 2 MB body cap, content-type allow-list.
- AC4 Unfetchable or JS-only URL (e.g. LinkedIn) → `needs_review` with reason "source could not be read — paste the text instead", opening the review screen prefilled with whatever was obtainable.
- AC5 Every stored file is private; downloads use short-lived signed URLs; files are purged when the job is force-deleted.

### US-05 Manage jobs
- AC1 Status transitions are validated against an allow-list; each writes a `job_status_histories` row with actor and timestamp.
- AC2 List endpoint supports filters, sort, full-text search and cursor pagination; `per_page` max 100; p95 < 300 ms for 1,000 jobs per user.
- AC3 Cross-user access returns **404** for foreign resources (no ID enumeration).
- AC4 Soft delete hides the job from all views/lists/stats; restore returns it with history intact.
- AC5 Bulk actions (status change, tag, delete) report per-item results.

### US-06 Reminders
- AC1 Deadline reminders are computed in the user's timezone at 09:00 local on the configured offset days; changing timezone reschedules only future reminders.
- AC2 Reminders are idempotent: a dispatcher re-run never sends twice (status guard + unique index).
- AC3 A failed send retries 3× with backoff, then marks `failed` and raises an in-app alert.
- AC4 Users can disable email while keeping in-app notifications.

### US-07 Dashboard insights
- AC1 Counts per status, upcoming deadlines (7/30 days) and this week's applications are served by one endpoint (`GET /v1/dashboard`) in a single round trip.
- AC2 Stats update immediately after a status change (cache invalidated on write).
- AC3 Empty states guide the user to their first capture instead of showing permanent zeros.

### US-08 CV match (Phase 5, designed now)
- AC1 Uploading a CV produces a structured profile; re-uploading an unchanged file does not re-charge AI (checksum dedupe).
- AC2 Match score is reproducible: same CV + same job ⇒ same score (deterministic function, unit-tested with fixtures).
- AC3 Output always lists matched skills, missing skills, and experience/education gaps separately; a job with no extractable skills returns score `null` plus an explanation, never a fake 0%.
- AC4 Generated content (cover letter, suggestions) is labelled AI-generated and requires explicit user action to copy/save; JobFlow never submits anything on the user's behalf.

## 7. Non-functional requirements


| Area | Requirement |
|---|---|
| Performance | p95 API < 300 ms for CRUD/list reads; capture accept < 500 ms; extraction p50 < 12 s, p95 < 45 s for a 5-page input |
| Scale | Designed for 10k users / 500 concurrent sessions on a 2-vCPU API node + 1 queue worker; no DB read/write split in v1 |
| Reliability | 99.5% monthly uptime for MVP; graceful degradation when the AI provider is down (queue, retry, `needs_review`) |
| Correctness | Deadlines/reminders timezone-correct (stored UTC, rendered and scheduled in the user's TZ); no duplicate reminders |
| Security | OWASP Top 10 baseline, per-user authorization on every resource, server-side validation always, private file storage, no secrets in the client bundle |
| Privacy | CVs and job data private to the owner; export + delete-account; provider data-retention/"training" settings disabled where supported |
| Accessibility | WCAG 2.1 AA on core flows: keyboard navigable, visible focus, labelled inputs, 4.5:1 contrast |
| Responsiveness | 360px–1920px; primary actions reachable with one thumb on mobile |
| Maintainability | Pint + Larastan (level 6+), ESLint + `tsc --noEmit`, ≥80% coverage on Services/Actions/Controllers, OpenAPI generated in CI |
| Observability | Structured logs with `request_id`, `ai_usage_logs` for every AI call, Horizon for queues, uptime + error alerting |
| Cost control | AI spend capped by quota; embeddings cached by content hash; prompt inputs truncated to a documented budget |

---

## 8. Success metrics (how the MVP is judged)

**North-star metric:** job cards reaching `applied` per active user per month.

| Layer | Metric | Target (first 90 days) |
|---|---|---|
| Activation | New user creates ≥1 job card within 24 h | ≥ 60% |
| Core value | Median first visit → first approved job card | < 5 minutes |
| Retention | W4 retention among users with ≥5 job cards | ≥ 30% |
| Reliability | Extractions approved without correction | ≥ 85% |
| Trust | Reminder delivery success | ≥ 99% |
| Cost | AI cost per approved job card | ≤ $0.01 |
| Quality | Field-level complaint rate for deadlines | < 3% |

Instrumentation: `ai_usage_logs` + `job_extractions` outcomes + product events (`capture_submitted`, `extraction_approved`, `status_changed`, `reminder_sent`, `match_generated`).

---

## 9. Risks & mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| AI cost scales with usage | Margin/viability | Hard quota, per-user cost log, cheap-model routing, input truncation, embedding cache |
| Extraction accuracy on messy Bangla/English posts | Trust | Strict schema, confidence flags, mandatory human approval, fixture suite grown from real corrections |
| Sources block bots (LinkedIn) | Broken flow | Explicit degrade path to paste-text mode with a clear message; no retry loops |
| Users expect auto-apply | Expectation gap | State scope in-product: JobFlow organises and prepares; the user applies |
| Wrong deadline causes a missed application | Reputation | Low-confidence deadlines must be confirmed; 7/3/1-day reminders give recovery time |
| Provider outage / rate limit | Blocked captures | Failover chain (OpenAI → Gemini), queue retries, `needs_review` fallback, provider health in admin logs |
| Scraping / ToS concerns | Legal | Only user-initiated single-URL fetches; no crawling, no bulk import, no automated submissions |
| PII inside CVs | Compliance | Private bucket, signed URLs only, retention policy, user-triggered deletion |

---

## 10. MVP release criteria (definition of done for the whole MVP)

1. All US-01…US-08 acceptance criteria pass as automated tests in CI.
2. Contract is published (OpenAPI) and the frontend consumes generated types — zero hand-written duplicate API types.
3. No screen renders placeholder/hardcoded data; every list, count and badge comes from an endpoint.
4. Cross-user isolation is proven by negative tests for every resource type.
5. Timezone, deadline and reminder logic covered by unit tests including DST-shift and Asia/Dhaka cases.
6. AI features are covered by `Agent::fake()` tests, including failure → `needs_review` degradation.
7. Production deploy reproducible from CI; migrations run forward-only; seeders are dev-only.
8. Security checklist signed off: rate limits, uploads, SSRF, CORS, secrets, authorization.



