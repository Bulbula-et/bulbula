# Architecture

| | |
| --- | --- |
| **Document** | System Architecture |
| **Version** | v1.0 |
| **Status** | Draft |
| **Date** | 2026-10-07 |
| **Owner** | Project owner (Bulbula) |
| **Supersedes** | `docs/architecture.md` (Phase 1; relocated here and extended to V1) |

> **Structure amendment — 2026-10-11.** Reconciled with the application as
> built: the surface-first layout, the namespace map, the boot sequence, the
> routing API and the middleware/health/migration status. The authority for the
> layout is [`project-structure-v2.md`](../70-engineering/project-structure-v2.md).
> **No requirement was removed or weakened**; controls that are not yet built
> are marked **not yet in place** with the milestone that delivers them.

**Scope.** The canonical description of how Bulbula is built: layers,
components, boundaries and the rules that keep them honest. Constraints and
their sources live in [`trd-v1.0.md`](trd-v1.0.md); this document is the map.

**Relationship to Phase 1.** The foundation described here is real and
running — front controller, router, middleware pipeline, PDO layer,
migrations, console, health checks, error handling. V1 extends it with domain
code. Nothing in Phase 1 is replaced.

---

## 1. Principles

1. **Framework-free.** Plain PHP. No Laravel, Symfony full-stack, Laminas,
   Mezzio, Dotkernel, Slim or Flight. Small, single-purpose libraries are
   welcome; application frameworks are not. An architecture assertion in
   `tests/Arch/DependenciesTest` enforces this.
2. **Composition over magic.** `Application` is a composition root. There is
   no annotation scanning and no reflection magic in application code: a class
   states what it needs in its constructor and receives it. One small explicit
   container exists. `Application::boot()` constructs every collaborator by
   hand, in dependency order, and hands each controller to the kernel through
   one written-out factory table. Nothing resolves a dependency from anywhere
   else, and a route that names a controller nobody wired fails loudly.
3. **One direction.** HTTP → application → domain → persistence. Inner layers
   never reference outer ones.
4. **One implementation of every rule.** Web, API and Telegram are adapters
   over the same application services (D-49).
5. **Server-rendered first.** HTML is produced on the server; JavaScript
   enhances. Core content works without it (C-37, D-52).
6. **Everything typed.** PHPStan at `level: max`, 100% type coverage.
7. **Tests that actually test.** Coverage alone is not a signal; mutation
   testing gates the real strength of the suite. Thresholds are never
   lowered (D-26).
8. **Auditability and privacy by construction**, not by policy alone
   (C-29, D-51).
9. **Simplest thing compatible with the PRD.** Complexity needs a
   requirement behind it, not an anticipation.

---

## 2. Layers

```text
            Browser (mobile-first)        Telegram Mini App         Future Flutter
                     │                            │                        │
                     └──────────────┬─────────────┘                        │
                                    │ HTTPS                                │
┌───────────────────────────────────▼───────────────────────────────────────▼──────┐
│ HTTP layer            Request · Response · Status · Method · emitter              │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Router / middleware   FastRoute matching · global and per-route pipelines         │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Controller            HTML controllers · JSON controllers · thin, no rules        │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Application services  Use cases: one method per meaningful operation              │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Domain                Entities, value objects, state machines, invariants         │
├──────────────────────────────────────────────────────────────────────────────────┤
│ Repositories          SQL per aggregate, prepared statements only                 │
├──────────────────────────────────────────────────────────────────────────────────┤
│ MariaDB  ·  filesystem  ·  external adapters (identity, mail, storage, maps)      │
└──────────────────────────────────────────────────────────────────────────────────┘
```

### 2.1 Responsibilities

| Layer | Does | Must not |
| --- | --- | --- |
| **HTTP** | Model the protocol: immutable `Request`/`Response`, status and method enums, emission | Contain application logic |
| **Router / middleware** | Match routes, run cross-cutting concerns (headers, correlation id, session, auth, CSRF, rate limit) | Contain business rules or SQL |
| **Controller** | Read input, delegate to one application service, render a response | SQL, business rules, HTML string building, validation that belongs in a service |
| **Application service** | Orchestrate a use case: authorize, validate, call domain, persist, audit, notify | Know about HTTP, sessions, templates |
| **Domain** | Hold the rules: listing state transitions, review states, campaign eligibility, ranking inputs, value objects | Perform I/O of any kind |
| **Repository** | Translate between the domain and SQL; own its aggregate's tables | Contain business rules; be bypassed by another component |
| **Adapters** | Speak to the outside: identity provider, mail transport, object storage, Telegram validation | Leak vendor types into the domain |

