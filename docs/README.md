# JobFlow AI — Development Blueprint (v1.0)

> **Status:** Planning only. No application code has been written yet.
> This folder is the single source of truth for the product until code exists.
> Approval required before Phase 0 begins.

## What is in this folder

| File | Contents |
|---|---|
| `README.md` | Index, verified stack versions, repo layout, open decisions, glossary |
| `01-prd.md` | Product requirement document: vision, users, problems, features, user stories, metrics |
| `02-architecture.md` | System architecture: frontend, backend, AI flow, upload flow, notifications, security, infra |
| `03-database.md` | Tables, columns, keys, relationships, indexes, enum contracts, migration order |
| `04-api.md` | REST API contract: auth, jobs, captures, reminders, notifications, future AI endpoints |
| `05-frontend.md` | Page/route structure, folder structure, state strategy, component inventory, UX flows |
| `06-roadmap.md` | Phase 0–6 plan, deliverables, acceptance criteria, estimates, development order |

## Verified environment (checked on this machine, 2026-09-28)

| Tool | Status |
|---|---|
| Node.js | v22.23.2 (installed) |
| npm | 10.9.8 (installed) |
| PostgreSQL | 16.15 via Homebrew (installed) — **pgvector extension not yet installed** |
| PHP / Composer | **NOT installed** (no `php`, no `composer` on PATH) |
| Docker | Docker.app present, `docker` CLI **not** on PATH |
| Repo state | All branches (`master`, `development`, `project_setup`) have **empty file trees** |

