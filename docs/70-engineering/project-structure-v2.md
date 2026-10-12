# Bulbula — Application Structure (v2)

**Status:** Active. Supersedes `php-application-structure-v1.0.md`.
**Scope:** PHP 8.4, no framework. The organising rule is *surface first*: the top
of the tree is the four places a request can enter the system, and everything
shared sits behind them.

---

## 1. The idea in one paragraph

Bulbula is one PHP application with four ways in. A **web** surface renders
pages for people, an **api** surface answers JSON for other systems, an
**admin** surface runs moderation and operations, and a **console** surface runs
scheduled work. All four call the same application services in the same process
(decision TD-01) — the API is not a private HTTP hop for the website. Below the
surfaces are the parts that do the work: domain rules, repositories, services,
security and integrations. The tree says which of those a file is, and the
namespace says it too, so a path in the repository is always enough to know what
you are looking at and who may call it.

## 2. The tree

```
bulbula/
├── public/                     ← the ONLY web-reachable directory
│   ├── index.php               single entry point for every HTTP request
│   ├── .htaccess               rewrite + security headers + deny-list + PHP handler pin
│   └── assets/{css,img,js}/    static files, served directly by the web server
│
├── frontend/                   Bulbula\Frontend\  — pages for people
│   ├── Controllers/            one per page or resource, thin
│   └── Views/{layouts,pages,partials,components}/
│
├── api/                        Bulbula\Api\       — JSON for other systems
│   ├── Controllers/V1/         one per endpoint group
│   ├── Requests/               input shape + validation rules per endpoint
│   └── Resources/              the exact JSON a client receives
│
├── admin/                      Bulbula\Admin\     — moderation & operations
│   ├── Controllers/            every action checks a named permission
│   └── Views/{layouts,pages,partials}/
│
├── backend/                    Bulbula\Backend\   — the application itself
│   ├── Core/                   framework-in-miniature: env, config, container,
│   │                           request, response, router, kernel, view, errors
│   ├── Console/Commands/       scheduled and manual jobs (cron-driven, TD-06)
│   ├── Database/               the one place PDO is created, plus query helpers
│   ├── Domain/                 entities and value objects; rules, no I/O
│   │   └── {Area}/             one folder per bounded area — see §4.1
│   ├── Repositories/           every SQL statement lives here, and only here
│   │   └── {Area}/
│   ├── Services/               use cases; the only layer surfaces may call
│   │   └── {Area}/
│   ├── Security/               sessions, CSRF, permissions, rate limiting, audit
│   ├── Integrations/           outbound HTTP: maps, Telegram, mail provider (TD-07)
│   ├── Mail/Templates/         email bodies, outside the view tree
│   ├── Exceptions/             typed failures, incl. the HTTP ones
│   └── Support/                helpers, logger factory, health checks
│
├── routes/                     web.php · api.php · admin.php · health.php · console.php
├── config/                     app · database · security · logging · mail
├── database/{migrations,seeds,reference}/
├── storage/                    runtime, git-ignored except .gitkeep
│   ├── logs/  cache/  exports/  tmp/  uploads/{originals,derivatives}/
├── tests/{Unit,Feature,Arch,Support}/
├── bin/console                 the only command-line entry point
└── docs/                       the specification set (unchanged)
```

Sixty directories, all of them earning their place. `storage/` and `vendor/` are
the only trees that are not source.

## 3. Why the top level is the way it is

**Four surfaces, one application.** `frontend/`, `api/`, `admin/` and the console
are peers because they are peers in reality: each has its own routing file, its
own failure style (HTML vs JSON), its own audience, and its own tests. Splitting
by surface is what makes "who can reach this?" answerable by looking at a path.

**One shared core, not four copies of it.** Nothing in the domain, repository,
service or security layers knows which surface called it. That is what keeps a
rule (say, "a listing cannot be published without a linked permission record")
enforceable in exactly one place for all four entry points — including the
import path, which is where the invariant would otherwise be lost.

**`public/` is the whole web root.** Templates, config, storage, migrations and
the application code all sit above the document root and are unreachable by URL.
A misconfigured server that exposes the repository root leaks nothing it should
not already have leaked by other means.

**No `src/`.** PSR-4 maps one namespace per surface root (`composer.json`), so the
path is the namespace and the namespace is the path. There is no extra hop of
indirection to remember.

## 4. What goes where

| You are writing… | It goes in | Naming |
|---|---|---|
| A page | `frontend/Views/pages/` + a controller action | `listing-show.php`, `ListingController::show` |
| A JSON endpoint | `api/Controllers/V1/` | `ListingController::index` |
| A moderation action | `admin/Controllers/` | `ReviewModerationController::approve` |
| A use case | `backend/Services/{Area}/` | `Services/Directory/PublishListingService::publish` |
| A business rule | `backend/Domain/{Area}/` | `Domain/Directory/Listing::canPublish` |
| A query | `backend/Repositories/{Area}/` | `Repositories/Directory/ListingRepository::findBySlug` |
| A table change | `database/migrations/` | `2026_10_11_120000_create_audit_entries.php` |
| A scheduled job | `backend/Console/Commands/` | `PurgeExpiredSessionsCommand` |
| Something that talks to the outside world | `backend/Integrations/` | `TelegramClient` |

### 4.1 Areas inside `Domain`, `Services` and `Repositories`

Forty capabilities do not belong in three flat folders. Each of the three
product layers is divided by **bounded area**, using the areas of TRD §11:
`Directory`, `Operations`, `Identity`, `Community`, `Advertising`, `Platform`,
with `Search` and `Media` beside them.

```text
backend/Services/Directory/PublishListingService.php
backend/Domain/Directory/ListingState.php
backend/Repositories/Directory/ListingRepository.php
```

