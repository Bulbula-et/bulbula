# PHP Application Structure — Bulbula V1

## 1. Document control

| | |
| --- | --- |
| **Document** | PHP Application Structure — Bulbula V1 |
| **Version** | v1.0 |
| **Status** | **Superseded by [`project-structure-v2.md`](project-structure-v2.md)** (2026-10-11). Retained as the record of the docs-faithful design; the active structure is v2 |
| **Date** | 2026-10-11 |
| **Owner** | Project owner (Bulbula) |
| **Supersedes** | — |
| **Companion** | [`findings-v1.0.md`](findings-v1.0.md) · [`structure-manifest.txt`](structure-manifest.txt) · [`../../tools/scaffold-structure.sh`](../../tools/scaffold-structure.sh) |
| **Authority** | Below every formal document, alongside [`../45-quality/implementation-plan-v1.0.md`](../45-quality/implementation-plan-v1.0.md), above code |

### 1.1 What this document is

The **material structure** of the Bulbula V1 codebase: every directory, every
namespace, every planned file, the rule each one exists to satisfy, and the
dependency directions that must not be crossed.

The formal documents specify *what the system must be*. The implementation
plan specifies *the order in which it is built*. This document specifies
**where the code lives** — the part neither of the others states in one place.

### 1.2 What this document is not

| Not this | Because |
| --- | --- |
| A requirements document | IMP-0.2. It states no requirement; every row traces to one |
| A decision record | IMP-0.3. Where it proposes a technical choice it is marked **PT-xx** and requires recording in the TRD or the register before it binds |
| A schedule | IMP-0.4. Milestones are named, never dated |
| SQL or PHP | It names files, not their contents. Column types appear only where §30–§31 of the TRD already fixed a convention |
| A scaffold to commit wholesale | §18 — `architecture.md` §3 forbids creating directories in advance |

### 1.3 Reading conventions

| Marker | Meaning |
| --- | --- |
| **[C]** | Confirmed — stated by an approved decision or a formal document |
| **[P]** | Proposal of this document; requires approval before it binds |
| **[U]** | Unknown — named so it can be answered, not guessed |
| **[X]** | Conflict between formal documents, reported not resolved |
| **TR-xxx / TD-xx / D-xx / C-xx** | As defined in the TRD, the register and the PRD |
| **STR-xx** | A structural rule of this document |
| **PT-xx** | A proposed technical decision; must be recorded in the TRD (A-2) if accepted |
| **FND-xx** | A finding in [`findings-v1.0.md`](findings-v1.0.md) |
| **F / M1…M9** | The milestone that introduces the file, per implementation-plan §10.1 |

---

## 2. Method and sources

Read in full, in this order, before any structure was proposed:

| Document | What it fixed for this design |
| --- | --- |
| [`60-decisions/decision-register.md`](../60-decisions/decision-register.md) | What is settled (23 Approved) and what is not |
| [`10-product/scope-v1.md`](../10-product/scope-v1.md), [`glossary.md`](../10-product/glossary.md) | The 40 capabilities, the 40 exclusions, and the canonical vocabulary the code must use |
| [`30-technical/trd-v1.0.md`](../30-technical/trd-v1.0.md) | 223 technical requirements, 7 technical decisions, §6 the Phase 1 contract, §10 the boundary rules, §17–§23 the area requirements |
| [`30-technical/architecture.md`](../30-technical/architecture.md) | The canonical component map, the namespace inventory, the 18 enforced architecture assertions |
| [`30-technical/data-model.md`](../30-technical/data-model.md) | Entities, ownership, classification, and §10 the entities deliberately not created |
| [`30-technical/api-spec-v1.0.md`](../30-technical/api-spec-v1.0.md) | The `/api/v1` contract, endpoint families, envelopes, pagination, caching |
| [`30-technical/auth-identity.md`](../30-technical/auth-identity.md) | Principals, session duality, OTP mechanics, staff separation |
| [`30-technical/search-design.md`](../30-technical/search-design.md) | The search document, normalisation, match stages, ranking inputs, sponsored separation |
| [`30-technical/deployment.md`](../30-technical/deployment.md) | Document root, `.htaccess` behaviour, configuration, migrations, cron, permissions |
| [`20-ux-ui/information-architecture-v1.0.md`](../20-ux-ui/information-architecture-v1.0.md), [`user-flows-v1.0.md`](../20-ux-ui/user-flows-v1.0.md) | The complete public URL grammar and every flow the code must serve |
| [`20-ux-ui/component-spec-v1.0.md`](../20-ux-ui/component-spec-v1.0.md), [`operations-console-ux-v1.0.md`](../20-ux-ui/operations-console-ux-v1.0.md) | The 44 components and the console structure |
| [`35-platforms/platform-architecture-v1.0.md`](../35-platforms/platform-architecture-v1.0.md), [`telegram-mini-app-v1.0.md`](../35-platforms/telegram-mini-app-v1.0.md) | The surface adapter boundary and its closed inventory |
| [`45-quality/implementation-plan-v1.0.md`](../45-quality/implementation-plan-v1.0.md), [`test-strategy-v1.0.md`](../45-quality/test-strategy-v1.0.md) | The milestone order, the dependency graph, the 20 invariants |
| [`10-product/interaction-permissions.md`](../10-product/interaction-permissions.md) | Actors, access classes, and the named-permission model |

**One structural conclusion emerged from reading them together.** The
specification set already fixes *almost everything* a file layout needs — the
document root, the namespace map, the layer names, the route families, the
component inventory, the invariant list. This document is therefore mostly
**assembly and reconciliation**, not invention. Where it invents, it says so.

---

## 3. The state of the repository — read this first

`main` at `4c44b50` contains **71 tracked files, all under `docs/`, and one
commit**. The specification set describes a repository that also contains a
49-file `src/`, a 49-file `tests/`, `composer.json`, `.ai/`, `tools/`,
`.github/workflows/` and a `.env.example`.

| Fact | Evidence | Consequence for this design |
| --- | --- | --- |
| The Phase 1 foundation is **absent** | TRD §6.2/§6.3, architecture §3 take a working `src/` as given; `main` has none | Every file this document marks **F / F?** must be **recovered or rebuilt** before the first V1 slice. The consequence is stated in FND-01 |
| `.ai/` is **absent** | implementation-plan §3 and §20.1 require reading it before coding; audit §27 describes 12 files | The documented pre-coding ritual cannot be performed. FND-03 |
| `tools/` and CI are **absent** | implementation-plan §4.2 lists four Python verifiers; audit F-06 says they were committed | Any file added under `docs/` — **including this one** — may violate a verifier whose rules cannot be read here. FND-04 |
| The documented baseline numbers describe **code that is not in this tree** | implementation-plan §4.3: 424 tests, 1,033 assertions, 100 % coverage, 18 architecture assertions | The quality floor in IMP-4.1 cannot be verified from `main`. FND-02 |

**How this document responds.** It is written so that it is correct **either
way**: the namespace map, the boundaries, the assertions and the route map are
identical whether the foundation is restored or rebuilt. What changes is *who
writes* the 42 files marked `F?`. Nothing in the design depends on
recovering the original text.

---

## 4. Constraints that determine the structure

Every row is a binding constraint with a structural consequence. This table is
the justification for the entire layout; anything in §5–§17 that is not
traceable to a row here is marked **[P]** and is a proposal.

### 4.1 Language, libraries, runtime