**Consequence:** Phase 0 starts with a local runtime decision (Decisions #1 below). Commits in this repo contain only an unrelated Express/Mongoose demo app — **nothing is reusable and there is no Laravel code in history**.

## Verified stack versions (latest stable at time of writing)

Confirmed from packagist.org, registry.npmjs.org and official docs — not from memory:

| Layer | Package | Version | Notes |
|---|---|---|---|
| Backend | `laravel/framework` | **13.33.0** | Laravel 13 requires **PHP 8.3–8.5**; released 2026-03-17; security fixes until 2028-03-17 |
| Backend | `laravel/sanctum` | 4.3.3 | SPA cookie auth + API tokens; docs explicitly cover a separate Next.js frontend |
| Backend | `laravel/ai` | **1.0.0** | First-party AI SDK: agents, structured output, attachments, queueing, embeddings, vector stores, failover, fakes |
| Backend | `laravel/horizon` | 5.50.0 | Redis queue dashboard + metrics |
| Backend | `laravel/reverb` | 1.12.0 | WebSocket broadcasting (live extraction status) |
| Backend | `pestphp/pest` | 5.2.1 | Test framework |
| Frontend | `next` | **16.3.6** | App Router, Turbopack default, dynamic-by-default caching, `proxy.ts` replaces `middleware.ts` |
| Frontend | `react` | 19.3.0 | |
| Frontend | `typescript` | 7.0.2 | Next 16 can type-check with TS 7 |
| Frontend | `tailwindcss` | **4.3.3** | CSS-first config (`@theme` in `globals.css`); no `tailwind.config.js` |
| Frontend | `shadcn` (CLI) | **4.21.0** | `npx shadcn@latest init` / `add`; Radix primitives + CVA |
| Frontend | `@tanstack/react-query` | 5.104.0 | v5 stable line |
| Frontend | `zustand` | 5.0.15 | |
| Database | PostgreSQL | 16.x | + `pgvector` for semantic search (Laravel 13 `whereVectorSimilarTo`) |
| Storage | Cloudflare R2 | S3-compatible | Presigned URLs 1s–7 days; GET/HEAD/PUT/DELETE only (no POST form upload); private bucket + signed URLs |

### Version-specific ground rules (do not fight the frameworks)

1. **Next.js 16:** no `middleware.ts` — the convention is `src/proxy.ts` with `export function proxy()`. Next docs call middleware a "last resort"; prefer layout/Server-Component auth checks with `unauthorized()` / `forbidden()`.
2. **Next.js 16:** caching is dynamic by default; opt in deliberately with `cacheComponents` + `use cache`. Never assume implicit caching.
3. **Laravel 13 AI SDK:** structured output = implement `HasStructuredOutput` and `schema(JsonSchema $schema): array`; the reply is a `StructuredAgentResponse` readable like an array. Attachments use `Laravel\Ai\Files\{Document,Image,Audio,Video}::fromStorage()|fromPath()|fromUpload()`. Failover is `provider: [Lab::OpenAI, Lab::Gemini]`; only `FailoverableException` (rate limit / overload / credits) triggers it.
4. **Laravel 13:** ships JSON:API-style resources, `Queue::route()`, `PreventRequestForgery`, `Cache::touch()`, and pgvector-backed `whereVectorSimilarTo()` — use these instead of hand-rolling equivalents.
5. **Tailwind 4:** design tokens live in CSS (`@theme`), so shadcn tokens are declared once in `globals.css`.

## Repository layout (decided: two repositories)

| Repository | Contents | Deploy target |
|---|---|---|
| **`jobflow-api`** — *this repository* (`jobFlow_web`, remote `backend_dev`) | Laravel 13 API + the `docs/` blueprint | VPS / Forge (`api.jobflow.ai`) |
| **`jobflow-web`** — new repository | Next.js 16 app | Vercel (`app.jobflow.ai`) |

```
jobflow-api/                     # this repo — Laravel application root
├── app/ config/ database/ routes/ tests/     # standard Laravel structure
├── docs/                        # THIS BLUEPRINT — single source of truth
├── .github/workflows/api.yml    # Pint · Larastan · Pest · Scramble → OpenAPI artifact
└── README.md

jobflow-web/                     # separate repo — Next.js application root
├── src/                         # app/ components/ lib/ providers/ stores/ types/
├── .github/workflows/web.yml    # lint · tsc --noEmit · build · openapi-typescript
└── README.md                    # links back to this repo's docs/
```

**Why the blueprint lives in `jobflow-api`:** schema and endpoints are the source of truth, and the OpenAPI artifact is produced here, so the docs sit next to what they describe. `jobflow-web` keeps a short README that links to `docs/` here.

**Contract flow between the repos:** `jobflow-api` CI generates `openapi.json` as a build artifact → `jobflow-web` CI pulls it and regenerates `src/types/api.d.ts` → a backend field rename fails the frontend build instead of silently breaking production. If the two repos ever need to move in lockstep (breaking contract changes), tag a release in `jobflow-api` and pin that tag in `jobflow-web` CI.

**Alternative considered:** a third `jobflow-docs` repository. Rejected for now — three repos for one solo developer adds ceremony without benefit. Moving `docs/` later is a folder move, not a rewrite.


## Decisions needed before Phase 0 (blocking)

| # | Decision | Recommendation |
|---|---|---|
| 1 | Local PHP runtime: Laravel Herd vs `brew install php@8.4 composer` vs Docker/Sail | **Herd** on macOS (zero-config PHP 8.4). Docker/Sail as CI + Linux parity fallback |
| 2 | Auth transport: Sanctum SPA cookies (API + web on one registrable domain) vs BFF (Next route handlers hold an httpOnly token) | **Sanctum SPA cookies** for v1 (see `02-architecture.md` → Security) |
| 3 | Domains: `app.jobflow.ai` + `api.jobflow.ai` (needed for #2) | Same registrable domain. If the frontend must live on `*.vercel.app`, switch to the BFF pattern |
| 4 | AI provider default + failover order | **OpenAI primary → Gemini failover** (both natively accept images and PDFs) |
| 5 | Free-tier AI quota numbers | 30 extractions + 3 CV analyses per month, config-driven, enforced server-side |
| 6 | Monetization in v1? | **No** — Stripe/Cashier in Phase 6 behind a `billing` flag; quota still enforced now |
| 7 | Transactional email provider | Postmark or Mailgun; dev uses the `log` mailer |
| 8 | Contract tooling | `dedoc/scramble` (OpenAPI 3.1 from Laravel routes) + `openapi-typescript` → generated TS types checked in CI |
| 9 | Search engine | **PostgreSQL full-text search only** for v1; do not add Meilisearch/Scout yet |
| 10 | Stray worktree `.kilo/worktrees/airy-sphynx` (unrelated Express project) | Delete or gitignore; not part of this product |
| 11 | Repository layout | **DECIDED: separate repos** — `jobflow-api` (this repo, Laravel) and `jobflow-web` (new repo, Next.js); blueprint stays in `jobflow-api` (see "Repository layout" above) |

Rows 1–10 are still open; **row 11 is settled**, so the folder trees and CI steps across `02-architecture.md`, `05-frontend.md` and `06-roadmap.md` already reflect the two-repo split.

## Scope boundaries (what "done" means for this blueprint)

- This blueprint covers **Phases 0–4.5 in implementation detail** (the MVP) and **Phases 5–6 at design level**.
- Every schema field, endpoint and page listed for the MVP is intended to be built; Phase 5/6 items are marked as such and must not appear as fake UI before their endpoint exists (see product principle #6).
- Estimates assume **one experienced full-stack developer, full-time**; multiply by ~1.6 for part-time (20 h/week).

## Glossary

- **Job card** — the structured record produced from a raw capture (a `jobs` row plus its skills/company links).
- **Capture** — one raw input submission (text/PDF/image/URL) and its AI extraction record (`job_extractions` row).
- **Extraction** — the async AI process that turns a capture into a job-card draft.
- **needs_review** — extraction finished but with low confidence, missing mandatory fields, or an unfetchable source; the user must confirm or fix fields.
- **Human-in-the-loop** — AI output is always a draft; a user approves it before it becomes a saved job. Nothing is auto-applied or auto-sent.
- **Match score** — deterministic weighted CV↔job comparison explained by AI text (never an unexplained number).
- **Job card pipeline** — create capture → extract → review → approve → track.

**Next:** read `01-prd.md` for scope, `06-roadmap.md` for build order.