| Area | Owns | Home |
| --- | --- | --- |
| Directory | Business, Branch, Listing state, Category, Subcategory, Alias, Area, Sub-city, Landmark, media association | `Domain/Directory/` · `Services/Directory/` · `Repositories/Directory/` |
| Operations | Permission records, provenance, verification, corrections, reports, work queues | `…/Operations/` |
| Identity | Customer, provider identities, sessions, OTP state, staff users, roles, authorization | `…/Identity/` |
| Community | Reviews, ratings, saves, review reports, moderation | `…/Community/` |
| Advertising | Packages, placements, campaigns, targeting, delivery | `…/Advertising/` |
| Search | Query interpretation, candidates, ranking, suggestions, the search document | `…/Search/` |
| Media | Upload intake, validation, derivatives, storage adapter | `…/Media/` |
| Platform | Audit log, analytics events and rollups, notifications, background jobs, cache | `…/Platform/` |

Two consequences worth stating:

- **Table ownership is per area** (TR-16): `Repositories/Directory/` is the only
  code that may read the Business, Branch or Listing tables. Cross-area work is
  orchestrated by an application service, never by a join across two areas'
  repositories.
- **Areas are not namespaces of their own.** `Bulbula\Backend\Services\Directory`
  is a namespace; there is no `Bulbula\Directory`. The surface split is the
  organising rule, and the area is the second coordinate inside the backend.

Four rules that make the table enforceable:

1. **A view never queries.** Controllers and services prepare data; templates
   print it. No `Connection`, no repository inside a template — ever.
2. **A repository holds every SQL statement.** If a query appears anywhere else,
   it is in the wrong file.
3. **A surface never reaches past the service layer.** Controllers call services.
   They do not open the database and they do not re-implement a rule.
4. **One namespace owns outbound HTTP** (TD-07), asserted by a test, so an
   integration can be swapped or forbidden without a search across the codebase.

## 5. The request lifecycle

```
public/index.php
  └─ Application::boot()
       ├─ Env::load()                 .env read once, never logged
       ├─ Config                       config/*.php, dotted access
       ├─ error handling               warnings become exceptions
       ├─ Router                       routes/{web,api,admin,health}.php loaded
       └─ Container bindings           Config, Router, View, ErrorRenderer,
                                       Connection (lazy), Logger, Kernel
  └─ Kernel::handle(Request::fromGlobals())
       ├─ correlation id               X-Request-Id on every response
       ├─ FastRoute dispatch
       ├─ controller (constructed by the container, dependencies injected)
       └─ Response
  └─ Response::send()                  status, headers, body
```

Failures are rendered in one place. `ErrorRenderer` returns the documented JSON
envelope (`error.code`, `error.message`, `error.request_id`) to API callers and a
readable page to everyone else; stack traces, SQL and file paths appear only
when `app.debug` is on, and `config/app.php` forces debug off in production
regardless of `.env`. A 404 for something that exists but is not yours is the
same 404 as for something that does not exist — existence is itself information.

## 6. Security, designed in rather than bolted on

These are properties of the structure, not a checklist to apply later:

- **One entry point.** `public/index.php` is the only file web requests reach;
  `bin/console` is the only file that calls `exit()`.
- **Sessions and CSRF live in `backend/Security/`.** Every state-changing form
  POST carries a token; verification is a service call, so it can be tested
  directly. Cookies are `HttpOnly`, `SameSite=Lax`, `Secure` outside local.
- **Prepared statements only.** PDO is created in one place with
  `ATTR_EMULATE_PREPARES => false`; no string-built SQL anywhere.
- **Escaping at one boundary.** `e()` (`View::escape`) is the only way a value
  enters HTML, with `ENT_QUOTES | ENT_SUBSTITUTE` and an explicit UTF-8 charset.
- **Nothing sensitive in a log.** No passwords exist anywhere in the schema; OTP
  codes, tokens and session identifiers are redacted by policy.
- **Uploads are not web-served.** They land in `storage/uploads/` outside the
  document root and leave, if ever, through a controller that authorises first.
- **Rate limits come from config.** `config/security.php` holds per-action
  limits; no number is invented in code, and no limit is disclosed to a caller
  that has hit it.
- **Permissions are data.** Named permission strings checked by a service; the
  code never branches on a role name (TD-03).

## 7. Conventions

- `declare(strict_types=1);` and `final` on every class (enforced by Pint).
- Constructor property promotion and readonly where the value never changes.
- Types on every parameter and return; PHPStan at level max over the whole tree.
- One class per file, file name equals class name, namespace equals path.
- Exceptions are typed and meaningful; the HTTP ones carry their status.
- Migrations are forward-only `.sql`, numbered, never edited after they ship.
- Config is read through `Config`; no `getenv()` outside `Env`.
- No constants for anything a decision has not yet fixed: ranking weights,
  retention periods and session lifetimes come from `config/`, never from code.

## 8. How to work in it

```bash
composer install                 # dependencies (requires PHP 8.4)
cp .env.example .env             # then fill in DB credentials
php -S localhost:8000 -t public  # local server; the smoke test named below
composer check                   # lint + analyse + tests, the one command that must pass
composer test                    # PHPUnit
composer analyse                 # PHPStan, level max
composer lint / composer fix     # Pint, check / fix
php bin/console                  # command-line entry point
```

The first request worth making locally is `GET /health`, which reports
`{"status":"pass"}` without touching anything; `GET /health/ready` adds the
checks that matter — a real database round-trip and a writable `storage/logs/` —
and answers `503` with `{"status":"fail","checks":{…}}` rather than dying with
the database. Before proposing a commit, run the secret scan that CI runs:
`gitleaks git .` (tracked content and full history).

## 9. Build order

The structure is done when the skeleton runs; the application is done feature by
feature, in this order — each slice lands whole, with its tests:

1. **Foundation** (this document): core, config, routing, error handling. *
   Skeleton complete — no feature behaviour yet.*