### 2.2 Enforced boundaries

These are architecture tests — a violation fails the build. The suite lives
at `tests/Arch/` and is **in place**; every rule below is asserted today, and a
boundary added by a slice is asserted in the change that adds it (TR-01,
IMP-20.6). `DependenciesTest` covers the framework rows and the dependency
surface; `BoundariesTest` covers the boundaries and the absence of a container;
`ConventionsTest` covers naming, strict types and the document root.

| Rule | Status |
| --- | --- |
| No framework namespaces | In place — `DependenciesTest` |
| No container or service locator; controllers come from an explicit factory table | In place — `BoundariesTest` |
| No ORM/DBAL namespaces | In place — `DependenciesTest` |
| `PDO`, `PDOStatement`, `PDOException`, `mysqli` only in `Bulbula\Backend\Database` | In place — `BoundariesTest` |
| `curl_init` and family only in `Bulbula\Backend\Integrations` | In place — held by absence until the first adapter lands |
| `Bulbula\Backend\Core` contains no SQL | In place — `BoundariesTest` |
| Controllers never touch `Bulbula\Backend\Database` or `PDO` | In place — `BoundariesTest` |
| Controllers suffixed `Controller`, HTTP exceptions suffixed `Exception` | In place — `ConventionsTest` |
| `Bulbula\Backend\Database` independent of the HTTP layer and of every surface | In place — `BoundariesTest` |
| `Bulbula\Backend\Domain` and `Bulbula\Backend\Services` independent of the HTTP layer and of every surface | In place — `BoundariesTest` |
| `Bulbula\Backend\Core\View` touches no database and no HTTP | In place — `BoundariesTest` |
| `Env` read only inside `Bulbula\Backend\Core`; no superglobals elsewhere | In place — `BoundariesTest` |
| Every SQL statement lives in `Bulbula\Backend\Repositories\{Area}` | In place — `BoundariesTest` |
| All classes final, strict types, value objects readonly | In place — Pint (`final_class`, `declare_strict_types`) and `ConventionsTest` |
| No `dd`, `dump`, `die`, `var_dump`, `sleep`, `exit` in source | In place — `BoundariesTest` and `ConventionsTest` |

Two consequences are easy to miss:

- **`curl_init` is confined to `Bulbula\Backend\Integrations`** (TD-07), so
  every outbound HTTP call sits behind an adapter whose transport lives there.
  When the first outbound adapter lands, the assertion names that namespace in
  the same change — it is never relaxed instead.
- **Domain and service namespaces are added to the "independent of
  presentation" expectation as they are created**, not after the fact
  (TR-01, IMP-4.3).

> **Settled — composition root (owner, 2026-10-11).** The first pass recorded
> a deviation here: a small explicit container used only at the edges. The
> owner took the original absolute rule instead. There is no container, no
> binding registry and no resolution-by-name anywhere in the build.
> `Application::boot()` constructs each collaborator explicitly and lists one
> factory per controller in `Bulbula\Backend\Core\ControllerFactory`; a route
> that names a class no factory covers raises a `ConfigurationException` and a
> readable 500 rather than being conjured. The rule in §17 is unconditional
> again, and `tests/Arch/BoundariesTest` fails if a service locator returns.

---

## 3. Layout

```text
admin/                 Bulbula\Admin\ — operations console: controllers, views
api/                   Bulbula\Api\ — JSON v1: controllers, requests, resources
backend/               Bulbula\Backend\ — the application (Core, Database, Domain,
                       Repositories, Services, Security, Integrations, Mail,
                       Console, Exceptions, Support)
bin/console            CLI entry point: migrations, scheduled jobs, maintenance
config/                app.php, database.php, security.php, logging.php, mail.php
database/migrations/   ordered, forward-only migrations
database/reference/    curated catalogue data, not schema (D-56)
docs/                  documentation (this file lives in docs/30-technical/)
frontend/              Bulbula\Frontend\ — public pages: controllers, views
public/                document root: index.php, .htaccess, assets
routes/                web.php, api.php, admin.php, console.php
storage/               runtime artefacts (logs, cache, uploads) — contents git-ignored
tests/                 Unit, Feature, Arch, Support
```

