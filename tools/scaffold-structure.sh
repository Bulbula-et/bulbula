#!/usr/bin/env bash
#
# SUPERSEDED 2026-10-11 — DO NOT RUN.
#
# This script builds the v1 `src/`-based tree, which is not the structure of this
# application. The active structure is documented in
# docs/70-engineering/project-structure-v2.md, and the tree it describes is
# created directory by directory as the code that belongs in it lands.
#
# It is kept only as the record of the v1 design and of its file inventory
# (docs/70-engineering/structure-manifest.txt). Either rewrite it against the v2
# layout or delete it — do not leave it looking runnable.
#
# scaffold-structure.sh — the planned Bulbula V1 file structure, as data.
#
# WHY THIS FILE EXISTS
#   architecture.md §3 states a binding rule: "Directories for future modules are
#   not created in advance: a directory appears when the code that belongs in it
#   does." IMP-11.7 repeats it. A whole-tree scaffold committed to the repository
#   would therefore VIOLATE a documented rule, not implement it.
#
#   So this script does not build the application. It holds the MAP as data, and
#   offers three read-only or non-destructive modes:
#
#     --plan [--milestone=Mx]   print the tree (default). Writes nothing.
#     --manifest                print path<TAB>milestone<TAB>kind for diffing.
#     --preview <dir>           materialise a THROWAWAY preview tree (dirs +
#                               .gitkeep + placeholder stubs) for inspection.
#                               Refuses to target the repository root.
#
#   The authoritative prose is docs/70-engineering/php-application-structure-v1.0.md
#
# MILESTONE TAGS
#   F   Phase 1 foundation, taken as given by TRD §6 / architecture.md §3.
#       Files marked "F?" are documented by namespace only — the Phase 1 tree is
#       not present on `main` (see findings-v1.0.md, FND-01) and their exact
#       filenames must be read from the real foundation before being trusted.
#   M1..M9  Added by the milestone that needs them (implementation-plan §10.1).
#
# KIND
#   d  directory      f  file      i  runtime artefact (git-ignored contents)
#
set -euo pipefail