2. **Identity**: session transport, OTP, permission service, audit log.
3. **Business and listing**: domain, repositories, publication gate, URL grammar.
4. **Search**: the denormalised document, ranking, sponsored separation.
5. **Reviews and reports**: submission, moderation states, append-only history.
6. **Admin console**: queues, decisions, exports.
7. **API v1**: the endpoints the surfaces above already need internally.
8. **Integrations**: mail, Telegram, maps — each behind its interface.
9. **Operations**: scheduled jobs, retention, monitoring, backups.

Steps 2–4 carry the invariants that matter most: publication refused without a
linked permission record from *every* path including import; organic results
byte-identical with and without campaigns; unknown opening hours never rendered
as "closed"; closure recorded as a state, never as a delete.

## 10. What is deliberately absent

No ORM, no template language, no service locator, no event bus, no queue broker,
no micro-framework, no second web root, no `src/` wrapper, no per-module
scaffolding. Each of those is a dependency, a failure mode and an onboarding
cost, and none of them earns its place at this size. The four runtime libraries
in `composer.json` (FastRoute, Monolog, PSR-3, phpdotenv) are the entire
third-party surface of the application.

---

## 11. Documentation reconciliation — 2026-10-11

The specification set was written against a `src/`-shaped repository that no
longer exists. On the owner's instruction — *update the documentation with this
tech and structure* — the documents that **own** a statement about layout, the
namespace map or the toolchain were reconciled with the code. Product, UX,
security, privacy and decision documents were not touched: they are
structure-agnostic and are still the requirements.

Rules applied to every edit:

1. **No requirement, threshold or security control was removed or weakened.**
   Where a control is specified but not built, it is marked **not yet in place**
   with the milestone that delivers it (IMP-4.2, IMP-13.1).
2. **Historical records were annotated, not rewritten.** A discovery report or
   audit that is edited to match a later state stops being evidence.
3. Every reconciled document carries a dated amendment note naming this file as
   the layout authority.

| Document | What changed |
| --- | --- |
| `30-technical/trd-v1.0.md` | §6 rewritten (layout, namespace map, enforced boundaries); TR-04, TR-184, TR-192, §34 SQL row and the build-gate line updated to the surface layout and the real toolchain |
| `30-technical/architecture.md` | §2.2 boundary table with real status; §3 layout and §3.1 namespace map rewritten; §4 boot sequence, §5 lifecycle, §6 routing (no route groups, constrained segments, `RouteMatch`), §6.1 route families (per-route requirements inside the public prefix), §7 middleware status, §8 clock, §9 database access, §10 migrations, §12 error handling, §13 health, §14 document root, §15 security posture, §17 "does not add" |
| `30-technical/deployment.md` | directory tree, DR-1, IN-1, CF-2, CR-6, PS-3, §11 health checks and the two troubleshooting entries |
| `30-technical/performance-and-caching.md` | RN-5 and DB-10 assertions renamed and marked M1 |
| `35-platforms/platform-architecture-v1.0.md` | PA-2.7 and PD-02 point at `Bulbula\Backend\Integrations\Telegram` plus the single client-side adapter script |
| `45-quality/implementation-plan-v1.0.md` | §4 baseline rewritten from the working tree; §5.7 outbound-HTTP warning; §12.5 gates and §12.6 definition of done; §13 documentation rules; §20 workflow; traceability rows; the `.ai/` ritual removed |
| `45-quality/test-strategy-v1.0.md` | §1 baseline (PHPUnit, real suite size, status per control); §2 level table |
| `45-quality/quality-strategy-v1.0.md` | §3.1 local gate, §3.2 CI, §3.3 thresholds, §3.4 architecture rules — each with a status |
| `45-quality/release-management-v1.0.md` | §3.1 gate |
| `45-quality/production-readiness-v1.0.md` | technical criteria 2, 3, 4, 6 and 15 |
| `50-security/application-security-v1.0.md` | §7.1 header table with real status; APP-8.9 |
| `50-security/security-architecture-v1.0.md` | SEC-5.1; T8 |
| `50-security/security-operations-v1.0.md` | SO-6.5 |
| `40-operations/backup-recovery-v1.0.md` | BR-3.4 |
| `00-discovery/project-understanding-v0.1.md` | Status note only: it is a snapshot of a state that has been rebuilt |
| `70-engineering/findings-v1.0.md` | Status note: its `src/` references describe the abandoned v1 layout |
| `70-engineering/structure-manifest.txt` | Superseded header: it is the v1 file inventory |
| `70-engineering/php-application-structure-v1.0.md` | Status line already marked it superseded; unchanged |

### 11.1 Open reconciliation items

These are the places where the code and the specification disagreed and the
resolution was a decision, not an edit. None of them was a layout question.
Resolved items stay in the table with their resolution recorded: the table is
evidence of what was decided, not a to-do list.

