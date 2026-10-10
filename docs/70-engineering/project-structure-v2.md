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
│   ├── .htaccess               rewrite + security headers + deny-list
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
| `ActorType` — Guest, Customer, Operator, Administrator | `backend/Domain/Platform/ActorType.php` | OM §3, TR-40, D-54 |

`Platform` was checked against §4.1 before the first file was written: audit,
analytics, notifications, jobs and cache are its declared territory, so the
audit trail belongs there and nowhere else.

New assertions arrive with the code they guard, as §2.2 requires: the audit
trail is append-only (`UPDATE` or `DELETE` against `audit_entries` fails the
build, while the migration that owns the table is exempt), DDL is confined to
`backend/Database`, `backend/Repositories` and `database/migrations`, and
`database/migrations/` is scanned as source. The suite stands at 90 tests and
2,458 assertions; Pint, PHPStan at level max and PHPUnit are green together.

Two things the slice refused to decide, because the specification does not:

- **Attribution for platform-initiated actions.** OM §3 names four actors, and
  a scheduled job is none of them — while the same document's prose says "there
  is no fourth" over a table that lists four plus a Business owner marked *not
  a platform actor* (D-54). The contradiction is real and is reported rather
  than smoothed over: whether a job records as a system actor, as the
  Administrator who scheduled it, or not at all is an owner question, so no
  fifth `ActorType` case was invented.
- **The retention period for audit entries**, which is `PENDING COUNSEL`
  (L-21, D-46) and therefore never invented in code.

This pass also corrected two defects it found in the documents:

| Defect | Correction |
| --- | --- |
| §4's migration example, `2026_10_11_0001_create_listings.sql`, contradicted architecture §10 and the locator: migrations are `.php` and named `YYYY_MM_DD_HHMMSS_description` | the example now shows the migration that exists |
| `30-technical/architecture.md` §10 described a `Migration` base class to extend; the contract is an interface, which is what was built | recorded in §10 as a deliberate deviation, with the reason |

---

*Companion records: `php-application-structure-v1.0.md` (superseded; its feature
inventory, routes, migration plan and build order remain the source for content),
`findings-v1.0.md` (document defects found while reading the specification set),
`structure-manifest.txt` (the v1 file inventory).*