TREE=$(cat <<'TREE_EOF'
F|d|.ai|binding implementation context (absent from main @ 4c44b50 — FND-03)
F|d|.ai/contexts|per-area implementation context
F|d|.github/workflows|ci.yml · mutation.yml · security.yml
F|d|bin
F|f|bin/console|CLI entry point; the only file permitted to exit() (CR-6)
F|d|config
F|f|config/app.php|environment, debug, base URL, timezone
F|f|config/database.php|DB_* → DatabaseConfig
F|f|config/logging.php|channel, level, path, rotation (LG-1, LG-4)
F|d|database
F|d|database/migrations|YYYY_MM_DD_HHMMSS_*.php, reversible, SQLite-portable
F|d|docs|the 71-file specification set
F|d|public|THE DOCUMENT ROOT — the only web-reachable directory (TR-184, DR-1)
F|d|public/assets|served by Apache, never routed through PHP (DR-5)
F|f|public/index.php|the single executable entry point (TR-09, DR-2)
F|f|public/.htaccess|DirectoryIndex + mod_rewrite + cPanel PHP 8.4 handler block
F|d|resources
F|d|resources/views|templates, outside the document root (TR-184)
F|d|routes|web.php, api.php
F|d|src|PSR-4 root, Bulbula\
F|d|storage|runtime artefacts; contents disposable (PM-8)
F|d|tests|Unit, Feature, Arch (test-strategy §2)
F|d|tools|read-only verifiers
F|f|.env.example|every variable, placeholder values only (CF-3)
F|f|.gitignore|
F|f|.gitleaks.toml|secret scanning (SC-1)
F|f|composer.json|PHP ^8.4, ext-pdo, ext-pdo_mysql, four runtime libraries
F|f|composer.lock|authoritative; never updated on a server (IN-3)
F|f|phpstan.neon|level: max, no baseline
F|f|pint.json|psr12
F|f|rector.php|config, public, src, tests
F|f|infection.json5|MSI 100 over the unit suite (IMP-4.2)
F|f|README.md|

# ── src/Foundation — boot and composition ─────────────────────────────────────
F|d|src/Foundation
F?|f|src/Foundation/Application.php|composition root; boot() and isDebug()
F?|f|src/Foundation/Environment.php|
F?|f|src/Foundation/Services.php|explicit construction of shared collaborators
F?|f|src/Foundation/HttpKernelFactory.php|Router ← routes/web.php, routes/api.php

# ── src/Config — the only environment reader (CF-2) ───────────────────────────
F|d|src/Config
F?|f|src/Config/Config.php|immutable, dot-notation
F?|f|src/Config/Env.php|the ONLY reader of getenv/$_ENV (CF-2, TR-192)

# ── src/Support — leaf utilities every layer may use ──────────────────────────
F|d|src/Support
F?|f|src/Support/Env|typed environment values (consumed by Config only)
M1|d|src/Support/Clock|injected time — no wall-clock reads anywhere (TR-05, TST-2.4)
M1|f|src/Support/Clock/Clock.php|interface
M1|f|src/Support/Clock/SystemClock.php|
M1|d|src/Support/Identifier|correlation ids, opaque tokens, ULIDs
M1|f|src/Support/Identifier/IdentifierGenerator.php|interface
M1|f|src/Support/Identifier/UlidGenerator.php|sortable id for request correlation
M1|f|src/Support/Identifier/OpaqueToken.php|high-entropy session/token values
M1|d|src/Support/Pagination|offset pagination primitives; keyset is OT-08
M1|f|src/Support/Pagination/PageRequest.php|clamped bounds (TR-47)
M1|f|src/Support/Pagination/Page.php|
M1|f|src/Support/Pagination/PageMeta.php|the API meta envelope

# ── src/Exception — root-level failures ───────────────────────────────────────
F|d|src/Exception
F?|f|src/Exception/ConfigurationException.php|boot failure, loud (TR-194)

# ── src/Logging ───────────────────────────────────────────────────────────────
F|d|src/Logging
F?|f|src/Logging/LoggerFactory.php|JSON in production, readable elsewhere
M1|f|src/Logging/CorrelationProcessor.php|every line carries the request id (TR-134)
M1|f|src/Logging/RedactionProcessor.php|no credential, token, OTP or full record (TR-135)

# ── src/Error ─────────────────────────────────────────────────────────────────
F|d|src/Error
F?|f|src/Error/ErrorHandler.php|promote, log with reference id, render safely
F?|f|src/Error/HttpErrorHandler.php|HttpException renders as itself (TR-142)

# ── src/Http ──────────────────────────────────────────────────────────────────
F|d|src/Http
F?|f|src/Http/Request.php|readonly; fromGlobals(); expectsJson()
F?|f|src/Http/Response.php|readonly
F?|f|src/Http/Status.php|enum
F?|f|src/Http/Method.php|enum
F?|f|src/Http/Kernel.php|handle(Request): Response; no output (TR-13)
F?|f|src/Http/ResponseEmitter.php|
F|d|src/Http/Exception|every HTTP failure; suffixed Exception (arch test)
F?|f|src/Http/Exception/HttpException.php|
F?|f|src/Http/Exception/NotFoundException.php|404
F?|f|src/Http/Exception/MethodNotAllowedException.php|405 + Allow
F?|f|src/Http/Exception/MalformedRequestException.php|400
M1|f|src/Http/Exception/ValidationFailedException.php|422, field-level detail (TR-145)
M1|f|src/Http/Exception/ConflictException.php|409 — duplicate, concurrent edit, sold out
M1|f|src/Http/Exception/RateLimitExceededException.php|429; thresholds never disclosed
M1|f|src/Http/Exception/ForbiddenException.php|403; never distinguishes existence (TR-35)
M1|f|src/Http/Exception/UnauthenticatedException.php|401
M1|f|src/Http/Exception/GoneException.php|410
M1|f|src/Http/Exception/UnavailableException.php|503
F|d|src/Http/Routing
F?|f|src/Http/Routing/Router.php|register · match · generate only
F?|f|src/Http/Routing/RouteCollection.php|
F?|f|src/Http/Routing/Route.php|
M1|f|src/Http/Routing/RouteGroup.php|per-family policy defaults (§10.4 of the design)
M1|f|src/Http/Routing/UrlGenerator.php|named routes; canonical URLs (SEO-2)
F|d|src/Http/Middleware
F?|f|src/Http/Middleware/Middleware.php|process(Request, Closure): Response
F?|f|src/Http/Middleware/Pipeline.php|outermost first
F?|f|src/Http/Middleware/SecureHeaders.php|CSP, nosniff, frame, referrer, permissions
M1|f|src/Http/Middleware/RequestIdMiddleware.php|outermost; TR-12
M1|f|src/Http/Middleware/SessionMiddleware.php|resolve session → actor; no session for Guests (TR-14)
M1|f|src/Http/Middleware/CsrfMiddleware.php|cookie-authenticated writes only (TD-02)
M1|f|src/Http/Middleware/RateLimitMiddleware.php|auth, OTP, review, report, search, /ops writes
M1|f|src/Http/Middleware/SurfaceMiddleware.php|validate Mini App context; mark the surface
M1|f|src/Http/Middleware/AuthorizationMiddleware.php|named permission per route (TD-03)
M1|f|src/Http/Middleware/NoStoreMiddleware.php|authenticated and staff responses (TR-120)
M1|f|src/Http/Middleware/IdempotencyMiddleware.php|Idempotency-Key on retryable writes (TR-159)
F|d|src/Http/Controller|thin boundary only: no SQL, no rules, no HTML (TR-03)
F?|f|src/Http/Controller/HealthController.php|reference example
F|d|src/Http/Controller/Web|the public and account HTML surface
F|d|src/Http/Controller/Api/V1|the JSON contract
F|d|src/Http/Controller/Api/V1/Ops|staff JSON, separate authorization family
M1|d|src/Http/Responder|error and problem shapes — HTML and JSON
M1|f|src/Http/Responder/JsonErrorResponder.php|one envelope, stable code (TR-26, TR-145)
M1|f|src/Http/Responder/HtmlErrorResponder.php|safe copy, route forward (TR-146)
M1|d|src/Http/Validation|typed readers and rejection semantics
M1|f|src/Http/Validation/Validator.php|
M1|f|src/Http/Validation/ValidationResult.php|
M1|f|src/Http/Validation/Rule|length, range, allowed values, slug, email, coords
M1|f|src/Http/Validation/RejectsUnknownFields.php|unknown input rejected, not ignored (TRD §34)

# ── src/Database — the only namespace that may touch PDO ──────────────────────
F|d|src/Database
F?|f|src/Database/Connection.php|select · selectOne · execute · statement · transaction · ping
F?|f|src/Database/ConnectionFactory.php|the only `new PDO`
F?|f|src/Database/DatabaseConfig.php|readonly
F?|f|src/Database/DatabaseException.php|names driver and database, never credentials
F|d|src/Database/Migrations
F?|f|src/Database/Migrations/Migration.php|up(Connection) · down(Connection)
F?|f|src/Database/Migrations/MigrationLocator.php|filename order
F?|f|src/Database/Migrations/MigrationRepository.php|the `migrations` tracking table
F?|f|src/Database/Migrations/Migrator.php|apply · revert by batch; DDL never in a transaction
M1|d|src/Database/Query|shared SQL fragments with bound parameters only
M1|f|src/Database/Query/BoundQuery.php|SQL + parameters, never interpolated (TRD §34)

# ── src/Console ───────────────────────────────────────────────────────────────
F|d|src/Console
F?|f|src/Console/CommandRunner.php|
F?|f|src/Console/Command.php|interface
F?|f|src/Console/Output/Writer.php|
M1|f|src/Console/Output/ExitCode.php|
M1|d|src/Console/Command|one command per file
M1|f|src/Console/Command/QueueWorkCommand.php|queue:work (TD-06)
M1|f|src/Console/Command/MaintenancePruneCommand.php|maintenance:prune
M1|f|src/Console/Command/DiagnosticsReportCommand.php|
M2|f|src/Console/Command/ReferenceImportCommand.php|taxonomy/locations reference data (TR-177)
M2|f|src/Console/Command/SitemapGenerateCommand.php|sitemap.xml (TR-103)
M3|f|src/Console/Command/SearchReindexCommand.php|search:reindex (SD-4)
M3|f|src/Console/Command/AnalyticsRollupCommand.php|analytics:rollup
M3|f|src/Console/Command/ListingsFreshnessCommand.php|listings:freshness (D-08)
M6|f|src/Console/Command/StaffCreateCommand.php|staff account creation (SF-1); factor strength is D-45
M6|f|src/Console/Command/StaffDisableCommand.php|revokes sessions immediately (SF-7)
M7|f|src/Console/Command/MediaDerivativesCommand.php|regenerate derivatives (TR-83)
M9|f|src/Console/Command/DataRequestExecuteCommand.php|C-36 execution; windows PENDING COUNSEL (L-7)
M1|f|src/Console/Lock.php|job overlap lock with expiry (TR-153)

# ── src/Diagnostics ───────────────────────────────────────────────────────────
F|d|src/Diagnostics
F?|f|src/Diagnostics/HealthChecker.php|liveness never touches the database
F?|f|src/Diagnostics/HealthReport.php|
M1|f|src/Diagnostics/Check/StorageWritableCheck.php|readiness extension (architecture §13)
M3|f|src/Diagnostics/Check/SearchDocumentDriftCheck.php|SD-5
M6|f|src/Diagnostics/Check/QueueBacklogCheck.php|surfaces stalled jobs (TR-155)

# ── src/View — receives prepared data; no database, no HTTP ───────────────────
F|d|src/View
F?|f|src/View/PageRenderer.php|Phase 1 static-page renderer (D-17 open)
M1|d|src/View/Template|engine seam — D-17 keeps in-house vs library open
M1|f|src/View/Template/TemplateEngine.php|interface
M1|f|src/View/Template/PhpTemplateEngine.php|in-house implementation
M1|f|src/View/Escape/Escaper.php|context-correct by default (TR-99, APP-4)
M1|f|src/View/ViewFactory.php|
M1|f|src/View/Fragment.php|D-38 keeps fragment swaps possible
M1|d|src/View/Component|the few components that need code, not only markup
M1|f|src/View/Component/SponsoredLabel.php|label produced WITH the placement (TR-73)
M1|f|src/View/Component/RatingDisplay.php|no rating ≠ rating of zero (TR-63)
M1|f|src/View/Component/OpenStatus.php|unknown is never "closed" (TR-56)
M1|f|src/View/Component/BusinessCard.php|
M1|d|src/View/Metadata|head metadata and structured data
M1|f|src/View/Metadata/PageMetadata.php|unique title and description (SEO-5)
M1|f|src/View/Metadata/CanonicalLink.php|one canonical URL per page (SEO-2)
M1|f|src/View/Metadata/StructuredData.php|Business, hours, rating, breadcrumb (SEO-6)
M1|f|src/View/Sitemap/UrlsetWriter.php|generated from published Listings (TR-103)

# ── src/Outbound — TD-07: exactly one owner of outbound HTTP ──────────────────
M1|d|src/Outbound
M1|f|src/Outbound/OutboundHttp.php|the interface every integration depends on
M1|f|src/Outbound/Transport.php|transport seam
M1|f|src/Outbound/CurlTransport.php|THE ONLY PLACE curl_init MAY APPEAR (TR-01b)
M1|f|src/Outbound/NullTransport.php|degraded behaviour when a dependency is down
M1|f|src/Outbound/OutboundRequest.php|readonly
M1|f|src/Outbound/OutboundResponse.php|readonly
M1|f|src/Outbound/Timeout.php|
M1|f|src/Outbound/RetryPolicy.php|bounded attempts with backoff (TR-01c, TR-161)
M1|f|src/Outbound/OutboundHttpException.php|

# ── src/Platform — the Platform area's own tables ─────────────────────────────
M1|d|src/Platform/Queue
M1|f|src/Platform/Queue/QueuedJob.php|
M1|f|src/Platform/Queue/JobType.php|enum
M1|f|src/Platform/Queue/JobPayload.php|
M1|f|src/Platform/Queue/QueueRepository.php|owns `queued_jobs` (TR-16)
M1|f|src/Platform/Queue/JobRunner.php|bounded per run (TR-151)
M1|f|src/Platform/Queue/JobHandler.php|interface; idempotent by construction (TR-150)
M1|d|src/Platform/Queue/Handler|one handler per job type
M1|f|src/Platform/Queue/Handler/DispatchEmailHandler.php|
M1|f|src/Platform/Queue/Handler/GenerateDerivativesHandler.php|
M1|f|src/Platform/Queue/Handler/RebuildSearchDocumentHandler.php|
M1|d|src/Platform/Cache
M1|f|src/Platform/Cache/Cache.php|interface; optional by rule (TR-121)
M1|f|src/Platform/Cache/FilesystemCache.php|V1 default (TD-05)
M1|f|src/Platform/Cache/DatabaseCache.php|the alternative backend — OT-03
M1|f|src/Platform/Cache/NullCache.php|cold cache behaves identically
M1|f|src/Platform/Cache/CacheKey.php|every input in the key (TR-122)
M1|f|src/Platform/Cache/CacheEntry.php|
M1|f|src/Platform/Cache/CacheRepository.php|owns `cache_entries` if the DB backend is chosen
M1|d|src/Platform/Derived
M1|f|src/Platform/Derived/DerivedValue.php|DO-7: derived data is rebuildable
M1|f|src/Platform/Derived/RebuildCoordinator.php|

# ── src/Audit — append-only; a privileged action that cannot be recorded fails ─
M1|d|src/Audit
M1|d|src/Audit/Domain
M1|f|src/Audit/Domain/AuditEntry.php|
M1|f|src/Audit/Domain/ActorType.php|enum: operator · administrator · customer · system
M1|f|src/Audit/Domain/ActorRef.php|
M1|f|src/Audit/Domain/ActionKey.php|permission-scoped action vocabulary
M1|f|src/Audit/Domain/TargetRef.php|
M1|f|src/Audit/Domain/Reason.php|required where published data changes (TR-52)
M1|d|src/Audit/Application
M1|f|src/Audit/Application/AuditRecorder.php|fails the action on write failure (TR-08, invariant 6)
M1|f|src/Audit/Application/AuditReader.php|read is Administrator-only (TR-36)
M1|d|src/Audit/Repository
M1|f|src/Audit/Repository/AuditEntryRepository.php|append + read ONLY; no update, no delete (DO-8)
M1|f|src/Audit/Repository/AuditQuery.php|filter by actor, action, target, period

# ── src/Analytics — derived; never identifies a Guest ────────────────────────
M1|d|src/Analytics
M1|d|src/Analytics/Domain
M1|f|src/Analytics/Domain/EventType.php|the 12-event set, PRD §26.2
M1|f|src/Analytics/Domain/AnalyticsEvent.php|no IP, no fingerprint, no cross-session id (TR-202)
M1|f|src/Analytics/Domain/MetricKey.php|
M1|f|src/Analytics/Domain/Dimension.php|
M1|f|src/Analytics/Domain/Period.php|
M1|d|src/Analytics/Application
M1|f|src/Analytics/Application/RecordEvent.php|off the critical path (AN-4)
M1|f|src/Analytics/Application/RollupService.php|
M1|f|src/Analytics/Application/AnalyticsQuery.php|staff views read rollups only (AN-2)
M1|f|src/Analytics/Application/ZeroResultRecorder.php|no Guest identifier (ZR-1)
M1|d|src/Analytics/Repository
M1|f|src/Analytics/Repository/EventRepository.php|append-only
M1|f|src/Analytics/Repository/RollupRepository.php|
M8|f|src/Analytics/Repository/CampaignDeliveryRepository.php|reporting only; never feeds ranking (TR-74)

# ── src/Mail — composition separate from delivery (TR-88) ────────────────────
M1|d|src/Mail
M1|d|src/Mail/Domain
M1|f|src/Mail/Domain/MessageCategory.php|exactly the PRD §18 categories (TR-94)
M1|f|src/Mail/Domain/NotificationMessage.php|
M1|f|src/Mail/Domain/DeliveryState.php|enum: queued · sent · failed · suppressed
M1|d|src/Mail/Application
M1|f|src/Mail/Application/ComposeMessage.php|
M1|f|src/Mail/Application/EnqueueNotification.php|enqueue, never deliver inline (TR-91)
M1|f|src/Mail/Application/DeliveryService.php|
M1|d|src/Mail/Transport
M1|f|src/Mail/Transport/Transport.php|interface; provider replaceable by config (TR-89)
M1|f|src/Mail/Transport/LogTransport.php|V1 default while D-41 is open
M1|f|src/Mail/Transport/ProviderTransport.php|HTTP provider via Outbound — D-41
M1|f|src/Mail/Transport/TransportResult.php|
M1|d|src/Mail/Repository
M1|f|src/Mail/Repository/NotificationMessageRepository.php|
M1|f|src/Mail/Repository/DeliveryAttemptRepository.php|bounded retries (TR-161)

# ── src/Identity — one implementation of who the actor is ────────────────────
M4|d|src/Identity
M4|d|src/Identity/Domain
M4|f|src/Identity/Domain/Customer.php|
M4|f|src/Identity/Domain/CustomerState.php|enum: active · disabled
M4|f|src/Identity/Domain/EmailAddress.php|value object; normalisation before comparison (LK-2)
M4|f|src/Identity/Domain/DisplayName.php|
M4|f|src/Identity/Domain/ProviderIdentity.php|row, not column — the D-13 seam (TR-111)
M4|f|src/Identity/Domain/Provider.php|enum: google · email
M4|f|src/Identity/Domain/Session.php|
M4|f|src/Identity/Domain/SessionTransport.php|enum: cookie · bearer (TD-02)
M4|f|src/Identity/Domain/OtpRequest.php|hash only, single-use, session-bound (OTP-2, OTP-7)
M4|f|src/Identity/Domain/OtpAttempts.php|
M6|f|src/Identity/Domain/StaffUser.php|separate principal from Customer (ACC-8)
M6|f|src/Identity/Domain/StaffState.php|enum: active · disabled
M6|f|src/Identity/Domain/Role.php|
M6|f|src/Identity/Domain/Permission.php|named string, grouped by area (TD-03)
M6|f|src/Identity/Domain/StaffAuthFactor.php|a ROW, so D-45 needs no migration (TR-31)
M6|f|src/Identity/Domain/FactorType.php|enum: placeholder set pending D-45
M4|d|src/Identity/Application
M4|f|src/Identity/Application/SignInWithGoogle.php|server-side verification (GA-1)
M4|f|src/Identity/Application/RequestOtp.php|uniform response (OTP-8)
M4|f|src/Identity/Application/VerifyOtp.php|constant-time, single-use (OTP-3)
M4|f|src/Identity/Application/ResolveIdentity.php|the D-13 resolution seam, no auto-merge
M4|f|src/Identity/Application/EstablishSession.php|rotate on authentication (S-5)
M4|f|src/Identity/Application/RevokeSession.php|
M4|f|src/Identity/Application/SignOut.php|
M4|f|src/Identity/Application/UpdateProfile.php|
M9|f|src/Identity/Application/RequestAccountDeletion.php|
M9|f|src/Identity/Application/ExecuteAccountDeletion.php|revoke, detach, withdraw, audit without PII (DL-3)
M9|f|src/Identity/Application/ExportCustomerData.php|format PENDING COUNSEL (L-7)
M6|f|src/Identity/Application/CreateStaffUser.php|
M6|f|src/Identity/Application/DisableStaffUser.php|
M6|f|src/Identity/Application/GrantRole.php|
M4|d|src/Identity/Application/Authorization|called from middleware, enforced server-side
M4|f|src/Identity/Application/Authorization/Actor.php|Guest · Customer · Operator · Administrator
M4|f|src/Identity/Application/Authorization/ActorResolver.php|
M4|f|src/Identity/Application/Authorization/PermissionChecker.php|
M4|f|src/Identity/Application/Authorization/PermissionRegistry.php|permissions as data, never role checks (TR-37)
M4|f|src/Identity/Application/Authorization/DenialRecorder.php|failed staff attempts logged (TR-39)
M4|d|src/Identity/Repository
M4|f|src/Identity/Repository/CustomerRepository.php|
M4|f|src/Identity/Repository/ProviderIdentityRepository.php|
M4|f|src/Identity/Repository/SessionRepository.php|owns sessions for both actor kinds
M4|f|src/Identity/Repository/OtpRequestRepository.php|
M6|f|src/Identity/Repository/StaffUserRepository.php|
M6|f|src/Identity/Repository/RoleRepository.php|
M6|f|src/Identity/Repository/PermissionRepository.php|
M6|f|src/Identity/Repository/StaffAuthFactorRepository.php|

# ── src/Directory — C-01…C-12, C-19…C-24 ─────────────────────────────────────
M2|d|src/Directory
M2|d|src/Directory/Domain
M2|d|src/Directory/Domain/Business|brand-level attributes ONLY (DM-R1)
M2|f|src/Directory/Domain/Business/Business.php|
M2|f|src/Directory/Domain/Business/BusinessStatus.php|draft · in_review · published · unpublished · closed
M2|f|src/Directory/Domain/Business/Slug.php|stable across renames (DM-3)
M2|f|src/Directory/Domain/Business/Description.php|
M2|d|src/Directory/Domain/Branch|the physical, searchable location (D-55)
M2|f|src/Directory/Domain/Branch/Branch.php|
M2|f|src/Directory/Domain/Branch/BranchStatus.php|active · temporarily closed · permanently closed
M2|f|src/Directory/Domain/Branch/Address.php|
M2|f|src/Directory/Domain/Branch/Coordinates.php|
M2|f|src/Directory/Domain/Branch/PhoneNumber.php|may be a flagged personal contact point
M2|f|src/Directory/Domain/Branch/OpenStatus.php|unknown is a value, not closed (TR-56)
M2|f|src/Directory/Domain/Branch/HoursSpecification.php|STRUCTURE OPEN (D-04) — marker only until decided
M2|d|src/Directory/Domain/Classification
M2|f|src/Directory/Domain/Classification/Category.php|exactly two levels (D-06)
M2|f|src/Directory/Domain/Classification/Subcategory.php|
M2|f|src/Directory/Domain/Classification/CategoryAssignment.php|one primary + n secondaries (D-57)
M2|f|src/Directory/Domain/Classification/Alias.php|matching input, never a display label (AL-3)
M2|f|src/Directory/Domain/Classification/Visibility.php|
M2|d|src/Directory/Domain/Location
M2|f|src/Directory/Domain/Location/Area.php|curated, not free text
M2|f|src/Directory/Domain/Location/SubCity.php|stored, not browsable (GEO-6)
M2|f|src/Directory/Domain/Location/Landmark.php|optional
M2|d|src/Directory/Domain/Listing
M2|f|src/Directory/Domain/Listing/ListingState.php|the state machine of TRD §17.1
M2|f|src/Directory/Domain/Listing/PublicationGate.php|permission AND verification required
M2|f|src/Directory/Domain/Listing/Completeness.php|missing FIELDS listed, not only a score (D-09)
M2|f|src/Directory/Domain/Listing/Freshness.php|age is derivable by query (TR-54)
M2|d|src/Directory/Domain/Provenance
M2|f|src/Directory/Domain/Provenance/ProvenanceRecord.php|who collected it, from what source, when
M2|f|src/Directory/Domain/Provenance/SourceMethod.php|
M2|f|src/Directory/Domain/Provenance/PersonalContactPoint.php|flagged at the schema level (DO-6)
M2|d|src/Directory/Application
M2|f|src/Directory/Application/CreateListing.php|
M2|f|src/Directory/Application/EditListing.php|concurrency token enforced (TR-57)
M2|f|src/Directory/Application/SubmitForReview.php|
M2|f|src/Directory/Application/PublishListing.php|refuses without Permission and Verification
M2|f|src/Directory/Application/UnpublishListing.php|reason required
M2|f|src/Directory/Application/CloseListing.php|a state, never a delete (TR-53)
M2|f|src/Directory/Application/ManageBranches.php|at least one; exactly one primary
M2|f|src/Directory/Application/DetectDuplicates.php|deterministic name + Area + contact (TR-55)
M2|f|src/Directory/Application/ReadCategoryTree.php|
M2|f|src/Directory/Application/ReadAreaList.php|
M2|f|src/Directory/Application/ReadBusinessProfile.php|Business + selected Branch composition
M2|f|src/Directory/Application/ClassifyListing.php|primary/secondary rules in one place
M6|f|src/Directory/Application/ManageCategories.php|Administrator-only; merge keeps a redirect
M6|f|src/Directory/Application/MergeCategories.php|
M6|f|src/Directory/Application/ManageAreas.php|Administrator-only
M2|f|src/Directory/Application/GenerateSitemap.php|published Listings and taxonomy (TR-103)
M2|d|src/Directory/Repository
M2|f|src/Directory/Repository/BusinessRepository.php|owns `businesses`
M2|f|src/Directory/Repository/BranchRepository.php|owns `branches`
M2|f|src/Directory/Repository/CategoryRepository.php|owns `categories`
M2|f|src/Directory/Repository/SubcategoryRepository.php|
M2|f|src/Directory/Repository/AliasRepository.php|
M2|f|src/Directory/Repository/AreaRepository.php|
M2|f|src/Directory/Repository/SubCityRepository.php|
M2|f|src/Directory/Repository/LandmarkRepository.php|
M2|f|src/Directory/Repository/CategoryAssignmentRepository.php|owns `business_category`
M2|f|src/Directory/Repository/RedirectRepository.php|old path → new path (SEO-4)
M2|f|src/Directory/Repository/BusinessQuery.php|read models for lists; batched, no N+1 (TR-127)

# ── src/Operations — C-19…C-21, C-26 and the data-quality machinery ──────────
M2|d|src/Operations
M2|d|src/Operations/Domain
M2|f|src/Operations/Domain/PermissionRecord.php|evidences that Permission was obtained (D-50)
M2|f|src/Operations/Domain/PermissionScope.php|
M2|f|src/Operations/Domain/VerificationRecord.php|method, date, Operator (D-08 shape, policy open)
M2|f|src/Operations/Domain/VerificationMethod.php|
M2|f|src/Operations/Domain/Correction.php|field, before, after, reason, source, actor, time
M2|f|src/Operations/Domain/ListingChange.php|
M2|f|src/Operations/Domain/PublicationReadiness.php|the VO the publish gate consumes
M2|f|src/Operations/Domain/FreshnessPolicy.php|interval is CONFIGURATION (D-08)
M5|f|src/Operations/Domain/Report.php|subject is a Listing or a Review
M5|f|src/Operations/Domain/ReportSubject.php|enum
M5|f|src/Operations/Domain/ReportGround.php|from the published list only
M5|f|src/Operations/Domain/ReportState.php|enum
M2|d|src/Operations/Application
M2|f|src/Operations/Application/RecordPermission.php|
M2|f|src/Operations/Application/WithdrawPermission.php|withdrawal unpublishes or blocks
M2|f|src/Operations/Application/RecordVerification.php|
M2|f|src/Operations/Application/EvaluatePublicationReadiness.php|the interface Directory depends on
M2|f|src/Operations/Application/ApplyCorrection.php|flags re-verification where material (COR-2)
M2|f|src/Operations/Application/BuildReverificationQueue.php|derived from last verification (TR-54)
M5|f|src/Operations/Application/SubmitReport.php|Guest-safe for Listing reports (TS-2)
M5|f|src/Operations/Application/TriageReport.php|
M5|f|src/Operations/Application/ResolveReport.php|links the Correction that resolves it (COR-4)
M2|d|src/Operations/Repository
M2|f|src/Operations/Repository/PermissionRecordRepository.php|
M2|f|src/Operations/Repository/VerificationRecordRepository.php|
M2|f|src/Operations/Repository/CorrectionRepository.php|
M5|f|src/Operations/Repository/ReportRepository.php|
M2|f|src/Operations/Repository/WorkQueueQuery.php|queues are queries, not tables

# ── src/Community — C-13, C-14, C-25, C-34, C-35 ─────────────────────────────
M5|d|src/Community
M5|d|src/Community/Domain
M5|f|src/Community/Domain/Review.php|subject is ALWAYS a Branch (D-34, D-55)
M5|f|src/Community/Domain/ReviewState.php|pending · published · rejected · removed · deleted
M5|f|src/Community/Domain/Rating.php|integer 1..5, required (TR-69 §18)
M5|f|src/Community/Domain/ReviewText.php|optional, so rating-only is valid
M5|f|src/Community/Domain/EditWindow.php|30 days, server-side only (TR-71)
M5|f|src/Community/Domain/Withdrawal.php|public visibility ceases; row survives (TR-72)
M5|f|src/Community/Domain/ReviewModeration.php|outcome + policy ground + actor + reason
M5|f|src/Community/Domain/PolicyGround.php|a decision without a ground is invalid (invariant 10)
M5|f|src/Community/Domain/ReviewReport.php|
M5|f|src/Community/Domain/RatingSummary.php|unweighted mean of published ratings (SUM-6)
M4|f|src/Community/Domain/Save.php|owns `saves`; the canonical verb is Save
M5|d|src/Community/Application
M5|f|src/Community/Application/SubmitReview.php|created pending; moderation precedes publication
M5|f|src/Community/Application/EditReview.php|re-enters moderation (TR-71)
M5|f|src/Community/Application/WithdrawReview.php|
M5|f|src/Community/Application/ReadPublishedReviews.php|newest first (TR-73)
M5|f|src/Community/Application/RecomputeRatingSummary.php|Branch, and Business by aggregation
M5|f|src/Community/Application/ModerateReview.php|publish · reject · remove ONLY; never edit (MOD-4)
M5|f|src/Community/Application/SubmitReviewReport.php|reporter identity never exposed (TR-65)
M4|f|src/Community/Application/SaveBusiness.php|idempotent; uniqueness in the database (TR-160)
M4|f|src/Community/Application/RemoveSave.php|
M4|f|src/Community/Application/ListSaved.php|unpublished Businesses keep their status (C-34)
M5|d|src/Community/Repository
M5|f|src/Community/Repository/ReviewRepository.php|owns `reviews`
M5|f|src/Community/Repository/ReviewModerationRepository.php|
M5|f|src/Community/Repository/ReviewReportRepository.php|
M5|f|src/Community/Repository/RatingSummaryRepository.php|derived, rebuildable
M4|f|src/Community/Repository/SaveRepository.php|

# ── src/Advertising — C-16, C-27; built disabled until D-39 ──────────────────
M8|d|src/Advertising
M8|d|src/Advertising/Domain
M8|f|src/Advertising/Domain/Placement.php|a named, finite slot (ADV-3)
M8|f|src/Advertising/Domain/PlacementKey.php|enum: search_results · category_page · homepage
M8|f|src/Advertising/Domain/Package.php|placement + target + duration + fixed price
M8|f|src/Advertising/Domain/Price.php|the column exists; no value is approved
M8|f|src/Advertising/Domain/Campaign.php|
M8|f|src/Advertising/Domain/CampaignState.php|draft · pending_approval · active · suspended · ended · cancelled
M8|f|src/Advertising/Domain/CampaignTarget.php|category, subcategory or area only
M8|f|src/Advertising/Domain/CampaignEligibility.php|approved AND in period AND Business eligible
M8|f|src/Advertising/Domain/Inventory.php|overlap refused at creation (TR-72)
M8|f|src/Advertising/Domain/SponsoredPlacement.php|label is part of the value object
M8|f|src/Advertising/Domain/CampaignDelivery.php|reporting only (TR-74)
M8|d|src/Advertising/Application
M8|f|src/Advertising/Application/CreateCampaign.php|
M8|f|src/Advertising/Application/ApproveCampaign.php|Administrator-only
M8|f|src/Advertising/Application/SuspendCampaign.php|
M8|f|src/Advertising/Application/CancelCampaign.php|
M8|f|src/Advertising/Application/CheckPlacementAvailability.php|
M8|f|src/Advertising/Application/SelectSponsoredPlacements.php|runs AFTER organic; must not read ranking
M8|f|src/Advertising/Application/RecordSponsoredImpression.php|a failure must not fail the page (TR-77)
M8|f|src/Advertising/Application/RecordSponsoredClick.php|
M8|f|src/Advertising/Application/ReadCampaignReporting.php|
M8|d|src/Advertising/Repository
M8|f|src/Advertising/Repository/PlacementRepository.php|
M8|f|src/Advertising/Repository/PackageRepository.php|
M8|f|src/Advertising/Repository/CampaignRepository.php|
M8|f|src/Advertising/Repository/CampaignTargetRepository.php|
M8|f|src/Advertising/Repository/CampaignDeliveryRepository.php|

# ── src/Search — C-02, C-03, C-07; MariaDB only, never a cluster ──────────────
M3|d|src/Search
M3|d|src/Search/Domain
M3|f|src/Search/Domain/QueryText.php|raw input
M3|f|src/Search/Domain/NormalisedText.php|output of the ONE normaliser (N-8)
M3|f|src/Search/Domain/MatchStage.php|enum: exact · fulltext · prefix · relaxed
M3|f|src/Search/Domain/Candidate.php|
M3|f|src/Search/Domain/RankedResult.php|
M3|f|src/Search/Domain/RankingInputs.php|the admissible inputs of search-design §9.1
M3|f|src/Search/Domain/RankingPolicy.php|weights from CONFIG; none invented (OR-1, OR-2)
M3|f|src/Search/Domain/Suggestion.php|
M3|f|src/Search/Domain/ZeroResult.php|
M3|f|src/Search/Domain/BoundingBox.php|
M3|f|src/Search/Domain/Distance.php|straight-line, presented as approximate
M3|d|src/Search/Text
M3|f|src/Search/Text/Normaliser.php|N-1…N-8; shared by index, query and suggestion
M3|f|src/Search/Text/Tokeniser.php|Amharic-safe; stopwords and min length from config
M3|f|src/Search/Text/QueryInterpreter.php|
M3|f|src/Search/Text/AliasMatcher.php|aliases change matching, never ranking (AL-4)
M3|d|src/Search/Ranking
M3|f|src/Search/Ranking/RankingPipeline.php|
M3|f|src/Search/Ranking/Tiebreaker.php|deterministic, stable across pages (SRCH-6)
M3|f|src/Search/Ranking/TextMatchSignal.php|name beats description, magnitude unset
M3|f|src/Search/Ranking/VerificationSignal.php|
M3|f|src/Search/Ranking/CompletenessSignal.php|
M3|f|src/Search/Ranking/RatingSignal.php|average AND count together
M3|f|src/Search/Ranking/DistanceSignal.php|only when proximity was asked for
M3|d|src/Search/Application
M3|f|src/Search/Application/Search.php|the single entry point (FM-1)
M3|f|src/Search/Application/Suggest.php|fails without breaking plain search (PF-4)
M3|f|src/Search/Application/RebuildSearchDocuments.php|safe to re-run (SD-4)
M3|f|src/Search/Application/SearchDocumentWriter.php|synchronous on publish/edit (SD-2)
M2|f|src/Search/Application/WriteSearchDocument.php|interface Directory calls on state change
M3|d|src/Search/Repository
M3|f|src/Search/Repository/SearchDocumentRepository.php|owns `search_documents`
M3|f|src/Search/Repository/ZeroResultRepository.php|no Guest identifier
M3|f|src/Search/Port/SearchPort.php|the interface the API and Web depend on (TR-46)

# ── src/Media — C-22; staff-only upload, never executable ────────────────────
M2|d|src/Media
M2|d|src/Media/Domain
M2|f|src/Media/Domain/MediaAsset.php|
M2|f|src/Media/Domain/MediaAttachment.php|may attach to a Business OR a Branch
M2|f|src/Media/Domain/AttachmentLevel.php|enum
M2|f|src/Media/Domain/ImageType.php|allow-list (TR-80)
M2|f|src/Media/Domain/Derivative.php|
M2|f|src/Media/Domain/DerivativeSize.php|
M2|d|src/Media/Application
M2|f|src/Media/Application/StoreUpload.php|content inspection, not extension (TR-80)
M2|f|src/Media/Application/AttachMedia.php|
M2|f|src/Media/Application/DetachMedia.php|
M2|f|src/Media/Application/DeleteMedia.php|
M2|f|src/Media/Application/GenerateDerivatives.php|queued or synchronous per OT-09
M2|f|src/Media/Application/StreamMedia.php|the explicit delivery endpoint (TR-81)
M2|d|src/Media/Storage
M2|f|src/Media/Storage/StorageAdapter.php|interface; local default, object storage MAY be configured
M2|f|src/Media/Storage/LocalFilesystemStorage.php|V1 default (TR-84)
M2|f|src/Media/Storage/ObjectStorage.php|D-25, constrained by D-42
M2|f|src/Media/Storage/StorageKey.php|adapter-agnostic key
M2|d|src/Media/Inspection
M2|f|src/Media/Inspection/ContentInspector.php|finfo + decode, never the client MIME
M2|f|src/Media/Inspection/ImageDecoder.php|re-encode rather than trust bytes (TR-82)
M2|d|src/Media/Repository
M2|f|src/Media/Repository/MediaAssetRepository.php|
M2|f|src/Media/Repository/MediaAttachmentRepository.php|

# ── src/Surface — PA-2.2: capabilities, not host handles ─────────────────────
M1|d|src/Surface
M1|f|src/Surface/SurfaceAdapter.php|the interface shared presentation consumes
M1|f|src/Surface/SurfaceCapabilities.php|"can share", "has safe-area insets" — never a host object
M1|f|src/Surface/SurfaceKind.php|enum: web · telegram
M1|f|src/Surface/WebAdapter.php|
M1|f|src/Surface/SurfaceResolver.php|

# ── src/Telegram — the only namespace that may name Telegram ────────────────
M7|d|src/Telegram
M7|f|src/Telegram/TelegramAdapter.php|implements SurfaceAdapter
M7|f|src/Telegram/Launch/LaunchContext.php|
M7|f|src/Telegram/Launch/ContextValidator.php|server-side signature + freshness (TR-112)
M7|f|src/Telegram/Host/HostClient.php|Bot API through Outbound
M7|f|src/Telegram/Host/HostCapabilities.php|detect, never demand (PD-06)
M7|f|src/Telegram/Host/ThemeMapper.php|host theme → Bulbula semantic tokens
M7|f|src/Telegram/Host/ExternalLink.php|host link opening, with fallback

# ── routes/ ──────────────────────────────────────────────────────────────────
F|d|routes/web|the public, account and ops families
F|d|routes/api|the /api/v1 families
M1|f|routes/web/home.php|GET / (C-01)
M1|f|routes/web/static.php|C-18 pages, mechanism only
M3|f|routes/web/search.php|C-02, C-03
M2|f|routes/web/taxonomy.php|C-04: /categories, /c/{category}, /c/{category}/{sub}
M2|f|routes/web/locations.php|C-05: /areas, /a/{area}
M3|f|routes/web/intersection.php|C-06: /c/{category}/in/{area}
M3|f|routes/web/nearby.php|C-07
M2|f|routes/web/business.php|C-08…C-13, C-15, C-17: /b/{business}
M4|f|routes/web/saved.php|C-14, C-34: /saved (authenticated inside the public prefix)
M4|f|routes/web/account.php|C-33, C-35, C-36: /account/*
M4|f|routes/web/auth.php|C-30, C-31: /signin (separate ops entry too)
M2|f|routes/web/health.php|/health and /health/ready
M6|f|routes/web/ops-dashboard.php|
M6|f|routes/web/ops-listings.php|
M6|f|routes/web/ops-taxonomy.php|
M6|f|routes/web/ops-locations.php|
M6|f|routes/web/ops-reviews.php|
M5|f|routes/web/ops-reports.php|
M8|f|routes/web/ops-advertising.php|
M6|f|routes/web/ops-analytics.php|
M6|f|routes/web/ops-audit.php|
M6|f|routes/web/ops-staff.php|
M1|f|routes/api/health.php|/api/v1/health, /health/ready
M2|f|routes/api/discovery.php|/categories, /areas, /discovery/home
M3|f|routes/api/search.php|/search, /search/suggest
M2|f|routes/api/businesses.php|/businesses/{slug}
M5|f|routes/api/reviews.php|/branches/{id}/reviews, /reviews/{id}/reports
M4|f|routes/api/auth.php|/auth/google, /auth/otp/request, /auth/otp/verify, /auth/signout
M4|f|routes/api/me.php|/me, /me/saves
M5|f|routes/api/my-reviews.php|/me/reviews
M9|f|routes/api/privacy.php|/me/data, /me/deletion, /me
M6|f|routes/api/ops-businesses.php|
M6|f|routes/api/ops-taxonomy.php|
M6|f|routes/api/ops-locations.php|
M6|f|routes/api/ops-media.php|
M6|f|routes/api/ops-moderation.php|
M5|f|routes/api/ops-reports.php|
M8|f|routes/api/ops-campaigns.php|
M6|f|routes/api/ops-analytics.php|
M6|f|routes/api/ops-audit.php|

# ── resources/views — receive prepared data only ─────────────────────────────
M1|d|resources/views/layouts|app · console · auth · error · email
M1|f|resources/views/layouts/app.html|
M1|f|resources/views/layouts/console.html|
M1|f|resources/views/layouts/auth.html|
M1|f|resources/views/layouts/error.html|
M1|f|resources/views/layouts/email.html|
M1|d|resources/views/partials|
M1|f|resources/views/partials/head.html|metadata, canonical, structured data
M1|f|resources/views/partials/header.html|component 1
M1|f|resources/views/partials/footer.html|component 2
M1|f|resources/views/partials/discovery-bar.html|component 3
M1|f|resources/views/partials/breadcrumb.html|component 4
M1|f|resources/views/partials/account-menu.html|component 5
M1|f|resources/views/partials/pagination.html|component 27
M1|f|resources/views/partials/flash.html|component 28
M1|d|resources/views/components|the 44 specified components, one partial each
M1|f|resources/views/components/search-field.html|6
M1|f|resources/views/components/autocomplete.html|7
M1|f|resources/views/components/filter-panel.html|8
M1|f|resources/views/components/filter-chips.html|9
M1|f|resources/views/components/sort-control.html|10
M2|f|resources/views/components/business-card.html|11
M2|f|resources/views/components/result-list.html|12
M2|f|resources/views/components/taxonomy-tile.html|13
M5|f|resources/views/components/rating-display.html|14
M4|f|resources/views/components/save-control.html|15
M2|f|resources/views/components/verified-indicator.html|16
M2|f|resources/views/components/hours-open-status.html|17
M2|f|resources/views/components/contact-actions.html|18
M8|f|resources/views/components/sponsored-placement.html|19 — label cannot be omitted (TR-73)
M7|f|resources/views/components/map-block.html|20 — static-first, deferred
M2|f|resources/views/components/photo-gallery.html|21
M7|f|resources/views/components/share-control.html|22
M5|f|resources/views/components/report-form.html|23
M1|f|resources/views/components/empty-state.html|24
M1|f|resources/views/components/loading-state.html|25
M1|f|resources/views/components/error-state.html|26
M1|f|resources/views/components/modal.html|29
M1|f|resources/views/components/bottom-sheet.html|30
M1|f|resources/views/components/confirmation-dialog.html|31
M1|f|resources/views/components/form-field.html|32
M4|f|resources/views/components/otp-input.html|33
M4|f|resources/views/components/auth-prompt.html|34
M1|f|resources/views/components/button.html|35
M1|f|resources/views/components/link.html|36
M6|f|resources/views/components/data-table.html|37
M6|f|resources/views/components/console-filter-bar.html|38
M6|f|resources/views/components/status-badge.html|39
M6|f|resources/views/components/record-detail-panel.html|40
M6|f|resources/views/components/audit-trail-list.html|41
M6|f|resources/views/components/duplicate-panel.html|42
M8|f|resources/views/components/campaign-form.html|43
M6|f|resources/views/components/completeness-indicator.html|44
M1|d|resources/views/pages
M1|f|resources/views/pages/home.html|C-01
M3|f|resources/views/pages/search/results.html|C-02
M3|f|resources/views/pages/search/zero-results.html|the designed zero-result destination (ZR-1…ZR-5)
M2|f|resources/views/pages/taxonomy/categories-index.html|C-04
M2|f|resources/views/pages/taxonomy/category.html|
M2|f|resources/views/pages/taxonomy/subcategory.html|
M2|f|resources/views/pages/locations/areas-index.html|C-05
M2|f|resources/views/pages/locations/area.html|
M3|f|resources/views/pages/intersection/category-in-area.html|C-06
M3|f|resources/views/pages/nearby/index.html|C-07
M2|f|resources/views/pages/business/profile.html|C-08…C-12, C-17
M5|f|resources/views/pages/business/reviews.html|C-13 read
M5|f|resources/views/pages/business/review-form.html|C-13 write, auth
M5|f|resources/views/pages/business/report.html|C-15
M4|f|resources/views/pages/saved/index.html|C-34
M4|f|resources/views/pages/account/profile.html|C-33
M5|f|resources/views/pages/account/reviews.html|C-35
M9|f|resources/views/pages/account/privacy.html|C-36
M9|f|resources/views/pages/account/deletion-confirm.html|C-36
M4|f|resources/views/pages/auth/signin.html|C-30, C-31
M4|f|resources/views/pages/auth/otp-verify.html|
M1|f|resources/views/pages/static/about.html|C-18 — mechanism now, text PENDING COUNSEL
M1|f|resources/views/pages/static/privacy.html|
M1|f|resources/views/pages/static/terms.html|
M1|f|resources/views/pages/static/advertising.html|explains sponsorship; sells nothing (D-10)
M1|f|resources/views/pages/static/corrections.html|the correction route for Business owners
M1|f|resources/views/pages/static/how-we-verify.html|
M1|f|resources/views/pages/static/how-ranking-works.html|OR-4: explainable in plain language
M1|f|resources/views/pages/static/review-policy.html|
M1|f|resources/views/pages/static/contact.html|
M6|f|resources/views/pages/ops/dashboard.html|
M6|f|resources/views/pages/ops/listings/index.html|
M6|f|resources/views/pages/ops/listings/form.html|
M6|f|resources/views/pages/ops/listings/detail.html|
M6|f|resources/views/pages/ops/listings/changes.html|
M6|f|resources/views/pages/ops/listings/duplicates.html|
M6|f|resources/views/pages/ops/taxonomy/index.html|
M6|f|resources/views/pages/ops/taxonomy/merge.html|
M6|f|resources/views/pages/ops/locations/index.html|
M6|f|resources/views/pages/ops/reviews/queue.html|
M5|f|resources/views/pages/ops/reports/queue.html|
M8|f|resources/views/pages/ops/advertising/campaigns.html|
M8|f|resources/views/pages/ops/advertising/campaign-form.html|
M6|f|resources/views/pages/ops/analytics/index.html|
M6|f|resources/views/pages/ops/audit/index.html|
M6|f|resources/views/pages/ops/staff/index.html|
M1|d|resources/views/fragments|D-38: the same content as swappable fragments
M1|f|resources/views/fragments/result-list.html|
M1|f|resources/views/fragments/filter-panel.html|
M4|f|resources/views/fragments/save-control.html|
M1|d|resources/views/errors|404 · 405 · 410 · 419 · 429 · 500 · 503
M1|f|resources/views/errors/404.html|
M1|f|resources/views/errors/500.html|
M1|f|resources/views/errors/503.html|
M1|d|resources/views/email|one template per PRD §18 category
M4|f|resources/views/email/auth/sign-in-code.html|
M9|f|resources/views/email/account/deletion-requested.html|
M9|f|resources/views/email/account/deletion-completed.html|
M9|f|resources/views/email/account/identity-linked.html|
M5|f|resources/views/email/review/published.html|
M5|f|resources/views/email/review/rejected.html|
M5|f|resources/views/email/review/removed.html|
M5|f|resources/views/email/report/received.html|
M5|f|resources/views/email/report/outcome.html|
M1|f|resources/views/email/service/policy-change.html|
M6|f|resources/views/email/staff/queue-escalation.html|
M1|f|resources/views/sitemap/urlset.xml|
M1|d|resources/lang/en|no hard-coded user-visible strings (TR-105, D-18)
M1|f|resources/lang/en/public.php|
M1|f|resources/lang/en/console.php|
M1|f|resources/lang/en/email.php|
M1|f|resources/lang/en/validation.php|
M1|f|resources/lang/en/errors.php|
M1|d|resources/content|static page BODIES as data; legal text PENDING COUNSEL

# ── public/assets ────────────────────────────────────────────────────────────
M1|d|public/assets/css
M1|f|public/assets/css/tokens.css|the semantic tier ONLY (design-system §1)
M1|f|public/assets/css/base.css|
M1|f|public/assets/css/components.css|the 44 components
M1|f|public/assets/css/surface-public.css|
M6|f|public/assets/css/surface-console.css|
M1|f|public/assets/css/print.css|
M1|d|public/assets/js
M1|f|public/assets/js/boot.js|capability detection, no framework (D-16)
M1|d|public/assets/js/adapter|the ONLY client files that may name a surface
M1|f|public/assets/js/adapter/web.js|
M7|f|public/assets/js/adapter/telegram.js|the only file that may reference window.Telegram
M1|d|public/assets/js/enhance|progressive enhancement only (TR-97)
M3|f|public/assets/js/enhance/autocomplete.js|
M3|f|public/assets/js/enhance/filters.js|
M7|f|public/assets/js/enhance/map-deferred.js|
M7|f|public/assets/js/enhance/share.js|
M4|f|public/assets/js/enhance/save.js|
M1|f|public/assets/js/enhance/sheet.js|
M6|f|public/assets/js/enhance/console-queues.js|
M1|d|public/assets/img|
M1|f|public/assets/img/missing-image.svg|no placeholder implying a missing photograph (TR-86)
M1|f|public/assets/manifest.json|content hash → filename for long max-age (SA-2)

# ── database/ ────────────────────────────────────────────────────────────────
M2|d|database/reference|curated reference DATA, never application schema (D-56, TR-177)
M2|f|database/reference/categories.php|synthetic fixture until the catalogue is produced (D-56)
M2|f|database/reference/subcategories.php|
M2|f|database/reference/areas.php|boundary unsettled (D-40)
M2|f|database/reference/sub-cities.php|
M2|f|database/reference/aliases.php|Amharic and colloquial Aliases
M8|f|database/reference/placements.php|[P] slot definitions until approved
M8|f|database/reference/packages.php|no price is approved
M6|f|database/reference/permissions.php|named permissions; role grants provisional (D-14)
M1|d|database/fixtures/dev|synthetic development dataset — never real personal data (IMP-6.5)
M1|d|database/fixtures/test|

# ── storage/ — runtime, git-ignored, disposable ──────────────────────────────
F|i|storage/logs|rotated and pruned (LG-4)
F|i|storage/cache|disposable (PM-8)
M1|i|storage/locks|job overlap locks (TR-153)
F|i|storage/tmp|
M2|i|storage/media/originals|retained so derivatives can be regenerated (TR-83)
M2|i|storage/media/derivatives|rebuildable
M9|i|storage/exports|temporary data exports (C-36); retention PENDING COUNSEL

# ── tests/ ───────────────────────────────────────────────────────────────────
F|d|tests/Arch
F|f|tests/Arch/ArchTest.php|the 18 existing assertions, extended to 32 (design §8)
M1|f|tests/Arch/BoundariesTest.php|layer and area independence
M1|f|tests/Arch/NamingTest.php|suffixes, final, strict types, backed enums
M1|f|tests/Arch/ForbiddenConstructsTest.php|no dd/dump/die/var_dump/sleep/exit outside bin/console
M1|f|tests/Arch/NamespaceGuardTest.php|TR-01: every new namespace is guarded in the same change
M1|f|tests/Arch/AbsentCapabilityTest.php|no business account, claim, reply, password, bid, auction
F|d|tests/Unit
M1|d|tests/Unit/Foundation
M1|d|tests/Unit/Support
M1|d|tests/Unit/Http
M1|d|tests/Unit/Database
M1|d|tests/Unit/Outbound
M1|d|tests/Unit/Platform
M1|d|tests/Unit/Audit
M1|d|tests/Unit/Analytics
M1|d|tests/Unit/Mail
M1|d|tests/Unit/View
M4|d|tests/Unit/Identity
M2|d|tests/Unit/Directory
M2|d|tests/Unit/Operations
M5|d|tests/Unit/Community
M8|d|tests/Unit/Advertising
M3|d|tests/Unit/Search
M2|d|tests/Unit/Media
M7|d|tests/Unit/Telegram
F|d|tests/Feature
M1|d|tests/Feature/Web
M1|d|tests/Feature/Api
M6|d|tests/Feature/Ops
M1|d|tests/Feature/Cli
M1|d|tests/Feature/Contract|per api-spec-v1.0.md
M1|d|tests/Feature/Invariants|the 20 invariants of test-strategy §3, one file each
M1|f|tests/Feature/Invariants/OrganicRankingUnaffectedByCampaignTest.php|invariant 1 — the most important test
M1|f|tests/Feature/Invariants/PublicationRequiresPermissionTest.php|invariant 2 — every publishing path
M1|f|tests/Feature/Invariants/AuditRecordedForEveryStaffMutationTest.php|invariant 3
M1|f|tests/Feature/Invariants/GuestAnalyticsCarriesNoIdentityTest.php|invariant 4
M1|f|tests/Feature/Invariants/DiscoveryWorksWithoutExternalDependenciesTest.php|invariant 5
M1|f|tests/Feature/Invariants/AuditFailureFailsTheActionTest.php|invariant 6
M1|f|tests/Feature/Invariants/SponsoredPlacementAlwaysLabelledTest.php|invariant 7
M1|f|tests/Feature/Invariants/SponsoredDoesNotDisplaceOrganicTest.php|invariant 8
M1|f|tests/Feature/Invariants/ModerationCannotEditReviewTest.php|invariant 9
M1|f|tests/Feature/Invariants/ModerationRequiresPolicyGroundTest.php|invariant 10
M1|f|tests/Feature/Invariants/ReporterIdentityNeverExposedTest.php|invariant 11
M1|f|tests/Feature/Invariants/GuestJourneyWithoutSessionTest.php|invariant 12
M1|f|tests/Feature/Invariants/AuthorizationEnforcedServerSideTest.php|invariant 13
M1|f|tests/Feature/Invariants/ProductionErrorsCarryNoInternalsTest.php|invariant 14
M1|f|tests/Feature/Invariants/LogsCarryNoSecretsTest.php|invariant 15
M1|f|tests/Feature/Invariants/DocumentRootHasSingleEntryPointTest.php|invariant 16
M1|f|tests/Feature/Invariants/CampaignWindowEvaluatedAtRequestTimeTest.php|invariant 17
M1|f|tests/Feature/Invariants/ClosureIsStateNotDeletionTest.php|invariant 18
M1|f|tests/Feature/Invariants/NoExcludedCapabilityExistsTest.php|invariant 19
M1|f|tests/Feature/Invariants/ScheduledJobsAreIdempotentTest.php|invariant 20
M1|d|tests/Support
M1|f|tests/Support/ApplicationFactory.php|test composition root
M1|f|tests/Support/InMemoryDatabase.php|SQLite; migrations applied per test
M1|f|tests/Support/RequestBuilder.php|
M1|f|tests/Support/FrozenClock.php|no test depends on wall-clock time (TST-2.4)
M1|f|tests/Support/FakeOutboundHttp.php|no test reaches a real external service (TST-2.3)
M1|f|tests/Support/FakeMailTransport.php|
M1|f|tests/Support/FakeStorageAdapter.php|
M1|f|tests/Support/FixtureLoader.php|
M1|f|tests/Support/AccessibilityAssertions.php|landmarks, headings, keyboard, contrast
M1|d|tests/Fixtures

# ── tools/ ───────────────────────────────────────────────────────────────────
F|f|tools/verify-docs.py|absent from main @ 4c44b50 (FND-04)
F|f|tools/verify-ai-context.py|absent from main @ 4c44b50
F|f|tools/qa35.py|absent from main @ 4c44b50
F|f|tools/qa36.py|absent from main @ 4c44b50
M1|f|tools/verify-structure.py|PROPOSED: check the tree against the manifest
M1|f|tools/scaffold-structure.sh|this file — the map, as data
TREE_EOF
)

usage() {
    cat <<'USAGE'
Usage: tools/scaffold-structure.sh [MODE] [options]

Modes (exactly one; default --plan):
  --plan                 Print the planned tree. Writes nothing.
  --manifest             Print TSV: milestone<TAB>kind<TAB>path<TAB>note
  --preview <dir>        Materialise a throwaway preview tree (dirs, .gitkeep,
                         and clearly-marked placeholder stubs). Refuses to
                         target the repository root or any directory that
                         already contains a composer.json.

Options:
  --milestone=M0..M9|F   Only entries for this milestone.
  --dirs-only            With --preview: create directories and .gitkeep only.
  -h, --help
USAGE
}

MODE="plan"
TARGET=""
FILTER=""
DIRS_ONLY="0"

while [ $# -gt 0 ]; do
    case "$1" in
        --plan)      MODE="plan" ;;
        --manifest)  MODE="manifest" ;;
        --preview)   MODE="preview"; shift; TARGET="${1:-}" ;;
        --milestone=*) FILTER="${1#*=}" ;;
        --dirs-only) DIRS_ONLY="1" ;;
        -h|--help)   usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

entries() {
    printf '%s\n' "$TREE" | grep -v '^[[:space:]]*$' | grep -v '^#'
}

if [ "$MODE" = "manifest" ]; then
    printf 'MILESTONE\tKIND\tPATH\tNOTE\n'
    entries | while IFS='|' read -r milestone kind path rest; do
        [ -n "$FILTER" ] && [ "$milestone" != "$FILTER" ] && continue
        printf '%s\t%s\t%s\t%s\n' "$milestone" "$kind" "$path" "$rest"
    done
    exit 0
fi

if [ "$MODE" = "plan" ]; then
    total_d=0; total_f=0; total_i=0
    entries | while IFS='|' read -r milestone kind path rest; do
        [ -n "$FILTER" ] && [ "$milestone" != "$FILTER" ] && continue
        case "$kind" in
            d) printf '%-3s  %s/\n' "$milestone" "$path" ;;
            f) printf '%-3s      %s%s\n' "$milestone" "$path" \
                   "${rest:+   # $rest}" ;;
            i) printf '%-3s      %s/   # runtime, git-ignored: %s\n' "$milestone" "$path" "$rest" ;;
        esac
    done
    echo
    echo "Directories: $(entries | awk -F'|' '$2=="d"' | wc -l | tr -d ' ')    Planned files: $(entries | awk -F'|' '$2=="f"' | wc -l | tr -d ' ')    Runtime dirs: $(entries | awk -F'|' '$2=="i"' | wc -l | tr -d ' ')"
    echo "NOTE: a directory appears when the code that belongs in it does (architecture.md §3)."
    exit 0
fi

# ── preview ──────────────────────────────────────────────────────────────────
if [ -z "$TARGET" ]; then echo "--preview needs a target directory" >&2; exit 2; fi
mkdir -p "$TARGET"
TARGET_ABS=$(cd "$TARGET" && pwd)
REPO_ABS=$(cd "$(dirname "$0")/.." && pwd)
if [ "$TARGET_ABS" = "$REPO_ABS" ]; then
    echo "Refusing to materialise the whole tree inside the repository." >&2
    echo "architecture.md §3 forbids creating directories in advance." >&2
    exit 3
fi
if [ -e "$TARGET_ABS/composer.json" ]; then
    echo "Refusing: $TARGET_ABS already contains a composer.json." >&2
    exit 3
fi

count_d=0; count_f=0
while IFS='|' read -r milestone kind path rest; do
    [ -z "$path" ] && continue
    [ -n "$FILTER" ] && [ "$milestone" != "$FILTER" ] && continue
    case "$kind" in
        d)
            mkdir -p "$TARGET_ABS/$path"
            printf '# %s\n' "${rest:-planned}" > "$TARGET_ABS/$path/.gitkeep"
            count_d=$((count_d+1))
            ;;
        i)
            mkdir -p "$TARGET_ABS/$path"
            printf '%s\n' 'git-ignored runtime directory — contents are disposable' \
                > "$TARGET_ABS/$path/.gitkeep"
            count_d=$((count_d+1))
            ;;
        f)
            [ "$DIRS_ONLY" = "1" ] && continue
            mkdir -p "$TARGET_ABS/$(dirname "$path")"
            case "$path" in
                *.php)
                    printf '<?php\n\ndeclare(strict_types=1);\n\n// PLANNED (%s): %s\n// Built when its capability lands — see docs/70-engineering/php-application-structure-v1.0.md\n' \
                        "$milestone" "${rest:-no note}" > "$TARGET_ABS/$path"
                    ;;
                *.html|*.xml)
                    printf '<!-- PLANNED (%s): %s -->\n' "$milestone" "${rest:-no note}" \
                        > "$TARGET_ABS/$path"
                    ;;
                *)
                    printf '# PLANNED (%s): %s\n' "$milestone" "${rest:-no note}" \
                        > "$TARGET_ABS/$path"
                    ;;
            esac
            count_f=$((count_f+1))
            ;;
    esac
done < <(entries)

echo "Preview written to $TARGET_ABS"
echo "  directories: $count_d"
echo "  placeholder files: $count_f"
echo "This is a THROWAWAY preview for inspection. Do not commit it."