| # | Item | The disagreement | Recommendation |
| --- | --- | --- | --- |
| R-1 | JSON encoding flags | The spec requires `HEX_TAG\|HEX_AMP\|HEX_APOS\|HEX_QUOT` so a response embedded in HTML cannot break out of its context; the code encoded with `JSON_UNESCAPED_SLASHES\|JSON_UNESCAPED_UNICODE` | **Resolved 2026-10-11.** `Response::json()` encodes with the HEX flags plus `JSON_UNESCAPED_SLASHES\|JSON_UNESCAPED_UNICODE` and `JSON_THROW_ON_ERROR`; `tests/Unit/Backend/Core/ResponseTest.php` holds the escaping, the invalid-UTF-8 failure and the content type. The rule was kept rather than amended |
| R-2 | Health endpoints | The spec defines four (`/health`, `/health/ready` and their `/api/v1` twins) with a `pass` vocabulary; the code had one honest probe, `GET /api/v1/health`, answering `ok\|degraded` and `503` when the database is down | **Resolved 2026-10-11.** All four paths exist in `routes/health.php`, served by one controller and a `HealthChecker` in `backend/Support/Health/`. Liveness touches no dependency and always answers `200 {"status":"pass"}`; readiness reports per-check detail (`database`, `storage`) and answers `503 {"status":"fail",…}`. The single-probe design was replaced rather than documented |
| R-3 | Composition root | architecture §17 says no container and no auto-wiring; the code had a small explicit container used only at the edges | **Resolved 2026-10-11, by owner decision.** The container was **removed**, not the rule: `Bulbula\Backend\Core\ControllerFactory` holds one hand-written factory per controller, written out in `Application::boot()`, and a route naming an unwired class raises a `ConfigurationException`. `tests/Arch/BoundariesTest` fails if a service locator comes back. The absolute rule in §17 stands |
| R-4 | Quality gate | The strategy names coverage, type coverage, Infection, Rector, PHPInsights, gitleaks and CI; none was wired up | **Partly resolved 2026-10-11.** `.github/workflows/ci.yml` runs `composer validate --strict`, Pint, PHPStan max and PHPUnit on every push and pull request; `.github/workflows/security.yml` runs `composer audit --locked` and gitleaks over the full history, weekly as well as per change; `.gitleaks.toml` extends the default ruleset without weakening it. **Still open:** coverage and type coverage thresholds, Infection (`mutation.yml`), Rector and PHPInsights |
| R-5 | Architecture suite | Boundaries are documented as enforced, and the suite that enforces them did not exist | **Resolved 2026-10-11.** `tests/Arch/` holds three suites over the source tree — `BoundariesTest` (namespace confinement, SQL, `PDO`, `curl`, superglobals, entry points), `ConventionsTest` (strict types, finality, naming, templates, the document root) and `DependenciesTest` (the four runtime dependencies, the namespace map, the gate scripts) — sharing `tests/Support/SourceFiles.php`. Every boundary a slice creates is guarded in the change that creates it |

### 11.2 Second pass — R-1, R-2, R-4, R-5 built (2026-10-11)

The first pass reconciled documents with code. This pass closed four of the five
items the first pass had flagged by changing the **code** rather than the
documents, and settled the fifth on the owner's instruction in the same
session — the container was removed rather than the rule narrowed:

| Item | Change | Where |
| --- | --- | --- |
| R-1 | `Response::json()` takes the HEX flag set and `JSON_THROW_ON_ERROR` | `backend/Core/Response.php`; `tests/Unit/Backend/Core/ResponseTest.php` |
| R-2 | Four probe paths, split liveness from readiness, per-check detail, `no-store` | `backend/Support/Health/`, `api/Controllers/V1/HealthController.php`, `routes/health.php` |
| R-4 | CI and the secret scan, as specified; the remaining tools still open | `.github/workflows/ci.yml`, `.github/workflows/security.yml`, `.gitleaks.toml` |
| R-5 | The architecture suite, which had been documented as enforced since the first pass | `tests/Arch/`, `tests/Support/SourceFiles.php` |
| R-3 | The container was **removed**: `ControllerFactory` holds one hand-written factory per controller, listed in `Application::boot()`; an unwired class fails loudly | `backend/Core/ControllerFactory.php`; `tests/Unit/Backend/Core/ControllerFactoryTest.php`; `tests/Arch/BoundariesTest::there_is_no_service_locator` |

The documents that describe these behaviours were updated in the same pass, so
the specification set and the working tree agree again:

| Document | What changed |
| --- | --- |
| `30-technical/architecture.md` | §2.2 boundary table now names the three suites; §3 tree; §13 health contract; §16 JSON encoding (deviation closed) |
| `30-technical/deployment.md` | §11 health table and smoke test, the two probe troubleshooting entries, CF-2, CR-6, PS-3, SC-1 |
| `30-technical/trd-v1.0.md` | §6 test tree, G-3, §34 SQL and Secrets rows |
| `45-quality/implementation-plan-v1.0.md` | §4.2 code present, §4.3 quality baseline, §6.2 APIs, §12.5 gates |
| `45-quality/test-strategy-v1.0.md` | §1 baseline (size, suites), §2 level table |
| `45-quality/quality-strategy-v1.0.md` | §3.2 workflow status, §3.4 architecture rules, §9 traceability |
| `45-quality/release-management-v1.0.md` | §3.2 what runs on the pull request |
| `45-quality/production-readiness-v1.0.md` | criteria 2, 3, 4, 6, 15 |
| `50-security/application-security-v1.0.md` | APP-8.9 |
| `50-security/security-architecture-v1.0.md` | SEC-5.1 |

One item in §11.1 is only partly closed and stays visible: the second half of
R-4, because coverage and mutation thresholds are a milestone's work rather than
an afternoon's. Everything else in the table is built.

Two defects surfaced while closing these items, and both were fixed rather than
noted. `LoggerFactory` passed a level *name* to Monolog's `Level::tryFrom()`,
which takes an int — a latent fatal that only appeared when the composition root
began building the logger at boot; the mapping is now written out, and
`tests/Unit/Backend/Support/LoggerFactoryTest.php` covers it. The same factory
trusted Monolog to fail on an unwritable log path, but Monolog opens its stream
lazily, so the failure would have arrived on the first log line of the incident
the logs were meant to record; the path is checked at boot, which is what
`deployment.md` §11 (PM-3) always claimed.

### 11.3 Third pass — the Identity slice's spine (2026-10-11)

The first product slice began with the parts every later table depends on: the
clock, the migration machinery, the console and the audit writer. None of them
is product-specific, which is exactly why they came first — M1 items 2, 11 and
12 of `implementation-plan-v1.0.md`.

