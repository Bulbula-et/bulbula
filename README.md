# Bulbula

A curated business directory for Ethiopia: verified facts, honest reviews, and
no paid placement pretending to be a result.

Plain PHP 8.4. No framework, no ORM, no template language, no build step. Four
runtime libraries, one web entry point, and a structure organised by the four
ways a request can enter the system.

```
frontend/    Bulbula\Frontend\   pages for people
api/         Bulbula\Api\        JSON for other systems
admin/       Bulbula\Admin\      moderation and operations
backend/     Bulbula\Backend\    the application: core, domain, services, repositories
```

Everything shared sits behind those four surfaces, so a business rule is written
once and holds for every entry point — including the ones that do not exist yet.

## Requirements

- PHP 8.4 or newer with `pdo_mysql`
- MariaDB 10.11 or MySQL 8.0
- Composer 2

## Getting started

```bash
composer install
cp .env.example .env          # then set the database credentials
composer serve                # http://localhost:8000
```

The first request worth making is `GET /health`. It answers `{"status":"pass"}`
and touches nothing, so it can never be the reason a healthy process is
restarted. `GET /health/ready` is the honest one: a real database round-trip and
a writable `storage/logs/`, answered `200 {"status":"pass","checks":{…}}` or
`503 {"status":"fail","checks":{…}}`. Both are also under `/api/v1`.

Nothing else is implemented yet: the homepage renders, the search page explains
that the directory is being prepared, and every unbuilt path returns a proper
404. Routes are added with the feature that needs them, never before.

## Everyday commands

| Command | What it does |
| --- | --- |
| `composer serve` | Development server (PHP's built-in server + `tools/router.php`) |
| `composer check` | Lint, static analysis and tests — the one command a change must pass |
| `composer test` | PHPUnit (runs on in-memory SQLite; needs no database) |
| `composer analyse` | PHPStan at level max over every source tree |
| `composer lint` / `composer fix` | Pint, check / fix |
| `php bin/console` | Command line (no commands registered yet) |
| `gitleaks git .` | Secret scan over the tracked tree and the full history — the same scan CI runs |

## Layout

```
public/                      the only web-reachable directory
  index.php                  single entry point for every request
  assets/{css,img,js}/       static files, served directly by the web server
  .htaccess                  rewrite + security headers

routes/                      the URL grammar, one file per surface
config/                      configuration; .env holds only what differs per machine
backend/Core/                env, config, request, response, router, kernel,
                             view, error rendering, controller factories
backend/Support/             logger factory, health checks, template helpers
backend/{Domain,Repositories,Services}/   where the application will live
backend/Security/            sessions, CSRF, permissions, rate limiting, audit
backend/Integrations/        outbound HTTP — the only place that may open one
frontend/, api/, admin/      controllers and views per surface
database/{migrations,seeds,reference}/    forward-only SQL, catalogue data
storage/                     logs, cache, uploads — outside the document root
tests/{Unit,Feature,Arch,Support}/  one test per behaviour; the architecture
                             suite fails the build when a boundary is crossed
```

`docs/70-engineering/project-structure-v2.md` explains every directory and the
rules that keep it that way.

## Design rules

These are the rules the structure exists to enforce. They are checked in review
and, where a machine can check them, by `tests/Arch/` and `composer check` — and
both run in CI (`.github/workflows/ci.yml`) on every push and pull request.

1. **The web root is `public/`.** Nothing above it is reachable by URL.
2. **A view never queries.** Controllers and services prepare data; templates
   print it. `e()` is the only way a value enters HTML.
3. **Every SQL statement lives in a repository.** Prepared statements only,
   with emulation off.
4. **A surface never bypasses the service layer.** Controllers call services;
   they do not re-implement a rule or open the database.
5. **Permissions are data.** Named permission strings checked by a service; the
   code never branches on a role name.
6. **No numbers in code that a decision has not fixed.** Ranking weights,
   retention periods and session lifetimes come from `config/`, and a
   misconfiguration fails loudly instead of running with a silent default.
7. **The error path is part of the design.** JSON callers get the documented
   envelope; everyone else gets a readable page; stack traces appear only when
   `APP_DEBUG` is on, and never in production.
8. **Nothing sensitive reaches a log line.** No passwords exist anywhere in the
   system; codes, tokens and session identifiers are never logged.
9. **There is no container.** `Application::boot()` constructs every service by
   hand, in dependency order, and lists one factory per controller. A controller
   that is not in that list cannot be reached; `tests/Arch/` fails if a service
   locator comes back.

## Testing

```bash
composer test
```

Feature tests boot the real application and drive it through the real kernel,
with the same call the entry point uses, so a broken route or a missing binding
fails in the suite rather than in production. Every test runs against in-memory
SQLite; a test run cannot touch real data.

## Documentation

- `docs/70-engineering/project-structure-v2.md` — the structure, the request
  lifecycle, the security model and the build order. **§11 records how the
  specification set was reconciled with this code** on 2026-10-11; **§11.1
  tracks the five places where code and specification disagreed**, four of them
  now built (R-1, R-2, R-4, R-5) and one — the composition root, R-3 — left to
  the owner.
- `docs/` — the product, technical and operations specification set. Documents
  that own a statement about layout, namespaces or tooling carry a dated
  amendment note; historical records were annotated rather than rewritten.