The four surfaces and the backend layers exist from the start, because they are
the map. **A directory for a capability appears when the code that belongs in
it does** — `backend/Services/Directory/` is created by the first Directory
service, not in advance of it.

### 3.1 Namespaces

One PSR-4 root per surface, and layers inside the backend. A path in the
repository is enough to know what a file is and who may call it.

| Namespace | Path | Responsibility |
| --- | --- | --- |
| `Bulbula\Frontend` | `frontend/` | HTML pages: controllers and views |
| `Bulbula\Api` | `api/` | JSON v1: controllers, request objects, resources |
| `Bulbula\Admin` | `admin/` | Moderation and operations console: controllers and views |
| `Bulbula\Backend\Core` | `backend/Core/` | Composition root, environment, config, request, response, router, controller factories, kernel, view, error rendering |
| `Bulbula\Backend\Database` | `backend/Database/` | PDO connection, connection factory, migration machinery |
| `Bulbula\Backend\Exceptions` | `backend/Exceptions/` | Typed failures, including the HTTP exceptions |
| `Bulbula\Backend\Support` | `backend/Support/` | Clock, identifiers, logger factory, template helpers |
| `Bulbula\Backend\Security` | `backend/Security/` | Sessions, CSRF, permission checks, rate limiting, audit trail |
| `Bulbula\Backend\Integrations` | `backend/Integrations/` | Outbound HTTP — the only namespace that may own a transport (TD-07) |
| `Bulbula\Backend\Mail` | `backend/Mail/` | Message composition and delivery state |
| `Bulbula\Backend\Console\Commands` | `backend/Console/Commands/` | Scheduled and manual jobs behind `bin/console` |
| `Bulbula\Backend\Domain\{Area}` | `backend/Domain/{Area}/` | Entities, value objects and rules |
| `Bulbula\Backend\Services\{Area}` | `backend/Services/{Area}/` | Application services |
| `Bulbula\Backend\Repositories\{Area}` | `backend/Repositories/{Area}/` | SQL, one repository per aggregate |

**Areas** are the bounded areas of TRD §11 — `Directory`, `Operations`,
`Identity`, `Community`, `Advertising`, `Platform` — with `Search` and `Media`
sitting beside them. A capability therefore has three known homes
(`Domain/{Area}`, `Services/{Area}`, `Repositories/{Area}`) plus the surface
that presents it. Cross-area work is orchestrated by an application service,
never by reaching into another area's tables (TRD TR-16, TR-17).

**Capability coverage** — the same mapping, by area rather than by layer:

| Area | Capabilities |
| --- | --- |
| Directory | C-04…C-12, C-19…C-24 |
| Operations | C-19…C-21, C-26 |
| Identity | C-30…C-33, C-36 |
| Community | C-13, C-14, C-25, C-34, C-35 |
| Advertising | C-16, C-27 |
| Search | C-02, C-03, C-07 |
| Media | C-22 |
| Platform (audit, analytics, jobs, cache, mail) | C-28, C-29, C-31, C-38, C-39 |
| Integrations | D-33, D-49 (Telegram, maps, Google, mail provider) |

---

## 4. Boot sequence

`public/index.php` is the single entry point. Before the application exists
it performs two version-agnostic guards — interpreter version and presence of
`vendor/autoload.php` — because a failure at that stage happens before any
handler could report it.

`Application::boot()` then:

1. Loads `.env` when present (never committed; see `.env.example`).
2. Builds the `Config` repository from `config/app.php`, `config/database.php`,
   `config/security.php`, `config/logging.php` and `config/mail.php`.
3. Applies the configured timezone and registers the error handler —
   warnings become exceptions, output is verbose while developing and opaque in
   production, where the client receives only a reference id that is also
   written to the log.
4. Loads `routes/web.php`, `routes/api.php` and `routes/admin.php` into the
   `Router`.
5. Constructs the shared collaborators, in dependency order: `Config`,
   `Router`, `View` (given the `frontend/Views` and `admin/Views` roots),
   `ErrorRenderer`, `Connection` (lazy — no socket is opened until a query
   needs one), `HealthChecker`, the PSR-3 logger, then the `Kernel` with a
   `ControllerFactory` holding one written-out factory per controller.