| Built | Where | Traces to |
| --- | --- | --- |
| `Clock` / `SystemClock` — the only source of "now", always UTC, injected | `backend/Support/{Clock,SystemClock}.php` | TR-05, STR-3 |
| Migration machinery: `Migration`, `MigrationLocator`, `MigrationRepository`, `Migrator` | `backend/Database/Migrations/` | TR-173, TR-175, TR-181; architecture §10 |
| `migrate`, `migration:status`, `rollback [--steps=n]`, wired through the composition root | `backend/Console/`, `routes/console.php`, `bin/console` | architecture §10, TD-06 |
| The audit trail: its table, an append-only repository, and a recorder that fails the action it could not record | `database/migrations/2026_10_11_120000_create_audit_entries.php`, `backend/Domain/Platform/`, `backend/Repositories/Platform/`, `backend/Services/Platform/` | C-29, TR-08, TR-167, OM-6.1–6.3 |
| `ActorType` — Guest, Customer, Operator, Administrator, and `System` (owner decision, 2026-10-11) | `backend/Domain/Platform/ActorType.php` | OM §3, TR-40, D-54 |

`Platform` was checked against §4.1 before the first file was written: audit,
analytics, notifications, jobs and cache are its declared territory, so the
audit trail belongs there and nowhere else.

New assertions arrive with the code they guard, as §2.2 requires: the audit
trail is append-only (`UPDATE` or `DELETE` against `audit_entries` fails the
build, while the migration that owns the table is exempt), DDL is confined to
`backend/Database`, `backend/Repositories` and `database/migrations`, and
`database/migrations/` is scanned as source. That pass left the suite at 96
tests and 2,474 assertions, with Pint, PHPStan at level max and PHPUnit green
together.

One thing the slice refused to decide, and one the owner then decided:

- **Attribution for platform-initiated actions — decided by the owner,
  2026-10-11: a `System` actor.** OM §3's prose says "exactly three platform
  actors. There is no fourth." over a table that lists four plus a Business
  owner marked *not a platform actor* (D-54); a scheduled job is none of them
  either. Faced with recording a machine's action as a named Administrator,
  recording it as nothing, or recording it as the machine it was, the owner
  chose the third. `ActorType::System` is therefore an owner decision, not an
  invention: it is never authenticated, never carries an actor id, and the
  check that enforces that is in `AuditRecorder`. The ambiguous sentence in
  OM §3 was then **corrected on the owner's instruction (2026-10-11)** — the
  three-actor rule is the glossary's (Guest, Customer, Staff), the section now
  says so, and the System row appears in its table. The same one-line
  clarification was carried to `glossary.md` §1 and `prd-v1.0.md` §8, which
  state the rule in the same form.
- **The retention period for audit entries**, which is `PENDING COUNSEL`
  (L-21, D-46) and therefore never invented in code.

This pass also corrected two defects it found in the documents:

| Defect | Correction |
| --- | --- |
| §4's migration example, `2026_10_11_0001_create_listings.sql`, contradicted architecture §10 and the locator: migrations are `.php` and named `YYYY_MM_DD_HHMMSS_description` | the example now shows the migration that exists |
| `30-technical/architecture.md` §10 described a `Migration` base class to extend; the contract is an interface, which is what was built | recorded in §10 as a deliberate deviation, with the reason |

### 11.4 Fourth pass — the Identity slice: sessions and sign-in codes (2026-10-11)

The rest of the identity spine, built on the machinery §11.3 delivered. Sources
of truth are `auth-identity.md` §4.2 and §6 and `data-model.md` §5.1–5.4; the
rules are cited per file rather than restated here.

| Built | Where | Traces to |
| --- | --- | --- |
| Five tables: `customers`, `provider_identities`, `otp_requests`, `sessions`, `rate_limits` | `database/migrations/2026_10_11_1300*` | data-model §5.1–5.4, TD-02, TR-167 |
| `Email`, `Customer`, `OtpChallenge`, `Session`, `IssuedSession`, `PrincipalType`, `Surface`, `CustomerState` | `backend/Domain/Identity/` | STR-3, LK-2, LK-3 |
| `CustomerRepository`, `OtpRequestRepository`, `SessionRepository`, `RateLimitRepository` | `backend/Repositories/{Identity,Platform}/` | TR-16 (table ownership), DB-10 |
| `RateLimiter` | `backend/Security/` | TRD §34, OTP-6 |
| `RequestOtpService`, `VerifyOtpService`, `SessionService`, `OtpSender` | `backend/Services/Identity/` | C-31, C-30, TD-02, OTP-1…10, S-1…8 |
| `Row` (typed column reads), `DatabaseTime` (the one datetime format) | `backend/Database/` | level-max static analysis, TR-05 |

**Three things this pass had to decide, and how it decided them:**

- **OTP policy numbers are TRD OT-02 — Open.** The mechanisms are fixed by the
  spec; the length, window, attempt ceiling and cooldown are not. They are
  therefore *configuration* (`config/security.php` → `security.otp`, and
  `OTP_*` in `.env.example`) with provisional defaults inside the cited
  standard's bounds, not constants. Confirming them in the security phase is a
  configuration change; no code moves.
- **Session lifetimes are TRD OT-01 — Open.** Same treatment: `SESSION_LIFETIME`
  (inactivity) and a new `SESSION_ABSOLUTE_LIFETIME` (the cap S-6 also
  requires), both provisional, both configuration.
- **`rate_limits` lives in Platform, not Identity.** It is cross-cutting
  operational state — the same table serves the review, report and search
  limits — and Platform already owns cache, jobs and analytics. Recorded here
  because area placement is a structure decision.

**What is deliberately not built:** sign-in over HTTP. There are no routes, no
login page and no cookie handling yet, so the services are reachable only from
tests and the console — and `UndeliverableOtpSender` refuses to pretend a code
was sent, because the only alternative available today is writing it to a log,
which OTP-10 forbids. The mail adapter (`Backend\Integrations`, TD-07) and the
web-facing half of this flow are the next slice.

This pass left the suite at 143 tests and 3,967 assertions.

### 11.5 Fifth pass — sign-in on the wire: the web journey, the API twin and the mail seam (2026-10-11)