| # | Constraint | Source | Structural consequence |
| --- | --- | --- | --- |
| 1 | Framework-free plain PHP; no Laravel, Symfony full-stack, Laminas, Mezzio, Slim, Flight | NG-1, architecture §1 | No `app/`, no `bootstrap/`, no framework skeleton. `src/` is hand-composed |
| 2 | Exactly four runtime libraries: `nikic/fast-route`, `monolog/monolog`, `psr/log`, `vlucas/phpdotenv` | TRD §6.1, deployment §2 | No HTTP-client library, no template library, no validation library in `require` |
| 3 | PSR-4, `Bulbula\` → `src/` | TRD §6.1 | One directory per namespace, rooted at `src/` |
| 4 | PHP `^8.4`, `ext-pdo`, `ext-pdo_mysql` | TRD §6.1 | `composer.json` platform; the cPanel handler block lives in `public/.htaccess` |
| 5 | No ORM, no DBAL, no query builder, no Active Record | NG-7, TR-164 | `Repository/` per aggregate, issuing SQL text through `Connection` |
| 6 | `PDO`, `PDOStatement`, `PDOException`, `mysqli`, `curl_init` only inside `Bulbula\Database` | ArchTest (§6.4) | `PDO` stays in `src/Database`. `curl_init` **must move** — see row 7 |
| 7 | Exactly one namespace owns outbound HTTP and is named in the architecture test | TD-07, TR-01a/b | A new leaf namespace, `src/Outbound`, becomes the only holder of `curl_init` |
| 8 | No outbound HTTP in any page-render path | TR-01d | `Http\Controller` may not reference `Outbound` (assertion 32). Google verification and email delivery are reached from services **and** executed from queue/console |
| 9 | `bin/console` is the only file permitted to call `exit()` | CR-6, ArchTest | All CLI behaviour sits behind `Console\CommandRunner` |
| 10 | No `dd`, `dump`, `die`, `var_dump`, `sleep` anywhere in `src/` | ArchTest | No debug helpers exist anywhere in the namespace map |

### 4.2 Layering and boundaries

| # | Constraint | Source | Structural consequence |
| --- | --- | --- | --- |
| 11 | HTTP → controller → application service → domain → repository | architecture §2, P-3 | Five layers, one direction. The folder names make the layer visible in every import |
| 12 | A controller contains no SQL, no business rules, no HTML construction | TR-03 | `Http/Controller/**` holds only input reading, one service call, one response |
| 13 | An application service is callable from Web, API and CLI and never references `Bulbula\Http` | TR-04, AC-4 | Services live in the areas, never in `Http` |
| 14 | Domain performs no I/O; time enters through an injected clock | TR-05, TST-2.4 | `Support\Clock` is a leaf; no `date()`, `time()` or `new DateTime()` in any `Domain/` |
| 15 | A repository is the only path to its aggregate's tables | TR-06, TR-16 | `Repository/` per area; no area reads another's tables |
| 16 | Cross-area work is orchestrated in an application service | TR-17 | Cross-area dependencies are declared as interfaces between areas (§7.2) |
| 17 | Advertising must not influence Directory or Community state, and ranking must not read Advertising | TR-18, G-7, SP-1 | One-way dependencies; asserted (22, 23) |
| 18 | Community depends on Directory and Identity, never the reverse | TR-19 | A Business never knows its Reviews exist beyond a derived summary |
| 19 | Platform services contain no domain rules | TR-20 | `Platform`, `Audit`, `Analytics`, `Mail`, `Outbound` hold no product invariant |
| 20 | Every new namespace is added to the architecture test in the same change | TR-01, IMP-4.3, IMP-20.6 | `tests/Arch/NamespaceGuardTest.php` is part of every slice's definition of done |

### 4.3 Data

| # | Constraint | Source | Structural consequence |
| --- | --- | --- | --- |
| 21 | MariaDB is the system of record; derived tables are rebuildable | DO-7, P-6 | `search_documents`, rating summaries, rollups are derived; `search:reindex` and `analytics:rollup` exist from M3 |
| 22 | Audit is append-only and a failed audit write fails the action | DO-8, TR-08 | `Audit/Repository/AuditEntryRepository` has no update and no delete (assertion 24) |
| 23 | Migrations are reversible, deterministic, SQLite-portable, and carry no product data | TR-173, TR-175, TR-178 | `database/migrations/` only; the catalogue is reference data (row 24) |
| 24 | The Category catalogue is **reference data, not application schema** | D-56, TR-177 | `database/reference/*.php` + an import command. **No catalogue row may appear in a migration** |
| 25 | No EAV, no attribute bags, no JSON standing in for columns | DM-1, TR-170 | Every entity is an explicit table with explicit columns |
| 26 | Absent means unknown; unknown hours are never "closed" | TR-56, DM-5 | Nullable columns; `OpenStatus::Unknown` is a value |
| 27 | Personal data is identifiable from the schema; personal contact points are flagged | DO-5/6, TR-172 | A per-table classification column in the data dictionary (§12.5, gap 3) |
| 28 | No password exists anywhere | D-48 | Assertion 25 forbids the token in `src/`; no `password` column in any migration |
| 29 | No retention period, response window, minimum age or OTP value may be invented | IMP-6.7, L-21, D-46, OT-02 | `config/retention.php` and `config/auth.php` may define **keys**; a missing required value fails the boot loudly (TR-194) rather than defaulting |

### 4.4 HTTP, URLs and rendering

| # | Constraint | Source | Structural consequence |
| --- | --- | --- | --- |
| 30 | Server-rendered HTML; JavaScript enhances, never enables | TR-96/97, NG-2, D-52 | `public/assets/js/enhance/*`; no bundler, no SPA, no build step required to deploy |
| 31 | Only `public/` is reachable; `resources/` holds templates | TR-184, DR-1 | Views never live under `public/`; uploaded media never does either (TR-81) |
| 32 | Exactly one executable entry point in the document root | TR-09, AC-3, invariant 16 | `public/index.php` alone; a test asserts it |
| 33 | The URL grammar is fixed by the IA and never mints a second indexable URL | IAR-1…IAR-8, SEO-10 | Routes mirror the grammar exactly; the canonical link is emitted by one component |
| 34 | Filter, sort, page and branch parameters never create indexable URLs | SEO-10, TR-102 | `PageMetadata` sets `noindex` and canonical for those states |
| 35 | Non-public surfaces are excluded from indexing | SEO-8, TR-104 | `robots.txt`, a route-family flag, and no `noindex` leakage to public pages |
| 36 | Templates escape by default; JSON uses HEX flags | TR-99, architecture §15 | One `Escaper` in `View/Escape`, one JSON encoder setting |
| 37 | `Bulbula\View` touches no database and no HTTP | ArchTest | `View/**` receives prepared data only; no repository import is possible |

### 4.5 Surfaces

| # | Constraint | Source | Structural consequence |
| --- | --- | --- | --- |
| 38 | One application, one deployment, two surfaces; the Mini App is not a build target | D-49, PA-1.1/1.6, TR-109 | No `mobile/`, no second front controller, no second view root |
| 39 | Surface differences are confined to one adapter; a surface conditional elsewhere is a defect | PA-2.1, TR-110 | `src/Surface` (contract) + `src/Telegram` (host calls) on the server; `assets/js/adapter/*` on the client (PT-03) |
| 40 | The adapter exposes **capabilities**, never host objects | PA-2.2, TM-3.2 | `SurfaceCapabilities` is the only thing shared presentation receives |
| 41 | The adapter inventory is closed at nine concerns | PA-2.4 | A tenth concern requires an owner decision, not a new file |
| 42 | Telegram context is a surface signal, not an identity; Telegram is not a provider | TR-113, AI-4, D-48 | `Telegram/Launch/ContextValidator` returns a surface, never a Customer |

### 4.6 Operations and quality

| # | Constraint | Source | Structural consequence |
| --- | --- | --- | --- |
| 43 | No daemon, no broker, no supervisor; cron-driven console commands only | TD-06, NG-4, CR-1…CR-8 | `Console/Command/*` + `Platform/Queue`; every job idempotent, bounded and locked |
| 44 | No cache service; caching ignores whether a cache exists | TD-05, TR-121 | `Platform/Cache` with a `NullCache` backend; no cache call is load-bearing |
| 45 | All configuration from the environment; `Env` reachable only from `Config` | CF-1…CF-7, TR-192, ArchTest | `config/*.php` + `Support/Env`; no superglobal outside `Config` |
| 46 | Feature-affecting values are configuration, not constants | TR-196 | Rate limits, page sizes, TTLs, verification interval and abuse thresholds have config keys and no literals |
| 47 | PHPStan at `level: max` with no baseline; 100 % line and type coverage; Infection MSI 100 on the unit suite | IMP-4.2/4.3, `infection.json5` | Favours explicit value objects over hidden branches; no unreachable code, no `@phpstan-ignore` |
| 48 | A test chooses the level it needs; no test reaches a real external service or the wall clock | TST-2.1…TST-2.6 | `tests/Support` carries `FakeOutboundHttp`, `FrozenClock`, `InMemoryDatabase` from M1 |

---

## 5. The repository root

Annotated. The full machine-readable inventory is
[`structure-manifest.txt`](structure-manifest.txt) (153 directories, 644
planned files, 7 runtime directories).

```text
bulbula/                              repository root — NEVER web-reachable (TR-184)
├── .ai/                              binding implementation context        F  [FND-03]
├── .github/workflows/                ci · mutation · security              F  [FND-04]
├── bin/console                       CLI entry point; the only exit()      F  (CR-6)
├── config/                           arrays built from env                 F  + M1…M9
├── database/
│   ├── migrations/                   YYYY_MM_DD_HHMMSS_*.php               F  + M1…M9
│   ├── reference/                    curated reference DATA (D-56, TR-177) M2 + M6 + M8
│   └── fixtures/                     synthetic only, never real people     M1
├── docs/                             the specification set
│   └── 70-engineering/               this document and its companions      [P]
├── public/                           THE DOCUMENT ROOT                     F  (DR-1)
│   ├── .htaccess                     DirectoryIndex + rewrite + handler    F
│   ├── index.php                     the single entry point                F  (TR-09)
│   └── assets/                       css · js · img · manifest.json        F  + M1…M7
├── resources/
│   ├── views/                        templates, outside the document root  F  + M1…M9
│   ├── lang/en/                      no hard-coded user-visible strings    M1 (TR-105)
│   └── content/                      static page bodies as data            M1
├── routes/                           web.php · api.php + per-family files   F  + M1…M8
├── src/                              PSR-4, Bulbula\                       F  + M1…M9
├── storage/                          runtime; contents disposable          F  + M1…M9
├── tests/                            Arch · Unit · Feature · Support       F  + M1…M9
├── tools/                            read-only verifiers                   F  + M1  [FND-04]
├── .env.example                      every variable, placeholders only     F  (CF-3)
├── .gitleaks.toml  .gitignore  .editorconfig                                F
├── composer.json  composer.lock                                           F
├── infection.json5  phpstan.neon  pint.json  rector.php                    F
└── README.md                                                              F
```

### 5.1 Four decisions in this root that need justification

| Decision | Why | Status |
| --- | --- | --- |
| `docs/70-engineering/` for engineering documents | The existing numbering runs `00-discovery … 60-decisions`. Engineering sits after decisions and before code. No number is reused | **[P]** — a verifier may require a registered folder (FND-04) |
| `database/reference/` beside `migrations/` | D-56/TR-177 forbid the catalogue in a migration, and forbid a code change to add a Category. Separating curated data from schema is the only structure that satisfies both | **[P] / PT-01** |
| `database/fixtures/` | Development and tests need data; L-5 and L-21 forbid real personal data in any environment before counsel confirms | **[C]** — derives from IMP-6.5 |
| `resources/content/` | C-18 page *mechanisms* are buildable at M1; the *text* is `PENDING COUNSEL`. Mechanism and text must be separable so one can ship without the other | **[P]** |

---

## 6. `src/` — the namespace map

### 6.1 Inventory

22 namespaces. **Fixed by the architecture** means `architecture.md` §3.1 names
it; **proposed** means this document introduces it under TRD rule A-2.

| Namespace | Purpose | Origin | Introduced |
| --- | --- | --- | --- |
| `Bulbula\Foundation` | Boot, composition root, services, kernel factory | Fixed | F |
| `Bulbula\Config` | Immutable configuration; the only environment reader | Fixed | F |
| `Bulbula\Support` | Leaf utilities every layer may use: clock, identifiers, pagination, typed env values | Fixed (§6.3) | F + M1 |
| `Bulbula\Logging` | PSR-3 factory, correlation and redaction processors | Fixed | F + M1 |
| `Bulbula\Error` | Promotion, safe rendering, reference ids | Fixed | F |
| `Bulbula\Exception` | Root-level failures | Fixed | F |
| `Bulbula\Http` | Request, response, kernel, routing, middleware, controllers, responders, validation | Fixed | F + M1…M8 |
| `Bulbula\Database` | PDO connection, migrations, bound queries | Fixed | F + M1 |
| `Bulbula\Console` | Command runner, commands, locks | Fixed | F + M1…M9 |
| `Bulbula\Diagnostics` | Liveness and readiness checks | Fixed | F + M1…M6 |
| `Bulbula\View` | Template engine seam, escaping, components, metadata, sitemap writing | Fixed | F + M1 |
| `Bulbula\Directory` | Business, Branch, Listing state, taxonomy, Areas | Fixed | M2 |
| `Bulbula\Operations` | Permission, provenance, verification, corrections, reports, queues | Fixed | M2 |
| `Bulbula\Identity` | Customers, provider identities, sessions, OTP, staff, roles, authorization | Fixed | M4 + M6 |
| `Bulbula\Community` | Reviews, ratings, moderation, saves | Fixed | M4 + M5 |
| `Bulbula\Advertising` | Placements, packages, campaigns, delivery | Fixed | M8 |
| `Bulbula\Search` | Normalisation, match stages, ranking, suggestions, search document | Fixed | M2 + M3 |
| `Bulbula\Media` | Upload intake, inspection, derivatives, storage adapters | Fixed | M2 |
| `Bulbula\Mail` | Composition, enqueue, transport adapters | Fixed | M1 |
| `Bulbula\Analytics` | Events, rollups, reporting reads | Fixed | M1 + M8 |
| `Bulbula\Audit` | Append-only privileged-action log | Fixed | M1 |
| `Bulbula\Telegram` | Mini App context validation and host integration | Fixed | M7 |
| `Bulbula\Outbound` | **The only owner of outbound HTTP** | **Proposed** — required by TD-07 | M1 |
| `Bulbula\Surface` | The surface-adapter contract and capabilities | **Proposed** — required by PA-2.2 | M1 |
| `Bulbula\Platform` | Queue, cache, derived-value coordination | **Proposed** — the Platform area's own tables | M1 |

### 6.2 PT-01 — reconciling two inventories [X → PT-01]

Two formal documents group the same components differently:

| Document | Grouping |
| --- | --- |
| `architecture.md` §3.1 (authority: component map, TRD §1.2) | 11 V1 namespaces: Directory, Operations, Identity, Community, Advertising, Search, Media, Mail, Analytics, Audit, Telegram |
| `trd-v1.0.md` §11 (authority: the constraints) | **Six bounded areas**: Directory, Operations, Identity, Community, Advertising, **Platform** — where Platform owns the audit log, analytics, notifications, background jobs and cache entries |

They are not contradictory; they answer different questions. One is a
**namespace map**, the other is a **table-ownership map**.

**PT-01 (proposed).** Namespaces follow `architecture.md` §3.1. Table
ownership follows TRD §11, and the Platform area is realised by four
namespaces:

| TRD §11 area | Namespaces that own its tables | Tables |
| --- | --- | --- |
| Platform | `Audit` | `audit_entries` |
| Platform | `Analytics` | `analytics_events`, `analytics_rollups`, `campaign_deliveries` |
| Platform | `Mail` | `notification_messages`, `delivery_attempts` |
| Platform | `Platform` | `queued_jobs`, `cache_entries` |

TR-16 ("a component must not read another area's tables") is satisfied because
ownership is asserted **per table per namespace**, not per nesting level.
Recorded as PT-01 because it interprets two documents rather than following
one. **It changes no product behaviour** (A-3).

### 6.3 The area template

Every bounded area follows one shape. The folder names make the layer visible
in every `use` statement.

```text
src/<Area>/
├── Domain/          entities, value objects, state machines, invariants — NO I/O (TR-05)
├── Application/     one class per meaningful use case; authorize, validate, persist, audit, notify
└── Repository/      the only path to this area's tables; SQL with bound parameters
```

| Rule | Statement | Source |
| --- | --- | --- |
| STR-1 | A bounded area contains exactly `Domain/`, `Application/`, `Repository/`. Any further sub-namespace must be named in §6.4 and justified | [P] — makes the boundary structural rather than conventional |
| STR-2 | Cross-cutting namespaces (`Search`, `Media`, `Mail`, `Analytics`, `Audit`, `Outbound`, `Surface`, `Telegram`, `Platform`) follow the same template where a domain exists, and may add one specialist sub-namespace — `Search/Text`, `Search/Ranking`, `Media/Storage`, `Media/Inspection`, `Mail/Transport`, `Telegram/Launch`, `Telegram/Host`, `Platform/Queue`, `Platform/Cache`, `Platform/Derived` | [P] |
| STR-3 | `Domain/` classes are `final`, `readonly` where they are value objects, and perform no I/O and read no clock except through an injected `Clock` | TR-05, ArchTest |
| STR-4 | An `Application/` class is suffixed with the use case in the imperative (`PublishListing`, `ModerateReview`) — never `Manager`, `Helper`, `Util`, or `Service` alone | [P] — a use-case name makes a controller's single call legible |
| STR-5 | A `Repository/` class is named for the aggregate it owns, not for its queries | TR-06 |

**Why `Application/` and not `Service/`.** The formal documents say
"application service" throughout (TR-03, TR-04, TR-17, architecture §2.1). The
folder name matches the layer name the documents use, so a reader tracing a
rule from the TRD to the file finds the same word. **PT-02 (proposed)** records
the naming choice.

### 6.4 File inventory per namespace

The complete list — 644 files with the milestone that introduces each — is in
[`structure-manifest.txt`](structure-manifest.txt). The shape is:

| Namespace | Representative files | Notes that matter |
| --- | --- | --- |
| `Foundation` | `Application`, `Environment`, `Services`, `HttpKernelFactory` | The composition root constructs; it is not a container (P-2) |
| `Support` | `Clock/Clock`, `Clock/SystemClock`, `Identifier/UlidGenerator`, `Identifier/OpaqueToken`, `Pagination/PageRequest`, `Pagination/PageMeta`, `Env/*` | `OpaqueToken` serves both cookie and bearer transports (TD-02) |
| `Http/Middleware` | `Pipeline`, `SecureHeaders`, `RequestIdMiddleware`, `SessionMiddleware`, `CsrfMiddleware`, `RateLimitMiddleware`, `SurfaceMiddleware`, `AuthorizationMiddleware`, `NoStoreMiddleware`, `IdempotencyMiddleware` | Exactly ten, in the order fixed by §9.2 |
| `Http/Controller` | `Web/**`, `Api/V1/**`, `Api/V1/Ops/**`, `HealthController` | Three families; no controller holds a rule (TR-03) |
| `Http/Responder` | `JsonErrorResponder`, `HtmlErrorResponder` | One envelope each (TR-26, TR-145) |
| `Database` | `Connection`, `ConnectionFactory`, `DatabaseConfig`, `Migrations/*`, `Query/BoundQuery` | `PDO` appears nowhere else |
| `Outbound` | `OutboundHttp`, `Transport`, `CurlTransport`, `NullTransport`, `OutboundRequest`, `OutboundResponse`, `Timeout`, `RetryPolicy` | `CurlTransport` is the only file allowed to call `curl_init` |
| `Audit` | `Application/AuditRecorder`, `Repository/AuditEntryRepository` | Append + read only |
| `Analytics` | `Domain/EventType` (12 values, PRD §26.2), `Application/RecordEvent`, `Application/RollupService` | `AnalyticsEvent` accepts no IP and no device identifier (TR-202) |
| `Mail` | `Domain/MessageCategory`, `Application/EnqueueNotification`, `Transport/Transport`, `Transport/LogTransport` | The V1 default transport writes to the log while D-41 is open |
| `Identity` | `Domain/{Customer, ProviderIdentity, Session, OtpRequest, StaffUser, Role, Permission, StaffAuthFactor}`, `Application/Authorization/*`, `Repository/*` | Five repositories are M4, four are M6 — the split is the milestone boundary |
| `Directory` | `Domain/{Business, Branch, Classification, Location, Listing, Provenance}`, `Application/*`, `Repository/*` | `Branch/HoursSpecification` is a **marker** until D-04; no hours table exists |
| `Operations` | `Domain/{PermissionRecord, VerificationRecord, Correction, Report}`, `Application/EvaluatePublicationReadiness` | The gate Directory depends on |
| `Community` | `Domain/{Review, Rating, ReviewModeration, RatingSummary, Save}`, `Application/*` | `Review` names a **Branch** as its subject in its own type |
| `Advertising` | `Domain/*`, `Application/SelectSponsoredPlacements` | Runs after organic ordering and cannot read ranking inputs |
| `Search` | `Domain/*`, `Text/Normaliser`, `Ranking/*`, `Application/Search`, `Port/SearchPort` | `Normaliser` is the one implementation used by index, query and suggestion (N-8) |
| `Media` | `Domain/*`, `Application/*`, `Storage/*`, `Inspection/*` | `StorageAdapter` has a local default so V1 runs with no vendor |
| `Surface` | `SurfaceAdapter`, `SurfaceCapabilities`, `SurfaceKind`, `WebAdapter`, `SurfaceResolver` | Capabilities, not host handles |
| `Telegram` | `TelegramAdapter`, `Launch/ContextValidator`, `Host/*` | The only server-side namespace that may name Telegram |
| `Platform` | `Queue/*`, `Cache/*`, `Derived/*` | `NullCache` proves caching is optional (TR-121) |

---

## 7. Dependency rules

### 7.1 The permitted direction

```text
Http ──────────▶ Area Application ──────────▶ Area Domain ─────────▶ Area Repository
 │                      │                                                   │
 │                      └──────────────▶ Support ◀───────────────────────────┘
 │                                          ▲
 └──────────▶ View (prepared data only) ────┘
                                          Logging · Error · Config · Exception
Console ─────────▶ Area Application ──────┘
Foundation ──────▶ everything (composition root only)
```

| Rule | Statement | Enforced |
| --- | --- | --- |
| STR-6 | Inner layers never import outer ones. `Domain/` imports no `Application/`, no `Repository/`, no `Http`, no `View` | Assertion 20 |
| STR-7 | `Repository/` imports no `Application/`, no `Http`, no `View`; it returns domain objects or plain structures | Assertion 20 |
| STR-8 | No area's `Application/` may import another area's `Repository/` | Assertion 21 |
| STR-9 | `Bulbula\View` imports neither `Database` nor `Http` nor `Outbound` | Existing + assertion 28 |
| STR-10 | `Bulbula\Database` imports neither `Http` nor `View` nor `Console` | Existing |
| STR-11 | `Bulbula\Outbound` imports no area, no `Http`, no `View` — it is a leaf | Assertion 19b |
| STR-12 | `Foundation` is the only namespace permitted to construct adapters, and it is never imported by anything else | [P] |

### 7.2 The five declared interfaces between areas

Cross-area work goes through an application service (TR-17). Five crossings
exist in V1 and no others:

| From | To | Interface | Why it cannot be avoided |
| --- | --- | --- | --- |
| `Directory` | `Operations` | `Operations\Application\EvaluatePublicationReadiness` | Publication is refused without a Permission record and a Verification record (TR-49, TR-50). Invariant 2 |
| `Directory` | `Search` | `Search\Application\WriteSearchDocument` | The search document updates synchronously on publish and edit (SD-2, TR-45) |
| `Community` | `Directory` | `Directory\Application\ReadBusinessProfile` (read-only) | A Review names a Branch; the summary is stored on Directory rows |
| `Http` | `Identity\Application\Authorization` | `PermissionChecker` | Authorization is server-side and per request (TR-33) |
| `Identity` | `Operations` | `AuditRecorder` (privileged actions) | Staff actions are audited by construction (TR-08) |

| Rule | Statement |
| --- | --- |
| STR-13 | A crossing is declared as an **interface with one implementation** only where a real second implementation is plausible (a fake in tests counts). Otherwise the concrete application class is injected directly — IMP-11.2 forbids premature abstraction |
| STR-14 | No crossing may be widened into a general dependency. Assertion 21 fails a build where an `Application/` class imports another area's `Repository/` |

### 7.3 The three prohibitions that keep the product honest

| Rule | Statement | Invariant |
| --- | --- | --- |
| STR-15 | `Search` must not reference `Advertising`. The organic pipeline does not know Campaigns exist (SP-1) | 1, 8 |
| STR-16 | `Advertising` must not reference `Search`'s ranking types. Delivery selection runs after ordering and cannot modify it (TR-69/TR-43) | 1, 8 |
| STR-17 | Nothing may write to `Audit`'s tables except `AuditRecorder`, and nothing may update or delete from them (DO-8) | 3, 6 |

---

## 8. Architecture assertions — 18 existing, 14 added

The 18 assertions in `tests/Arch/ArchTest.php` are described in
`architecture.md` §2.2 and reproduced in `.ai/architecture-context.md` §3.
V1 must extend them; TR-01b requires the outbound namespace specifically to be
named. Proposed additions, each traceable to a rule that already exists:

| # | Assertion | Enforces |
| --- | --- | --- |
| 19 | `curl_init`/`curl_exec` appear only in `Bulbula\Outbound` | TR-01a, TR-01b |
| 19b | `Bulbula\Outbound` imports no area, no `Http`, no `View` | STR-11 |
| 20 | `Domain/` and `Repository/` import nothing from `Application/`, `Http/`, `View/` | STR-6, STR-7, P-3 |
| 21 | No `Application/` class imports another area's `Repository/` | TR-16, STR-8 |
| 22 | `Bulbula\Search` references no `Bulbula\Advertising` symbol | SP-1, TR-41 |
| 23 | `Bulbula\Advertising` references no `Bulbula\Search\Ranking` symbol | TR-69, STR-16 |
| 24 | `AuditEntryRepository` declares no `update` and no `delete` method | DO-8 |
| 25 | No class, interface, trait or enum in `src/` contains `Password` or `password` | D-48 |
| 26 | No symbol contains `BusinessAccount`, `Claim`, `ReviewReply`, `Bid`, `Auction`, `Favourite|Favorite|Bookmark|Like` | AC-9, glossary §3 |
| 27 | `Domain/` contains no call to `time`, `date`, `mktime`, `strtotime`, `new DateTime` | TR-05, TST-2.4 |
| 28 | `Bulbula\View` references no `Bulbula\Database`, `Http`, `Outbound` symbol | TR-107, ArchTest |
| 29 | Every migration file declares a `down()` method, or a `@not-reversible` note naming the recovery | TR-175 |
| 30 | No migration references a `categories`, `subcategories`, `areas`, `aliases`, `placements`, `permissions` **row** (only the table) | D-56, TR-177 |
| 31 | Only `public/assets/js/adapter/telegram.js` contains the token `Telegram` | PD-02, TR-110 (client side) |
| 32 | `Bulbula\Http\Controller` references no `Bulbula\Outbound` symbol | TR-01d |

| Rule | Statement |
| --- | --- |
| STR-18 | A new namespace is not finished until its assertion exists in the same change (TR-01, IMP-20.6) |
| STR-19 | An assertion is never weakened to accommodate a change. If a change cannot satisfy an assertion, the change is wrong (IMP-4.2, audit §30.1) |

---

## 9. The HTTP layer

### 9.1 Controllers

```text
src/Http/Controller/
├── HealthController.php            /health, /health/ready            F
├── Web/                            HTML — the public and account surface
│   ├── HomeController.php                  C-01                       M1
│   ├── StaticPageController.php            C-18                       M1
│   ├── SearchController.php                C-02, C-03                M3
│   ├── CategoryController.php              C-04, C-06                M2
│   ├── AreaController.php                  C-05, C-06                M2
│   ├── NearbyController.php                C-07                      M3
│   ├── BusinessController.php              C-08…C-12, C-17           M2
│   ├── ReviewController.php                C-13                      M5
│   ├── ReportController.php                C-15                      M5
│   ├── SavedController.php                 C-14, C-34                M4
│   ├── AccountController.php               C-33, C-35                M4
│   ├── PrivacyController.php               C-36                      M9
│   ├── AuthController.php                  C-30, C-31                M4
│   ├── SitemapController.php               C-37                      M2
│   └── Ops/                                 C-19…C-29                 M6, M8
├── Api/V1/                         JSON — the Mini App and later clients
│   └── (one controller per API resource family, mirroring api-spec §2–§3)
└── Api/V1/Ops/                     staff JSON, separate authorization family
```

| Rule | Statement |
| --- | --- |
| STR-20 | A controller method does three things: read validated input, call exactly one application service, return a `Response`. Anything else belongs behind the service (TR-03) |
| STR-21 | There is one controller per **resource family and surface**, not per endpoint. `BusinessController` serves profile, hours, contact and map blocks because they are one page from one service call |
| STR-22 | HTML and JSON are separate controllers over the same services. A controller never branches on `expectsJson()` (TD-01, architecture §11) |

### 9.2 Middleware — exact order, outermost first

| # | Middleware | Responsibility | Source |
| --- | --- | --- | --- |
| 1 | `SecureHeaders` | CSP, nosniff, frame, referrer, permissions; HSTS over HTTPS | F |
| 2 | `RequestIdMiddleware` | Correlation id; attached to every log line and returned in `X-Request-Id` | TR-12, TR-134 |
| 3 | `SessionMiddleware` | Resolve cookie or bearer to an actor; **no session for a Guest discovery request** | TD-02, TR-14 |
| 4 | `SurfaceMiddleware` | Validate the Mini App context; mark the surface; never establish identity | TR-112, TR-113 |
| 5 | `CsrfMiddleware` | Per-session token on cookie-authenticated writes only | TD-02, CS-1 |
| 6 | `RateLimitMiddleware` | Auth, OTP, review, report, search, `/ops` writes | TRD §34 |
| 7 | `IdempotencyMiddleware` | `Idempotency-Key` on reports and campaign creation | TR-159 |
| 8 | `AuthorizationMiddleware` | Named permission per route; never distinguishes forbidden from missing | TR-33, TR-35 |
| 9 | `NoStoreMiddleware` | `no-store` on authenticated and staff responses | TR-120 |
| 10 | (handler) | Route handler | — |

| Rule | Statement |
| --- | --- |
| STR-23 | Order is not arbitrary: `RequestId` must precede anything that logs, and `SecureHeaders` must wrap error rendering so a 500 still carries headers (TR-11) |
| STR-24 | `NoStoreMiddleware` runs inside `AuthorizationMiddleware` so a denied response is also uncacheable |

### 9.3 Responders and validation

`JsonErrorResponder` produces the single envelope of `api-spec` §1.6 —
`code`, safe `message`, `request_id`, optional `details`. `HtmlErrorResponder`
produces non-technical copy with a route forward (TR-146) and no internal
detail in production (TR-144).

`Http/Validation` holds typed readers with rejection semantics: unknown query
parameters and unknown body fields are **rejected, not ignored**
(`api-spec` §1.3, TRD §34). Validation failures carry field-level detail and
preserve user input (TR-148).

---

## 10. Routes — the complete map

`routes/web.php` and `routes/api.php` remain the two registrars named by
`architecture.md`. Each `require`s a per-family file so that a family's
authorization, rate-limit and cache policy is visible in one place.

### 10.1 PT-03 — family defaults with per-route requirements [X → PT-03]

TRD §6.1 sets policy **per prefix**; the IA places two authenticated
destinations inside the public prefix:

| Destination | IA | TRD §6.1 family | Conflict |
| --- | --- | --- | --- |
| `/saved` | public prefix, `[auth]` | `/` = "no authentication" | [X] |
| `/b/{business}/review` | public prefix, `[auth]` | `/` = "no authentication" | [X] |

**PT-03 (proposed).** Keep the URL grammar exactly as the IA fixes it (IAR-1
makes URLs the constrained artefact), and make the **requirement per route,
with the family supplying defaults**:

| Family | Default | May a route narrow it? |
| --- | --- | --- |
| `/` | Guest-safe, cacheable | **Yes** — a named route may require a Customer session and become `no-store` and `noindex` |
| `/account/*` | Customer session required | No |
| `/ops/*` | Staff session + named permission | No |
| `/api/v1/*` | Public read or bearer | Yes, per endpoint |
| `/api/v1/ops/*` | Staff + named permission | No |

`RouteGroup` in `src/Http/Routing` carries the defaults; the route file states
the exception. No URL changes, no rule changes — only where the requirement is
declared. Recorded as PT-03 because it interprets two documents.

### 10.2 Public web

| Method | Path | Controller | Capability | Indexable |
| --- | --- | --- | --- | --- |
| GET | `/` | `Home` | C-01 | Yes |
| GET | `/search` | `Search` | C-02 | **No** (SEO-8) |
| GET | `/search/suggest` | `Search` (JSON) | C-03 | No |
| GET | `/categories` | `Category` | C-04 | Yes |
| GET | `/c/{category}` | `Category` | C-04 | Yes |
| GET | `/c/{category}/{subcategory}` | `Category` | C-04 | Yes |
| GET | `/c/{category}/in/{area}` | `Category` | C-06 | Yes, above the content rule (SEO-9) |
| GET | `/areas` | `Area` | C-05 | Yes |
| GET | `/a/{area}` | `Area` | C-05 | Yes |
| GET | `/nearby` | `Nearby` | C-07 | **No** |
| GET | `/b/{business}` | `Business` | C-08…C-12, C-17 | Yes |
| GET | `/b/{business}/report` | `Report` | C-15 | No |
| GET · POST | `/b/{business}/review` | `Review` | C-13 | No, **auth** |
| GET | `/saved` | `Saved` | C-34 | No, **auth** |
| GET | `/signin` | `Auth` | C-30, C-31 | No |
| GET · POST | `/signin/otp` · `/signin/otp/verify` | `Auth` | C-31 | No |
| POST | `/signin/google` | `Auth` | C-30 | No |
| POST | `/signout` | `Auth` | C-32 | No |
| GET · POST | `/account`, `/account/reviews`, `/account/privacy` | `Account`, `Privacy` | C-33, C-35, C-36 | No, **auth** |
| GET | `/about` `/privacy` `/terms` `/advertising` `/corrections` `/how-we-verify` `/how-ranking-works` `/review-policy` `/contact` | `StaticPage` | C-18 | Yes |
| GET | `/sitemap.xml` · `/robots.txt` | `Sitemap`, static | C-37 | n/a |
| GET | `/health` · `/health/ready` | `Health` | — | No |

### 10.3 Operations console (Web only)

```text
/ops                                  dashboard, work waiting by queue      M6
/ops/listings                         all · drafts · in-review · published ·
                                      needs-re-verification · unpublished   M6
/ops/listings/new · /{id}             create and edit                       M6
/ops/listings/{id}/changes            correction history                    M6
/ops/duplicates                       duplicate candidates                  M6
/ops/taxonomy · /ops/locations        Administrator-only                    M6
/ops/reviews · /ops/reports           the two moderation queues             M5, M6
/ops/advertising/{packages,placements,campaigns}                            M8
/ops/analytics · /ops/audit · /ops/staff                                     M6
/ops/signin                           separate staff entry point (ST-2)     M6
```

Queue and filter state lives in the URL (OPX-0.10), so `/ops/listings?queue=in-review&sort=oldest`
is a shareable view and remains non-indexable.

### 10.4 API

The endpoint set is exactly `api-spec-v1.0.md` §2–§5 and nothing more:
`/discovery/home`, `/categories`, `/categories/{slug}`, `/areas`,
`/areas/{slug}`, `/search`, `/search/suggest`, `/businesses/{slug}`,
`/branches/{id}/reviews`, `/reviews/{id}/reports`, `/auth/*`, `/me` and its
sub-resources, `/privacy/*`, `/api/v1/ops/*`, `/health`, `/health/ready`.

| Rule | Statement |
| --- | --- |
| STR-25 | No endpoint is added "because it will be needed". The first slice adds four endpoints and no others (implementation-plan §11.1) |
| STR-26 | A breaking change introduces `/api/v2`; `/api/v1` is additive only (TR-23) |
| STR-27 | No route exists without a capability, a decision or a rule behind it — routes inherit the "no requirement, no code" rule |

---

## 11. Views, assets and the surface boundary

### 11.1 Template tree

```text
resources/views/
├── layouts/     app · console · auth · error · email        M1
├── partials/    head · header · footer · discovery-bar ·
│                breadcrumb · account-menu · pagination · flash   M1
├── components/  the 44 specified components, one partial each    M1…M8
├── pages/       public screens, mirroring the IA exactly         M1…M9
├── ops/         console screens                                  M5…M8
├── fragments/   the same content as swappable fragments (D-38)   M1+
├── errors/      404 · 405 · 410 · 419 · 429 · 500 · 503          M1
├── email/       one template per PRD §18 category                M1…M9
└── sitemap/     urlset.xml                                       M1
```

| Rule | Statement |
| --- | --- |
| STR-28 | Component partial names match `component-spec` numbering (1–44) so a screen can be traced to its specification |
| STR-29 | `layouts/error.html` is used for every failure class; it is reachable with no service available |
| STR-30 | No template reads the database, the request or the clock — it receives prepared data (ArchTest, assertion 28) |

### 11.2 PT-04 — handling three open display decisions

| Open item | What the structure does | What it must not do |
| --- | --- | --- |
| **D-17** view layer in-house vs library | `View/Template/TemplateEngine` is an interface with one in-house implementation. Swapping is a composition-root change | Do not adopt a template library into `composer.json` before D-17 is decided |
| **D-16** htmx + Alpine vs vanilla modules | `public/assets/js/enhance/*` are dependency-free modules loaded per page; a fragment endpoint already exists for autocomplete. Both answers remain possible | Do not add a bundler, a build step, or any npm dependency to the deployment path |
| **D-38** Mini App navigation model | Every list page also has a fragment template | Do not build a client-side router |

| Rule | Statement |
| --- | --- |
| STR-31 | The page must deliver its content without JavaScript. A fragment template duplicates a page region; it never becomes the only source of that region (TR-96/97) |

### 11.3 Assets

```text
public/assets/
├── css/   tokens.css (semantic tier ONLY) · base.css · components.css ·
│          surface-public.css · surface-console.css · print.css
├── js/    boot.js · adapter/{web,telegram}.js · enhance/*.js
├── img/   logo · icons · missing-image.svg
└── manifest.json   content hash → filename, for long max-age (SA-2)
```

| Rule | Statement |
| --- | --- |
| STR-32 | `tokens.css` holds the **semantic** tier only. No component references a raw colour, so D-53's exact values land in one file (design-system §1, §2.3) |
| STR-33 | `assets/js/adapter/` is the client-side analogue of `src/Surface`: the only files that may name a runtime. Only `telegram.js` may reference `window.Telegram` (assertion 31) |
| STR-34 | A `.js` file under `enhance/` must be removable without removing a capability. If removing it removes function, it is not enhancement (TR-97) |

---

## 12. The data layer

### 12.1 Migration plan by milestone

One migration per table or one coherent change; filename
`YYYY_MM_DD_HHMMSS_description.php`; every one reversible and SQLite-portable
(TR-173/175).

| Milestone | Migrations | Notes |
| --- | --- | --- |
| F | `create_health_checks` (exists) | Tracking table for migrations already exists |
| M1 | `create_queued_jobs`, `create_cache_entries`, `create_audit_entries`, `create_analytics_events`, `create_analytics_rollups`, `create_notification_messages`, `create_delivery_attempts`, `create_redirects` | **Platform only.** No product table, no product column — this is what makes M1 startable before M0's successors |
| M2 | `create_sub_cities`, `create_areas`, `create_categories`, `create_aliases`, `create_businesses`, `create_branches`, `create_business_categories`, `create_landmarks`, `create_media_assets`, `create_media_attachments`, `create_permission_records`, `create_verification_records`, `create_listing_changes` | `business_categories` carries the D-57 constraints **at creation**, never retrofitted. No hours column (D-04) |
| M3 | `create_search_documents`, `create_zero_result_queries` | FULLTEXT plus the filter/order indexes, written with the table (TR-169) |
| M4 | `create_customers`, `create_provider_identities`, `create_sessions`, `create_otp_requests`, `create_saves` | `sessions` carries `actor_type` + `actor_id`; the staff foreign key arrives in M6 rather than blocking TD-02 |
| M5 | `create_reviews`, `create_review_moderations`, `create_review_reports`, `create_rating_summaries`, `create_reports` | `reviews` carries the unique active-per-(customer, branch) constraint in the database (TR-70). `create_reports` lands with the report domain it serves (C-15, C-26) rather than with the console that triages it |
| M6 | `create_staff_users`, `create_roles`, `create_permissions`, `create_role_permissions`, `create_staff_auth_factors` | `staff_auth_factors` holds rows, so D-45 needs no migration (TR-31) |
| M8 | `create_placements`, `create_packages`, `create_campaigns`, `create_campaign_targets`, `create_campaign_deliveries` | Inventory overlap enforced at creation (TR-72) |
| M9 | Retention and deletion support only | Adds nothing until L-21/D-46 resolve; see §12.2 |

| Rule | Statement |
| --- | --- |
| STR-35 | A migration creating a table also creates the indexes the queries need (TR-169, TR-179) |
| STR-36 | No migration inserts a Category, Sub-category, Alias, Area, Placement, Package or Permission row. Reference data is imported (assertion 30, TR-177) |
| STR-37 | A migration touching a table whose columns are the subject of an open decision is **not written**. Checked against §12.2 before every migration (IMP-20.1) |

### 12.2 Structures deliberately not created

| Not created | Blocked by | Consequence, stated honestly |
| --- | --- | --- |
| An opening-hours table or column set | **D-04** | `OpenStatus::Unknown` is the only value in V1. The `open_now` filter therefore matches nothing, and `has_confirmed_hours` on the search document is always false. That is the honest behaviour, not a bug |
| A services / products / pricing table | **D-44** | The console has **no field** for them (`operations-console-ux` OPX-3.9). The profile section is absent, not empty |
| `business_user`, `business_account`, `claim` | D-54, D-02 | No principal, no table, no seam beyond the permission model |
| `password`, `password_reset` | D-48 | Assertion 25 forbids the token in `src/` |
| `review_reply`, `review_photo`, `review_vote` | D-12, D-36, D-37 | Assertion 26 |
| `bid`, `auction`, `ad_impression_price`, `budget`, `payment`, `transaction` | D-10, D-11 | Assertion 26 |
| A separate `listing` table | data-model §3.3 | Publication state lives on `businesses` |
| `attribute` / `entity_attribute` | DM-1 | Explicit columns only |
| A shared `users` table | PRD ACC-8 | Customers and staff are separate principals |
| Retention values in any config default | L-21, D-46 | Keys exist; a missing required value fails the boot (TR-194) |

### 12.3 Conventions — synchronization point S2

implementation-plan §10.4 makes these the thing that must be agreed **before
the first product migration**. Stated here so S2 has a written answer:

| Concern | Convention | Source |
| --- | --- | --- |
| Primary key | `id`, unsigned big integer, `AUTO_INCREMENT` | DM-2, TR-166 |
| Natural keys | Unique constraints, never primary keys | DM-2 |
| Timestamps | `created_at`, `updated_at`; state times as `*_at`; **UTC storage**, `Africa/Addis_Ababa` display | DM-9, TR-168 |
| Slug | `slug`, unique, stable across renames; a change writes a `redirects` row | DM-3, SEO-4 |
| Concurrency | A `version` or `updated_at` token on published entity tables | TR-57 |
| Soft deletion | Only where the product promises history: Listings (state, not delete), Reviews (state), audit (never deleted) | TR-171, DO-9 |
| Absent vs false | Nullable columns; booleans never encode "not collected" | DM-5, TR-56 |
| Money | Precise decimal type; the column exists, no value is approved | D-10, advertising-products |
| Enumerations | Stored as short strings, mirrored by a backed PHP enum in `Domain/` | [P] — keeps SQLite and MariaDB identical |
| Foreign keys | Explicit `ON DELETE` on every relationship; audit never cascades | TR-167 |
| Indexes | Declared with the table, named for the query they serve | TR-169, TR-179 |
| Portability | No engine-specific DDL outside an explicit branch, because tests run on SQLite | TR-173 |
| Reference data | `database/reference/*.php` + `ReferenceImportCommand`, never a migration | D-56, TR-177 |

### 12.4 Data classification and the four gaps

| Gap | What is missing | Proposed treatment |
| --- | --- | --- |
| 1 | **No data dictionary.** `data-model.md` classifies entities, but TR-172/DO-5 require that personal-data **columns** be identifiable so a rights request is complete | **[P]** `docs/70-engineering/data-dictionary.md`, one row per column with class and purpose, generated from migrations and checked by `tools/verify-structure.py` |
| 2 | **No column-level purpose record.** TR-199 says a field without a stated purpose is removed | **[P]** the dictionary's `purpose` column is the record |
| 3 | **No machine check that §12.2 stays true** | **[P]** assertions 26 and 30 cover the schema side; the dictionary covers the rest |
| 4 | **Personal contact points are defined in prose only** | **[C]** DO-6 requires the flag at the schema level; `branches.phone`/`email` and `businesses.public_email` need the flag, decided when the column is created |

---

## 13. Configuration and environment

| File | Holds | Introduced | Invents a value? |
| --- | --- | --- | --- |
| `config/app.php` | environment, debug, base URL, timezone | F | No |
| `config/database.php` | DSN parts and PDO options | F | No |
| `config/logging.php` | channel, level, path, rotation | F | Path yes, level recommended |
| `config/http.php` | page sizes, cache TTLs, request-id header, outbound timeouts | M1 | Only non-product values |
| `config/security.php` | rate-limit keys, session/token lifetimes, cookie policy, CSP | M1 | **No** — OT-01, OT-02 |
| `config/auth.php` | Google credentials; OTP policy keys | M4 | **No** — values are OT-02 |
| `config/mail.php` | provider endpoint, sender, credential | M1 | No — D-41 |
| `config/media.php` | allowed types, byte ceilings, derivative sizes | M2 | Allow-list yes; ceilings are D-25 |
| `config/search.php` | tokeniser settings, per-page clamp, suggestion cap, cache TTL | M3 | Shape yes |
| `config/ranking.php` | ranking input weights | M3 | **No** — D-09, OR-1. Keys exist and are **unset** |
| `config/analytics.php` | event set, rollup dimensions, raw-event policy key | M1 | **No** — D-27 |
| `config/retention.php` | one key per data class | M9 | **No** — L-21/D-46. A required value that is absent fails the boot |
| `config/telegram.php` | bot token, host origin, frame-ancestors allow-list | M7 | No |
| `config/maps.php` | provider, key, embed strategy, fallback | M7 | No — D-21 |
| `config/i18n.php` | default locale, stored label locales | M1 | English-first per D-18 |

| Rule | Statement |
| --- | --- |
| STR-38 | A new setting updates `config/*.php`, `.env.example` and the TRD's variable classes in the same commit (CF-7, TR-193) |
| STR-39 | A configuration key whose value is a **product** answer (a weight, a threshold, a retention period, a session lifetime before OT-01) ships **without a value**. Absence is loud, never silent (TR-194, IMP-6.7) |
| STR-40 | `APP_DEBUG` remains incapable of enabling debug output in production; the structure does not change `Application::isDebug()` | TR-195 |

---

## 14. Console, cron and background work

| Command | Purpose | Introduced |
| --- | --- | --- |
| `migrate` · `rollback [--steps]` · `migration:status` | Schema | F |
| `queue:work` | Drain the job table, bounded per run | M1 |
| `maintenance:prune` | Expire sessions, OTP state, cache, temporary files | M1 |
| `diagnostics:report` | Health and drift in one output | M1 |
| `reference:import` | Import curated reference data (D-56) | M2 |
| `sitemap:generate` | Rebuild the sitemap from published Listings | M2 |
| `search:reindex` | Rebuild search documents, safe to re-run | M3 |
| `analytics:rollup` | Aggregate events into rollups | M3 |
| `listings:freshness` | Flag Listings due for re-verification (D-08) | M3 |
| `staff:create` · `staff:disable` | Staff lifecycle; factor strength is D-45 | M6 |
| `media:derivatives` | Regenerate derivatives from retained originals | M7 |
| `privacy:execute` | Execute a rights request; windows are L-7 | M9 |

| Rule | Statement |
| --- | --- |
| STR-41 | Every command is idempotent, bounded, lock-guarded and logs start, end, outcome and counts (TR-150…TR-155, TR-139) |
| STR-42 | **No product behaviour depends on a job having run.** Campaign state and verification age are evaluated at request time (TR-154) |
| STR-43 | Cron invokes the PHP 8.4 binary explicitly and writes to a log file, never to email (CR-7, CR-8) |

---

## 15. Tests

### 15.1 Layout

```text
tests/
├── Arch/        ArchTest (18 existing + 14 proposed) · Boundaries · Naming ·
│                 ForbiddenConstructs · NamespaceGuard · AbsentCapability
├── Unit/        one directory per namespace; no I/O, no clock, no network (TST-2.1)
├── Feature/     Web · Api · Ops · Cli · Contract · Invariants
├── Support/     ApplicationFactory · InMemoryDatabase · RequestBuilder ·
│                 FrozenClock · FakeOutboundHttp · FakeMailTransport ·
│                 FakeStorageAdapter · FixtureLoader · AccessibilityAssertions
└── Fixtures/    synthetic data only
```

### 15.2 The 20 invariants, one file each

`test-strategy` §3.1–§3.2 lists twenty behaviours that must be executable
tests. Mapping each to a file is what makes M1's "test harness" concrete:

`tests/Feature/Invariants/` —
`OrganicRankingUnaffectedByCampaignTest` (**1**, the most important test in the
system), `PublicationRequiresPermissionTest` (**2**, every publishing path
including import), `AuditRecordedForEveryStaffMutationTest` (**3**),
`GuestAnalyticsCarriesNoIdentityTest` (**4**),
`DiscoveryWorksWithoutExternalDependenciesTest` (**5**),
`AuditFailureFailsTheActionTest` (**6**), `SponsoredPlacementAlwaysLabelledTest`
(**7**), `SponsoredDoesNotDisplaceOrganicTest` (**8**),
`ModerationCannotEditReviewTest` (**9**), `ModerationRequiresPolicyGroundTest`
(**10**), `ReporterIdentityNeverExposedTest` (**11**),
`GuestJourneyWithoutSessionTest` (**12**),
`AuthorizationEnforcedServerSideTest` (**13**),
`ProductionErrorsCarryNoInternalsTest` (**14**), `LogsCarryNoSecretsTest`
(**15**), `DocumentRootHasSingleEntryPointTest` (**16**),
`CampaignWindowEvaluatedAtRequestTimeTest` (**17**),
`ClosureIsStateNotDeletionTest` (**18**), `NoExcludedCapabilityExistsTest`
(**19**), `ScheduledJobsAreIdempotentTest` (**20**).

| Rule | Statement |
| --- | --- |
| STR-44 | Invariants 1–20 have files from M1, written against synthetic fixtures, so they execute before the features they govern exist (TST-3.1) |
| STR-45 | A test never encodes an unresolved decision. No test asserts an OTP window, a retention period, a session lifetime or a ranking weight while its decision is open (TST-4.5) |
| STR-46 | Coverage thresholds are never lowered to accommodate a slice; a surviving mutant is a weak test (IMP-4.2) |

---

## 16. Build order — what exists after each milestone

| Milestone | Directories that come into existence | Exit condition |
| --- | --- | --- |
| **M1** Foundation | `Outbound`, `Surface`, `Platform/{Queue,Cache,Derived}`, `Audit`, `Analytics`, `Mail`, `Http/{Responder,Validation}`, `Support/{Clock,Identifier,Pagination}`, `View/{Template,Escape,Component,Metadata}`, `Console/Command`, `tests/Support`, `tests/Feature/Invariants` | Every new namespace guarded; the 20 invariant files exist and fail honestly; gate green |
| **M2** Core directory | `Directory/*`, `Operations/*`, `Media/*`, `Search/Application/WriteSearchDocument`, `database/reference`, first public pages | A real profile renders on Web **and** in the Mini App from the database, with the publication gates enforced. The **services** that record Permission and Verification exist here because the gate needs them; their **console screens** are M6 |
| **M3** Discovery | `Search/*` complete, `Http/Controller/Web/{Search,Nearby}`, `Console/Command/Search*` | Invariant 1 executes; zero-result recovery works |
| **M4** Identity | `Identity/{Domain,Application,Repository}` (customer half), `Community/Domain/Save`, `/saved`, `/account` | A Guest completes the whole journey with no session; a Customer can Save |
| **M5** Reviews | `Community/*` complete, moderation pipeline, `Operations/Application/{Submit,Triage,Resolve}Report` | Invariants 9, 10, 11 execute; per-Branch uniqueness is enforced by the database |
| **M6** Console | `Http/Controller/Web/Ops/*`, `Identity` staff half, `AuditReader`, `WorkQueueQuery` | Every mutating request audited; O-1…O-8 tested; **operational use still gated by D-45** |
| **M7** Surfaces | `Telegram/*`, `assets/js/adapter/telegram.js`, map and share enhancement | Surface parity tested per capability; the Mini App degrades to Guest on validation failure |
| **M8** Advertising | `Advertising/*` | Invariants 1, 7, 8 execute; activation impossible without the §16 checks. **Built disabled** |
| **M9** Hardening | `config/retention.php`, `privacy:execute`, deletion and export | Self-assessable readiness criteria met |

| Rule | Statement |
| --- | --- |
| STR-47 | A directory in this table is created **in the milestone that fills it**, never earlier — `architecture.md` §3, IMP-11.7 |
| STR-48 | A slice is a capability crossing the stack, not a layer. "Create all repositories" is not a slice (IMP-11.1) |

---

## 17. Naming and code conventions

| Kind | Convention | Enforced by |
| --- | --- | --- |
| Controller | `*Controller` in `Http/Controller/**` | ArchTest (exists) |
| HTTP failure | `*Exception` extending `HttpException` | ArchTest (exists) |
| Use case | Imperative verb phrase in `Application/` — `PublishListing`, `ModerateReview` | Review; STR-4 |
| Domain entity | Singular noun, `final`; value objects `readonly` | ArchTest (exists) |
| Repository | `*Repository` for the aggregate; `*Query` for read models | Review; STR-5 |
| Enum | Backed enum, one value per product state a formal document names — no invented states, no missing ones | Review; [P] proposed assertion 30a |
| Template | Lowercase, hyphenated, `.html`, matching IA or console route | Review |
| Migration | `YYYY_MM_DD_HHMMSS_description.php` | `MigrationLocator` |
| Test | `*Test` matching the class or invariant it covers | Review |
| Vocabulary | **The glossary's word, everywhere** — `Save` not favourite, `Review` not comment, `Area` not neighbourhood, `Closure` not deletion | Glossary §1 binder; assertion 26 covers the deprecated set mechanically |

---

## 18. What this structure deliberately does not contain

| Absent | Reason |
| --- | --- |
| `app/`, `bootstrap/`, `routes/console.php`, any framework skeleton | NG-1 |
| A service container, a locator, auto-wiring, annotation scanning | NG-8, P-2 |
| An ORM, a query builder, an entity-mapping layer | NG-7 |
| `mobile/`, a second front controller, a second view root | D-49, PA-1.1 |
| A frontend build step, a bundler, a `package.json` requirement for deployment | TR-96/97, D-16, deployment §4 |
| A search-cluster client, a queue-broker client, a cache-server client | NG-3, NG-4, NG-5 |
| A business-account, claim or owner surface of any kind | D-54, D-02 |
| A `password` column, field, route or reset flow | D-48 |
| A placeholder for any excluded capability — no disabled control, no "coming soon" | PRD §13, H-4 |
| Directories created in advance of their code | architecture §3, §18 of this document |

---

## 19. Open items

### 19.1 Proposed technical decisions requiring a record

| ID | Proposal | Must be recorded in |
| --- | --- | --- |
| **PT-01** | Namespaces follow `architecture.md` §3.1; the Platform area maps to Audit + Analytics + Mail + Platform (owner of each table named) | TRD §9/§11 or a TD-xx |
| **PT-02** | The application-service layer is the `Application/` sub-namespace of each area | TRD §9.2 (component rules) |
| **PT-03** | Route requirements are per route with family defaults, so the IA URL grammar is kept intact | TRD §6.1 with `architecture.md` §6.1 |
| **PT-04** | The view layer, the client-side approach and the Mini App navigation model are handled by seams that keep D-17, D-16 and D-38 open | TRD §22/§23 |
| **PT-05** | `docs/70-engineering/` is the engineering document home; `database/reference/` holds curated reference data | Documentation convention; a verifier may need updating (FND-04) |

### 19.2 Open product and technical items this structure accommodates

D-04 (hours — no table), D-09 (ranking — keys unset), D-11 (billing — invoice
reference only), D-13 (linking — identity is a row), D-14 (role split —
permissions as data), D-16, D-17, D-19 (dark mode — tokens only), D-20, D-21,
D-23, D-25 (local storage default), D-26, D-27 (raw-event policy key unset),
D-33 (Telegram not a provider), D-35 (reports are free text), D-38, D-39
(delivery built disabled), D-40 (Area content), D-41 (log transport default),
D-42/D-42b, D-43, D-44 (no table), D-45 (console built, not operable), D-46 and
L-items (no retention value in code), OT-01…OT-09 (all as configuration keys
without invented values).

### 19.3 What must happen before the first line of product code

1. **Confirm or rebuild the Phase 1 foundation** — 42 files marked `F?` (FND-01).
2. **Restore or author `.ai/`** — the documented pre-coding ritual depends on it (FND-03).
3. **Restore the verifiers and CI**, or accept that `docs/` changes cannot be validated (FND-04).
4. **Record PT-01…PT-05** so the structural choices bind by the documented process rather than by habit.
5. **Decide where engineering documents live** (PT-05) so the doc verifier and the folder do not conflict.

---

## 20. Findings raised

Four findings in [`findings-v1.0.md`](findings-v1.0.md) bear directly on this
design. They are reported, not resolved: two of them are document-consistency
defects that the documentation-audit process owns, and two are repository-state
facts that only the owner can settle.

| Finding | Effect on this document |
| --- | --- |
| **FND-01** The Phase 1 foundation described by TRD §6 is not on `main` | 42 files are marked `F?` rather than `F`. The design is unchanged either way |
| **FND-02** The documented quality baseline cannot be verified from this tree | The design treats the baseline as a **floor to establish**, not a number to maintain |
| **FND-03** `.ai/` is absent although the implementation plan requires reading it | §19.3 item 2 |
| **FND-04** `tools/` and CI are absent, so `docs/` cannot be validated | Adding `docs/70-engineering/` is a proposal (PT-05) with a known risk |
| **FND-05** Six duplicate `TR-xx` identifiers and at least four cross-document mis-citations | This document cites rules **by content as well as by ID**, and never relies on an ID alone |
| **FND-06** TRD §6.1 route families conflict with the IA for `/saved` and `/b/{business}/review` | PT-03 |
| **FND-07** Two formal documents group components differently (namespaces vs areas) | PT-01 |

---

## 21. Traceability

| Section | Derived from |
| --- | --- |
| §4 constraints | TRD §4, §6.4, §7, §9.2, §11, §13–§15, §24, §30–§34; architecture §1–§2; data-model §1, §10; IMP §5, §6 |
| §5 root | deployment §3, architecture §3, TRD §32 |
| §6 namespaces | architecture §3.1, §2.2; TRD §9.1, §11 |
| §7 dependency rules | architecture §2.1/§2.2; TRD §9.2, §11.1, §16–§20 |
| §8 assertions | architecture §2.2; TRD §6.4, TD-07; data-model §10; AC-1, AC-3, AC-9 |
| §9 HTTP | architecture §8–§13; TRD §10, §15, §27, §34 |
| §10 routes | information-architecture §1–§2, §9; operations-console-ux §2; api-spec §2–§5; TRD §6.1 |
| §11 views and assets | TRD §22, §23; design-system §1–§9; component-spec §45–§46; PA-2.1/2.2, PD-02 |
| §12 data | data-model §2–§11; TRD §30–§31; D-56/TR-177; IMP §9.2 |
| §13 configuration | TRD §33; deployment §5; CF-1…CF-7 |
| §14 console | TRD §28; deployment §13; IMP §6.2 items 10, 11 |
| §15 tests | test-strategy §2–§5; IMP §4.3, §20 |
| §16 build order | IMP §7, §10, §11 |
| §17 conventions | glossary; ArchTest; IMP §4 |

**Decision references:** D-01, D-02, D-04, D-06, D-09, D-10, D-11, D-12, D-13,
D-14, D-15, D-16, D-17, D-18, D-19, D-20, D-21, D-23, D-25, D-26, D-27, D-29,
D-33, D-34, D-35, D-38, D-39, D-40, D-41, D-42, D-42b, D-43, D-44, D-45,
D-46, D-48, D-49, D-50, D-51, D-52, D-53, D-54, D-55, D-56, D-57.