`Application` **is** the composition root: there is no container behind it. The
whole wiring is the body of one method, so "where does this come from?" has a
line number as its answer, and a controller that is not listed cannot be
reached at all.

---

## 5. Request lifecycle

Apache serves `public/` and resolves `DirectoryIndex index.php`; anything
that is not an existing file or directory is rewritten internally to
`index.php`, keeping `REQUEST_URI` intact for the router.

```text
public/index.php
  └─ guards                             interpreter version, autoloader present
  └─ Application::boot()                .env → Config → timezone → errors → routes → bindings
  └─ Request::fromGlobals()             superglobals read once, here and nowhere else
  └─ Kernel::handle(Request)
        ├─ correlation id    generated per request, returned as X-Request-Id
        ├─ Router::match()   FastRoute: found | 405 (Allow header) | 404
        ├─ handler           controller built by its factory, or a closure
        └─ errors            HttpException and anything else → ErrorRenderer
  └─ Response::send()                   status line, headers, body
```

**Not yet in place:** the global and per-route pipelines (§7) and the
`SecureHeaders` middleware. Today the browser-facing security headers are set
by `public/.htaccess`; TRD APP-7.2 requires them at the application layer so
they survive a host change, and that is the M1 work.

The error path runs inside `Kernel::handle`, so a 500 still carries
`X-Request-Id` and is still rendered in the caller's format.

---

## 6. Routing

Routes live in `routes/web.php`, `routes/api.php`, `routes/admin.php` and
`routes/console.php`. Each file returns a `Closure(Router): void`, so a route
file can do exactly one thing: register paths against the router it is handed.

```php
return static function (Router $router): void {
    $slug = '[a-z0-9-]{1,80}';

    $router->get('/', ['Bulbula\Frontend\Controllers\HomeController', 'index']);
    $router->get('/b/{business:' . $slug . '}', [BusinessController::class, 'show']);
};
```

- `get()`, `post()`, `put()`, `patch()`, `delete()` accept either a
  `[Controller::class, 'method']` pair — the normal case, built by its
  factory and called as `($request, $params)` — or a closure taking
  `(Request $request, array $vars)`.
- **Versioning is a literal path prefix** (`/api/v1/…`). There is no route
  group mechanism; a mechanism would be the beginning of a framework.
- **Dynamic segments are constrained at the route**, not in the handler:
  `{business:[a-z0-9-]{1,80}}`. A segment that does not match the pattern is
  not a route at all, so a reserved word can never be reached through a
  wildcard and a URL can never carry a payload.
- Matching is delegated to `nikic/fast-route`. The kernel converts the result
  into a `RouteMatch`; a miss becomes `HttpNotFoundException` (404), and a path
  that exists under another verb becomes `MethodNotAllowedException` (405)
  carrying an `Allow` header.
- A route is registered **with the slice that implements it**. A path with no
  handler behind it is a promise the application cannot keep, so the planned
  paths live in a comment block in the route file until then.

The router stays thin: register, match. Named routes, URL generation, model
binding, middleware aliases and controller resolution by convention would be
the beginning of a framework.

### 6.1 V1 route families

| Prefix | Surface | Authentication | File |
| --- | --- | --- | --- |
| `/` | Public web (discovery, profiles, static pages) | None, **except** the paths listed below | `routes/web.php` |
| `/account/*` | Customer web | Customer session | `routes/web.php` |
| `/ops/*` | Operations console | Staff session + permission | `routes/admin.php` |
| `/api/v1/*` | Public JSON API | None, or bearer token | `routes/api.php` |
| `/api/v1/ops/*` | Operations JSON API | Staff token + permission | `routes/api.php` |
| — | Console | Not routable | `routes/console.php` |

Separate prefixes exist so that authorization, rate limiting, caching and
indexability are set per family rather than per route (TRD TR-29).

**Two authenticated paths sit inside the public prefix**: `/saved` and
`/b/{business}/review`. The IA fixes those URLs, so the rule is applied one
level down: **each route declares its own requirement set**, and the family
supplies the default. A public-prefix route that needs a session or a
non-indexable response declares it in the route file beside its path, and the
declaration is the thing the authorization middleware reads. Indexability
follows the same declaration, so `/saved`, `/nearby`, `/search` and the review
form are never marked indexable by accident.