The fifth pass puts §11.4's services on the wire. Sources of truth are
`api-spec-v1.0.md` §1.5–1.6 and §3.1, `auth-identity.md` §6 and `security.md`
(the CSRF and session rules); rules are cited per file rather than restated
here.

| Built | Where | Traces to |
| --- | --- | --- |
| `Cookie` (hardened defaults, `forget()`) | `backend/Core/` | CS-5, S-5 |
| `Request`: `cookies()`, `cookie()`, `bearerToken()`; `Response`: `list<Cookie>`, one `Set-Cookie` per cookie with replace `false` | `backend/Core/` | TD-02, CS-3 |
| `Csrf` (double-submit before sign-in, session-bound after), `RateLimitDecision`, `CsrfTokenMismatchException` (419), `AuthenticationRequiredException` (401) | `backend/Security/`, `backend/Exceptions/` | CS-1…CS-5, E-6 |
| `Message`, `Mailer`, `LocalFileMailer`, `MailOtpSender` | `backend/Mail/`, `backend/Services/Identity/` | TD-07, OTP-1, OTP-10 |
| `SignInController` + `pages/sign-in.php` (two stages, no JavaScript) | `frontend/` | C-30, C-31 |
| `AuthController` (`requestCode`, `verify`, `logout`) | `api/Controllers/V1/` | api-spec §3.1 |
| `GET /sign-in`, `POST /sign-in/{code,verify,sign-out}`, `POST /api/v1/auth/otp/{request,verify}`, `POST /api/v1/auth/logout` | `routes/web.php`, `routes/api.php` | api-spec §1.5–1.6 |
| `2026_10_11_140000_add_csrf_hash_to_sessions` | `database/migrations/` | CS-2, K-6 |

**What this pass had to decide, and how it decided it:**

- **The mail seam is a port, not an integration.** `backend/Mail/` holds the
  outbound port (`Mailer`) and the one transport that needs no credentials
  (`LocalFileMailer`, refused unless `APP_ENV=local`). A provider adapter would
  implement the same interface; D-41 is still open, so none is built. Placing
  the port here rather than under a named provider keeps TD-07's single outbound
  namespace intact without inventing a provider this pass has no decision for.
- **An unconfigured deployment must refuse, not log.** `MAIL_TRANSPORT` names
  the transport; any value that is not `local` selects `UndeliverableOtpSender`,
  so a half-configured environment returns its uniform answer and sends nothing,
  rather than writing a live sign-in code into a log (OTP-10).
- **API auth never falls back to cookies.** `AuthController` reads the bearer
  token only; a session cookie presented to the API is not a token (CS-3). This
  makes TD-02's "one session, two transports" explicit in code.
- **No account enumeration, on either surface.** The web stage answers the same
  whether or not the address exists; the API returns `202 {"status":"sent"}`
  unconditionally. The only difference is in the log.

**Defect found and fixed while testing this pass:** the feature-test harness
booted the application *outside* the environment window that a test declares,
so both feature classes ran against the development database and its
accumulated rate-limit rows — first requests returned 429 and "no message was
delivered" cascaded from there. `ApplicationTestCase::boot()` now performs
`boot()` inside that window, because `.env` and `config/*.php` are read during
boot. This is recorded here because it was a harness property, not a product
bug: the production code was behaving correctly.

**What is deliberately not built:** `/auth/google` (needs the Google adapter),
`/auth/session` (capabilities — waits on TD-03), `/auth/telegram/context`
(D-33 open; AU-4/AU-5), the TD-03 permission check itself, and staff sign-in
(blocked by D-45). No linking behaviour was invented (D-13).

This pass left the suite at 162 tests and 4,768 assertions.

### 11.6 Sixth pass — Google sign-in, and D-13 answered (2026-10-11)

The sixth pass adds the second sign-in method and closes the last open
identity-linking decision. Sources of truth are `auth-identity.md` §4.1 (GA-1…GA-6)
and §5.1–5.3 (LK-1…LK-8, collisions), `api-spec-v1.0.md` §3.1, `trd-v1.0.md`
(D-13, D-48, TR-32/104/110/111) and Google's own published verification guide —
Google is the only external party in the product so far whose rules are not ours
to set, so they are cited by URL in the integration rather than paraphrased.

| Built | Where | Traces to |
| --- | --- | --- |
| `config/google.php` (`client_ids` from `GOOGLE_CLIENT_IDS`, `issuers`, `jwks_url`, `keys_ttl`, `keys_cache`, `max_token_age`, `clock_leeway`) | `config/` | GA-2, TR-110 |
| `GoogleKeySet` (disk cache under `storage/tmp/keys/`, JWK `n`/`e` → PEM via hand-rolled DER), `JsonFetcher`/`StreamJsonFetcher` | `backend/Integrations/Google/` | GA-1, GA-6 |
| `GoogleTokenVerifier`, `GoogleIdToken` | `backend/Integrations/Google/` | GA-2…GA-5 |
| `GoogleSignInService`, `GoogleSignInResult`, `PendingLink`, `ProviderCredentialRejectedException` | `backend/Services/Identity/`, `backend/Domain/Identity/`, `backend/Exceptions/` | §5.1, §5.3 |
| `purpose` + pending-identity columns on `otp_requests`; link completion in `VerifyOtpService` | `database/migrations/2026_10_11_150000_…`, `backend/Services/Identity/` | LK-6 |
| `POST /sign-in/google`, Google button on `pages/sign-in.php` | `frontend/`, `routes/web.php` | C-30, C-31 |
| `POST /api/v1/auth/google` (201 / 202 `confirm_required` / 401) | `api/Controllers/V1/`, `routes/api.php` | api-spec §3.1 |

**What this pass had to decide, and how it decided it:**

- **The verifier checks the signature before it reads a single claim.** A
  payload is unauthenticated bytes until the signature over it is Google's; the
  order in `GoogleTokenVerifier::verify()` is therefore signature, issuer,
  audience, time, then `email_verified`. `alg` is read from the token, so the
  accepted set is an allow-list of exactly `RS256` — `none` and the HMAC
  confusion class cannot be selected by the attacker.
- **The audience is a list, because the product has more than one client.**
  "Mobile" means Mini App, mobile browser and a native app (owner, 2026-10-11),
  and each may have its own client id. `GOOGLE_CLIENT_IDS` is a comma-separated
  list checked with `in_array(..., true)`; adding an iOS client is configuration,
  not a code change, and "any Google token" is never accepted.
- **The link intent lives on the code, not in a new table.** A pending Google
  identity is stored on the `otp_requests` row that carries it (`purpose`,
  pending provider/subject/address): one code path, one set of rate limits, one
  expiry rule, and no way for the link flow to become a weaker second way into
  an account than ordinary sign-in. LK-1…LK-5 and §5.3 are untouched — nothing
  merges Customers, and no Saves or Reviews move.
- **Google's cross-site token is checked only when Google sends one.** The
  HTML API's `g_csrf_token` cookie/body pair is enforced when the cookie is
  present; a request that arrives without it is still authenticated by the ID
  token itself, which is the actual credential. This keeps the manual/API path
  and the button path on the same rule.
- **An unconfigured deployment shows no button at all.** With no client id the
  sign-in page renders email codes alone rather than a control that cannot work
  — the same reasoning as §11.5's `UndeliverableOtpSender`: a half-configured
  environment behaves as if the feature were not there, and says so in the log.

**Follow-up within the pass (2026-10-11, after the first deployment was named):**
the button's `data-login_uri` was built from a request-relative path. Google
compares that string with the client's authorised redirect URIs character for
character and the console accepts absolute URIs only, so a relative value would
have produced `redirect_uri_mismatch` as soon as the app ran anywhere but the
root of a domain. It is now `config('app.url') . '/sign-in/google'` — the one
place a deployment's own address is written down (`APP_URL`), which makes a
domain move an `.env` change rather than a code change, and the web test pins
`APP_URL` and asserts the absolute value in the page (GA-1, TR-04).

**Second follow-up within the pass (2026-10-11, CI caught it):** the local file
mail spool named its files with second precision plus a random suffix, so two
codes issued inside one second shared a timestamp to the second and "the newest
message" became a coin toss between them — measured with the real reader
comparator at 101/200. The name now carries microseconds, which makes
lexicographic order chronological (measured 200/200), and the API link-journey
test snapshots what was delivered before the Google call so it reads the link
code by identity rather than by luck. This was a harness/dev-transport defect,
not a product defect: the 422 CI reported was the correct refusal of an
already-consumed code.

**Defect found and fixed while testing this pass:** the hand-rolled JWK → PEM
conversion was wrong twice — the `AlgorithmIdentifier` was double-wrapped in a
SEQUENCE, and the RSA key was appended to the SubjectPublicKeyInfo body instead
of being placed inside a BIT STRING. Both produced the same symptom
(`openssl_verify(): Supplied key param cannot be coerced into a public key`).
These were real product defects that a mock-based test would have missed, and
they are the argument for the pass having generated a real key pair: signature
verification is exercised as cryptography, offline.

**What is deliberately not built:** removal of a sign-in method and the
profile's sign-in-methods screen (LK-7/LK-8 decided 2026-10-11, not in this
slice), `POST /auth/telegram/context` (D-33), `/auth/session` (TD-03), staff
sign-in (D-45). No legacy Telegram login widget: Telegram remains a surface
signal only (TR-32, AI-4, D-48).

This pass left the suite at 183 tests and 5,493 assertions.

### 11.7 Seventh pass — what only a real host could show (2026-10-11)

The first session on the development host produced two defects that no local run
had exposed, both fixed in `9b81d25`. Sources of truth are the deployment model
in `project-understanding-v0.1.md` (*"Cloudflare in front"*), `deployment.md`
§5.4 (new) and the identity rules the rate limits come from (OTP-6, AX-1).

| Built | Where | Why it exists |
| --- | --- | --- |
| `CommandRunner` reports a throwing command and returns 1 | `backend/Console/` | `display_errors` is switched off outside development — right for a response, wrong for a terminal. A console that prints nothing is indistinguishable from one that succeeded |
| `bin/console` reports a **boot** failure | `bin/` | Boot happens before any command exists; an unreadable `.env` or an unwritable log directory must still be visible |
| `ClientAddress` (trusted-proxy resolution) | `backend/Core/` | With Cloudflare in front the peer is the edge: every visitor shared one address, so the per-network limits (OTP-6, AX-1) covered everybody at once and the audit record would have named the proxy |
| `TRUSTED_PROXIES` | `config/security.php`, `.env.example` | Trust is a deployment fact, so it is configuration. Empty — the default — believes nobody |
| §5.4, rules PX-1…PX-6 | `deployment.md` | States the trust rule, and that edge caching must never cover a response carrying a session |

**The decision this pass had to make:** whether to trust a forwarded address at
all. The answer is no, not by default — a header is a claim, not a fact, and only
the *sender's* address can make it worth reading. Honouring `X-Forwarded-For`
unconditionally would have been the convenient reading of "Cloudflare is in
front", and it is exactly the reading that turns a rate limit into a suggestion.
The same reasoning decides the walk direction: right to left, because a proxy
appends what it saw and anything to the left may have been typed by the client.

**Also recorded here:** `ApplicationTestCase` restored an error handler for every
test, including tests that never booted the application — restoring one that was
never installed removes PHPUnit's own handler, which is reported as a risky test.
It now counts what it installed.

This pass left the suite at 214 tests and 5,612 assertions.