---

## 7. Middleware

A middleware implements one method:

```php
public function process(Request $request, Closure $next): Response;
```

`Pipeline` composes a list back to front, so the first entry is the
outermost: the request travels down to the handler and the response bubbles
back up. A middleware may short-circuit by returning without calling
`$next`.

**The pipeline is not yet in place.** Security headers are currently set by
`public/.htaccess`, which is host-level and is not enough (APP-7.2). The M1
work builds the pipeline, `SecureHeaders` first, and moves the headers to the
application layer. V1 then adds, in outermost-first order:

| Middleware | Responsibility |
| --- | --- |
| `SecureHeaders` | CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`; HSTS over HTTPS |
| Request id | Generate or accept a correlation id, attach to the request and to every log line, return it in a header |
| Session | Resolve a session from cookie or bearer token; attach the actor (Guest, Customer, Operator, Administrator) |
| CSRF | Verify a per-session token on state-changing HTML submissions |
| Rate limit | Apply configured limits to authentication, OTP, review, report and search paths |
| Surface | Detect and validate the Telegram Mini App context; mark the surface on the request |
| Authorization | Per-route permission requirement for `/ops/*` and account routes |

**Order is a security property, not a convenience.** Authorization runs after
the session it depends on and before the handler it protects; nothing that
can reject a request runs after something that can leak from it.

---

## 8. Controllers, services and domain

A controller is an HTTP boundary and nothing else: read input from the
`Request`, call one application service, turn the result into a `Response`.
`HealthController` is the reference example — it asks `HealthChecker` for a
`HealthReport` and serialises it.

An application service is the use case. It authorizes, validates, invokes
domain logic, persists through repositories, writes the audit entry and
enqueues notifications. It knows nothing about HTTP, which is what lets the
same service back a web page, an API endpoint and a console command.

Domain objects hold the rules and perform no I/O: listing state transitions,
review state transitions, campaign eligibility, rating computation, ranking
inputs, value objects such as opening hours and coordinates. **Time enters
through an injected clock** (`Bulbula\Backend\Support\Clock`, TR-05, TST-2.4)
— a domain rule that reads `time()` directly cannot be tested against a
boundary, and every date-sensitive rule in this system has a boundary to test.

```text
HomeController ─▶ DiscoveryService ─▶ Listing (domain) ─▶ ListingRepository ─▶ Connection
                        │
                        ├─▶ AuditRecorder
                        └─▶ Mailer (queued)
```

---

## 9. Database access

MariaDB through PDO. No ORM, no query builder, no Active Record.

- `config/database.php` (driven by `DB_*` variables) carries the DSN inputs
  and the charset; `Bulbula\Backend\Database\ConnectionFactory` builds the
  PDO options: exceptions on error, associative fetch, stringify off, and
  `ATTR_EMULATE_PREPARES => false` — native prepared statements, so escaping
  is the server's job and never ours.
- `ConnectionFactory` is the **only** place that calls `new PDO`, and it is
  driver-aware: MySQL and the test suite's in-memory SQLite take different
  options because they are different engines.
- `Connection` opens **lazily** and exposes `pdo()` and `ping()`. Lazy matters
  for more than speed: a readiness probe must be able to report a database
  outage instead of dying with it.
- `select()`, `selectOne()`, `execute()`, `statement()` and `transaction()`
  arrive with the first repositories (M2). `transaction()` commits on success,
  rolls back on any throwable and rethrows — no silent swallowing.
- PDO failures become `DatabaseException` (M2) naming the driver and database
  but never the user, password or DSN; the password is marked
  `#[\SensitiveParameter]` so it is redacted in traces.

V1 adds **repositories** above this: one per aggregate, owning its tables,
returning domain objects or plain data structures, batching to avoid N+1
queries, and declaring the indexes its queries depend on in the migration
that creates the table.

The test suite runs against in-memory SQLite, which keeps it fast and
hermetic, so migrations stay portable or branch explicitly where they cannot.
One difference between the two engines is worth stating outright, because SQL
that runs on the test engine and dies on the production engine is invisible to
a suite that runs on one of them: **a named parameter is bound once per
statement.** SQLite will bind `:now` at every occurrence; MySQL's native
prepared statements refuse the statement (`HY093 Invalid parameter number`).
Two timestamps that happen to be equal are still two parameters —
`:created_at, :updated_at`. `tests/Arch/BoundariesTest` enforces this
statically, on every commit.

---

## 10. Migrations

Files live in `database/migrations/` named
`YYYY_MM_DD_HHMMSS_description.php`, each returning an anonymous class that
**implements** `Migration` with `up(Connection)` and `down(Connection)` — an
interface rather than a base class, because there is nothing to inherit and the
contract should stay as small as the job (a recorded, deliberate deviation from
the "extending" wording this document carried while the machinery was unbuilt).

**Built (2026-10-11, M1).** `MigrationLocator` discovers files in filename
order, `MigrationRepository` owns the `migrations` tracking table (`migration`
primary key, `batch`, `executed_at`) and `Migrator` applies, reverts and
reports. A batch records each migration with the same batch number; a rollback
reverts the last batch, or the last *n* with `--steps=n`. A migration that
sorts **before** an applied one is refused rather than applied: that is a branch
that went backwards, and the history it would rewrite cannot be rewritten back.

Three decisions worth stating, because each is a rule and not an accident:

- **No wrapper transaction.** MariaDB commits DDL implicitly, so a transaction
  around a migration would not make it atomic — it would only hide where it
  stopped. The ledger row is therefore written *after* the migration succeeds,
  and a migration is expected to be safe to re-run from the point it failed.
- **The ledger table is created by the runner**, never by a migration: nothing
  can record what has been applied before something knows how to record it.
- **Migrations are forward-only once shipped**: a correction is a new migration,
  never an edit to an applied one.

The first migration to land is `2026_10_11_120000_create_audit_entries.php`, the
audit trail (C-29, TR-08) — a platform table, so it arrived with M1 rather than
with the first product table in M2.

```bash
php bin/console migrate              # apply everything pending
php bin/console migration:status     # applied / pending, in order
php bin/console rollback             # revert the last batch
php bin/console rollback --steps=2   # revert the last two batches
```

`bin/console` is the only file in the project that calls `exit()`.

---

## 11. Web and API

| | Web | API |
| --- | --- | --- |
| File | `routes/web.php` | `routes/api.php` |
| Prefix | `/` | `/api/v1` |
| Success | HTML, or operational JSON | JSON |
| Errors | `text/plain` or an HTML error page | JSON `{"error": {...}}` |

`Request::wantsJson()` decides the error format: true for any path under
`/api/`, a JSON content type, or a JSON `Accept` header. That is the single
place where the distinction lives; controllers never branch on it.

**The Web surface does not call its own API** (TRD TD-01). Both surfaces call
application services in-process. The API exists for the Telegram Mini App,
for progressive-enhancement fetches, and for the later Flutter client.

---

## 12. Error handling

`Kernel::handle` owns the whole error path, and `ErrorRenderer` renders it in
one place. `HttpException` is abstract and its subclasses —
`HttpNotFoundException`, `MethodNotAllowedException`, `ServerErrorException`,
`ValidationException` — carry their own status, headers and, for validation,
field-level detail. Anything else is a 500: full detail while debugging, an
opaque message plus the reference id in production. **Controllers contain no
try/catch for this** — they throw, the kernel renders.

- JSON callers get `{"error": {"code", "message", "request_id"}}`; stack
  traces, SQL and file paths appear only when debug is on, and
  `config/app.php` forces debug off in production whatever `.env` says.
- HTML callers get a readable page with a route forward, and the same
  reference id.
- **A 404 for something that exists but is not yours is the same 404 as for
  something that does not exist.** Existence is information.

Logged through `error_log()` on purpose: the logger itself may be the thing
that failed, and an error path must not depend on the system it reports on.

---

## 13. Health checks

| Endpoint | Checks | Depends on the database | Status |
| --- | --- | --- | --- |
| `GET /health`, `GET /api/v1/health` | process is alive and can answer; no dependency is touched | no | **Built** |
| `GET /health/ready`, `GET /api/v1/health/ready` | a real `ping()` round-trip, and a writable `storage/logs/` | yes, for the readiness answer only | **Built** |

Liveness must never depend on the database, otherwise a blip triggers a restart
of a healthy process; it answers `200 {"status":"pass"}` and nothing else.
Readiness answers `200 {"status":"pass","checks":{"database":"ok","storage":"ok"}}`
or `503 {"status":"fail","checks":{…}}` with per-check detail, and sends
`Cache-Control: no-store`. Both are served from `routes/health.php` by one
controller and one `HealthChecker` in `backend/Support/Health/`, and a tracking
table is added to the readiness check when the migrations create one.

The readiness answer is honest rather than convenient: with no database
reachable it returns `503` and `{"checks":{"database":"error"}}` instead of a
200 that would fail the first real request. Neither probe discloses anything
about the deployment — no versions, hosts, paths or credentials.

---

## 14. Document root

Only `public/` is web accessible. `backend/`, `frontend/`, `api/`, `admin/`,
`config/`, `database/`, `routes/`, `tests/`, `vendor/`, `composer.json`,
`composer.lock`, `storage/` and `.env` stay above it, and a feature test
asserts that the document root holds a single executable entry point.

Two `.htaccess` files assume the docroot will one day be misconfigured: the
repository root refuses the source tree — **rewrite rules only**, because some
servers apply the document root's parent-directory `.htaccess` as well, and an
authorization directive there is enforced against the correctly configured site
(`deployment.md` §3.2, DR-6) — and `storage/` denies everything. A site pointed
at the repository root returns 403 for its whole tree instead of serving
configuration, migrations or uploads. **Do not edit either file to "make the
site work" — fix the docroot instead.**
Uploaded media is stored outside the document root and served through an
adapter or an explicit endpoint. Deployment details are in
[deployment.md](deployment.md).

---

## 15. Security posture

- Security headers — restrictive CSP, `X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and HSTS over
  HTTPS — are set by `public/.htaccess` today and move to a `SecureHeaders`
  middleware at M1 (APP-7.2: they must survive a host change). The Mini App
  needs a `frame-ancestors` policy admitting the Telegram host — **Open (TRD
  OT-05)**.
- JSON is encoded with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS |
  JSON_HEX_QUOT`, so a response embedded in an HTML attribute or a script
  context cannot break out of it, plus `JSON_UNESCAPED_SLASHES |
  JSON_UNESCAPED_UNICODE` so URLs and non-ASCII text stay readable, and
  `JSON_THROW_ON_ERROR` so invalid UTF-8 fails loudly instead of emitting an
  empty body. The recorded deviation of the first pass is **closed**; the
  encoder is `Response::json()` and the rule is held by
  `tests/Unit/Backend/Core/ResponseTest.php`.
- Malformed JSON bodies and unsupported methods are rejected before any
  handler runs.
- Credentials are never logged, never placed in an exception message and
  never committed; `.env.example` ships placeholders only.
- Production error output is opaque: a reference id and nothing else.

V1 adds sessions, CSRF, rate limiting, authorization and upload validation —
the mechanisms described in TRD §34. The full security specification belongs
to `docs/50-security/` in a later phase.

---

## 16. Development versus production

| Concern | Local / testing | Production |
| --- | --- | --- |
| `APP_DEBUG` | `true` | forced off — see `isDebug()` |
| Error output | class, message, file, line, trace | generic message + reference id |
| Log format | readable lines | JSON |
| Log level | `debug` | `warning` or higher (recommended) |
| Database | in-memory SQLite for tests | MariaDB |

`config/app.php` combines configuration with the environment — debug is
forced `false` when `APP_ENV=production`, whatever `.env` says. Debug output
cannot be switched on by accident on a live site.

---

## 17. What V1 deliberately does not add

| Not added | Why |
| --- | --- |
| A framework, or a service locator inside application code | Standing rule, with no exception: the composition root constructs everything by hand and `BoundariesTest` fails if a container returns |
| An ORM or query builder | Enforced by tests |
| A SPA or front-end framework | Server-rendered requirement (C-37, D-52) |
| A search cluster | MariaDB-backed search is the V1 baseline |
| Redis or Memcached | Not available on the target host; caching must work without it |
| A message broker | Cron-driven jobs are sufficient |
| Containers or orchestration as a deployment requirement | Shared hosting is the target |
| Any business-account feature | D-54; seams only, never features |

---

## Decision references

D-26, D-33, D-49, D-51, D-52, D-54.