**Addendum, same session — what the live host confirmed, and the last gap it
left.** The owner's session on the development host settled three things and
exposed one more: the document root is `public/` and the console now reports
failures; PHP 8.4 is selected per domain in MultiPHP Manager and cPanel writes
**no handler block** into `public/.htaccess` (so `deployment.md` §3.1's warning is
settled, and the interim `--skip-worktree` withdrawal is recorded there); and the
eight shipped migrations applied. The remaining gap was the reverse of the
silence already fixed: `.env` is optional by design and `safeLoad()` reads it
only when it exists — correct, but a host with **no** `.env` then ran on defaults
without saying so, which is how an edit to `.env.example` (a document, never
read) can look like a configuration change that does nothing. `Env` now records
whether it found the file, `Application::boot()` warns in the log, and
`bin/console` says it on the terminal where an operator is standing — with a test
that pins the rule that the example is never read as settings.

**Correction, 2026-10-13 — the two one-line files, and what the host actually
applies.** The addendum above says the live host writes **no** handler block
into `public/.htaccess`. The live host does apply one: the account's
`public_html/.htaccess` pins `ea-php82` for every directory beneath it, and the
served file must name `ea-php84` to win — it now does, committed, with the
account in `deployment.md` §3.1. The same diagnosis found the mirror-image
fault: the repository-root guard's `Require all denied` was enforced against
the whole, correctly configured site, because LiteSpeed also applies the
`.htaccess` of the document root's **parent** directories and this repository
root is the parent of `public/`. The guard is now rewrite-only, every rule
gated on the request resolving to a real file or directory
(`deployment.md` §3.2, DR-6). Both faults lived in one-line files and both were
settled by **editing on the server and watching the answer change** — not by
reading the file and reasoning about it. That is the method this pass adds to
the record: a configuration belief is verified by an in-place edit plus a
request, and the deployment cron (§7.1) means the durable edit is a commit.

---

**Addendum, 2026-10-13 — Business and listing: the domain, the gate, the URL
grammar (build order §9 step 3).** The slice's foundation landed with its
tests, on the branch `directory/business-listing`:

- **Schema** — eight migrations on 2026-10-13: `sub_cities`, `areas`,
  `categories` (one self-referencing table, exactly two levels — D-06),
  `businesses`, `business_categories` (`is_primary`; `UNIQUE (business_id,
  category_id)` — D-57), `branches`, `permission_records`, `redirects`.
- **Domain** — `Domain/Directory/` (`Slug`, `BusinessState`, `BranchState`,
  `Business`, `Branch`, `Category`, `Area`, `SubCity`) and
  `Domain/Operations/` (`PermissionMethod`, `PermissionState`,
  `PermissionRecord`). `Slug` is the URL grammar in one class: lowercase,
  hyphenated, 1–80 characters; a display name that folds to nothing (an
  Amharic-only name today) is refused rather than guessed, so Amharic URLs
  later arrive by widening one class and one route constraint (IAR-6).
- **Repositories** — `Repositories/Directory/` owns the Business, Branch,
  Category, Area and Sub-city tables; `Repositories/Operations/` owns the
  permission records; `Repositories/Platform/RedirectRepository.php` owns the
  redirects (TR-16 — table ownership per area).
- **The publication gate** — `Services/Directory/PublicationGate.php` answers
  *what is missing* for the model's required set plus the permission record,
  and `PublishListingService` refuses through it **before any state changes**:
  no publication without a linked Permission record, from every path (TR-49),
  with the change and its audit entry in one transaction (TR-08). The gate
  reads the **most recent** permission record, so a withdrawal stops
  publication (PRD TS-5) and a fresh grant restores it.
- **The write side** — `BusinessService`: creation with duplicate candidates
  **surfaced, not refused** (exact normalised name, public email or branch
  phone — TR-55); versioned edits that refuse a stale token (TR-57); slug
  changes that record the redirect in the same transaction (IAR-3); the first
  Branch is the primary one (D-03); classification that demotes the previous
  primary (D-57); closure as a state with a reason, never a delete (TR-53);
  every change audited as `listing.*`.
- **Tests** — 221 → **253** (5,633 → 7,381 assertions). `DirectoryScenario`
  seeds through the repositories, so no test asserts against a schema the
  application cannot write.

Deliberately deferred, each with its reason: the **Landmark** entity (§3.7 —
its own migration when the profile page needs it, rather than a branch text
column that would be a different design); the **rating summary** columns with
their writer (TR-62/63 arrive with Reviews); **Aliases** (Search slice);
**opening hours** (D-04 open); **media** attachments (media slice);
**verification records** (the gate needs permission, not verification, and
verification state is derived — §4.2).

One open reading is recorded rather than invented: **D-57 does not distinguish
taxonomy levels** ("exactly one primary Category"), so any Category may be
primary or secondary; and the duplicate signal deliberately excludes
area-alone, which every business in an area shares.

**Update, 2026-10-13 — the read path landed, and the slice is complete.**
`/b/{business}` serves the profile (a moved slug answers 301 to where the
redirect table says it went — IAR-3; everything not published answers the same
404); `GET /api/v1/businesses/{slug}` serves the same record to machines under
the api-spec §2.3 rules (PR-1…PR-6), with a content-derived `ETag` and public
cache headers (§1.8). `PublicListingService` is the one object that decides
what a stranger may see; both surfaces render it and neither queries.

Two defects that the SQLite-only suite **could not show** were found by running
the write path and the read path against a real MariaDB before the merge:
every INSERT and UPDATE bound `:now` twice, which MySQL's native prepares
refuse with `HY093`, and the duplicate-detection query did the same with
`:phone`. Both are fixed — one stamp, two parameters — and
`tests/Arch/BoundariesTest` now refuses a named parameter bound twice anywhere
under `backend/`, so the class of defect cannot return. Worth remembering when
reading the test-strategy table: the suite is a filter, not a proof, for engine
differences.

---

*Companion records: `php-application-structure-v1.0.md` (superseded; its feature
inventory, routes, migration plan and build order remain the source for content),
`findings-v1.0.md` (document defects found while reading the specification set),
`structure-manifest.txt` (the v1 file inventory).*
