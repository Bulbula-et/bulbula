<?php

declare(strict_types=1);

namespace Bulbula\Tests\Arch;

use Bulbula\Tests\Support\SourceFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The boundaries between the surfaces, the backend layers and the outside
 * world. These are the rules the structure exists to enforce; a violation here
 * is a design regression, not a style question.
 *
 * Every rule states what it is protecting, because a boundary nobody can
 * explain is a boundary somebody deletes.
 */
final class BoundariesTest extends TestCase
{
    private const FRAMEWORKS = [
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\Component\\HttpKernel',
        'Laminas\\',
        'Mezzio\\',
        'Slim\\',
        'Flight\\',
        'Doctrine\\',
        'Propel\\',
        'Cycle\\',
    ];

    /** SQL is the database layer's business, and the repositories'. */
    private const SQL_PATTERNS = [
        '/\bSELECT\b[^;]{0,200}?\bFROM\b/is',
        '/\bINSERT\s+INTO\b/i',
        '/\bUPDATE\b[^;]{0,200}?\bSET\b/is',
        '/\bDELETE\s+FROM\b/i',
        '/\bCREATE\s+TABLE\b/i',
        '/\bALTER\s+TABLE\b/i',
    ];

    /**
     * Two sign-in methods and no others (D-48, AI-1, AI-3, AI-5, TR-32).
     *
     * Adding a provider is a product decision, not a technical one, so the
     * code is not allowed to grow a third quietly. Telegram is absent on
     * purpose: it is a surface, not a provider (AI-4).
     */
    #[Test]
    public function no_authentication_mechanism_is_added_beyond_the_approved_two(): void
    {
        // Quoted literals, not substrings: `-apple-system` in a font stack is
        // not an authentication provider, and a test that says it is would be
        // turned off within a week.
        $forbidden = ['facebook', 'apple', 'magic-link', 'sms', 'whatsapp', 'truecaller'];
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            $code = strtolower(SourceFiles::withoutComments($file));

            foreach ($forbidden as $mechanism) {
                if (str_contains($code, "'" . $mechanism . "'") || str_contains($code, '"' . $mechanism . '"')) {
                    $offenders[] = SourceFiles::relative($file) . ' names the provider ' . $mechanism;
                }
            }
        }

        self::assertSame([], $offenders, 'Only Google and email OTP are approved (D-48).');
    }

    /**
     * The audit trail records what happened; nothing rewrites it (OM-6.2,
     * OPX-12.2). The migrations are excluded on purpose — they own whether the
     * table exists, which is not the same as owning its rows.
     */
    #[Test]
    public function the_audit_trail_is_append_only(): void
    {
        foreach (SourceFiles::in('backend') as $file) {
            $code = SourceFiles::withoutComments($file);

            foreach (['UPDATE audit_entries', 'DELETE FROM audit_entries'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $code,
                    SourceFiles::relative($file) . ' rewrites the audit trail.',
                );
            }
        }
    }

    /**
     * The composition root is explicit: an owner decision (R-3, 2026-10-11)
     * removed the container in favour of hand-written controller factories, so
     * a container coming back — under any name — is a design regression.
     */
    #[Test]
    public function there_is_no_service_locator(): void
    {
        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            self::assertStringNotContainsString(
                'Core\\Container',
                SourceFiles::withoutComments($file),
                SourceFiles::relative($file) . ' resolves a dependency from a container.',
            );
        }

        $offenders = array_filter(
            SourceFiles::filesIn('backend'),
            static fn (string $file): bool => str_contains(basename($file), 'Container'),
        );

        self::assertSame(
            [],
            array_values($offenders),
            'The composition root names what it builds; nothing is conjured from a container.',
        );
    }

    /** The roots the suite is meant to be looking at. */
    #[Test]
    public function the_scan_roots_exist(): void
    {
        // A renamed directory would otherwise turn most of this file into tests
        // that pass because they found nothing to check.
        foreach (SourceFiles::ROOTS as $root) {
            self::assertDirectoryExists(SourceFiles::basePath() . '/' . $root, $root);
        }

        self::assertNotEmpty(SourceFiles::in('backend/Core'));
    }

    #[Test]
    public function no_framework_or_orm_namespace_is_referenced(): void
    {
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            foreach (SourceFiles::names($file) as $name) {
                foreach (self::FRAMEWORKS as $forbidden) {
                    if (str_starts_with($name, $forbidden)) {
                        $offenders[] = SourceFiles::relative($file) . ' → ' . $name;
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Framework and ORM namespaces are forbidden.');
    }

    #[Test]
    public function the_database_driver_is_confined_to_the_database_layer(): void
    {
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            if (SourceFiles::isInside($file, 'backend/Database')) {
                continue;
            }

            foreach (SourceFiles::names($file) as $name) {
                if (in_array($name, ['PDO', 'PDOStatement', 'PDOException', 'mysqli'], true)) {
                    $offenders[] = SourceFiles::relative($file) . ' → ' . $name;
                }
            }
        }

        self::assertSame([], $offenders, 'PDO belongs in backend/Database and nowhere else.');
    }

    #[Test]
    public function outbound_http_is_confined_to_the_integrations_namespace(): void
    {
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            if (SourceFiles::isInside($file, 'backend/Integrations')) {
                continue;
            }

            if (preg_match('/\bcurl_(init|exec|setopt|getinfo|close|error)\b/', SourceFiles::withoutComments($file)) === 1) {
                $offenders[] = SourceFiles::relative($file);
            }
        }

        self::assertSame([], $offenders, 'Outbound transport belongs in backend/Integrations (TD-07).');
    }

    #[Test]
    public function sql_stays_in_the_database_and_repository_layers(): void
    {
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            // Migrations are the third legitimate home for SQL: they own the
            // schema, and a schema is described in DDL. They may not run
            // queries, which is what the rest of this rule protects.
            if (SourceFiles::isInside($file, 'backend/Database', 'backend/Repositories', 'database/migrations')) {
                continue;
            }

            $code = SourceFiles::withoutComments($file);

            foreach (self::SQL_PATTERNS as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $offenders[] = SourceFiles::relative($file) . ' matches ' . $pattern;
                }
            }
        }

        self::assertSame([], $offenders, 'A query outside the data layer is a query nobody owns.');
    }

    #[Test]
    public function a_surface_never_reaches_the_database_directly(): void
    {
        $offenders = [];

        foreach (SourceFiles::in('frontend', 'api', 'admin') as $file) {
            foreach (SourceFiles::names($file) as $name) {
                if (str_starts_with($name, 'Bulbula\\Backend\\Database')) {
                    $offenders[] = SourceFiles::relative($file) . ' → ' . $name;
                }
            }

            if (preg_match('/->pdo\(\)/', SourceFiles::withoutComments($file)) === 1) {
                $offenders[] = SourceFiles::relative($file) . ' calls ->pdo()';
            }
        }

        self::assertSame([], $offenders, 'A surface calls an application service, never a connection.');
    }

    #[Test]
    public function the_environment_is_read_only_inside_the_core(): void
    {
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            if (SourceFiles::isInside($file, 'backend/Core')) {
                continue;
            }

            if (preg_match('/\$_(ENV|SERVER|GET|POST|COOKIE|FILES)|\bgetenv\s*\(/', SourceFiles::withoutComments($file)) === 1) {
                $offenders[] = SourceFiles::relative($file);
            }
        }

        self::assertSame([], $offenders, 'Every setting arrives through Config (CF-2).');
    }

    #[Test]
    public function nothing_outside_the_two_entry_points_exits(): void
    {
        $allowed = ['bin/console', 'public/index.php'];
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            if (in_array(SourceFiles::relative($file), $allowed, true)) {
                continue;
            }

            if (preg_match('/\b(exit|die)\s*\(/', SourceFiles::withoutComments($file)) === 1) {
                $offenders[] = SourceFiles::relative($file);
            }
        }

        self::assertSame([], $offenders, 'Only the entry points may end the process (CR-6).');
    }

    #[Test]
    public function domain_and_service_layers_do_not_know_about_presentation(): void
    {
        $offenders = [];

        foreach (SourceFiles::in('backend/Domain', 'backend/Services') as $file) {
            foreach (SourceFiles::names($file) as $name) {
                if (self::isPresentation($name)) {
                    $offenders[] = SourceFiles::relative($file) . ' → ' . $name;
                }
            }
        }

        self::assertSame([], $offenders, 'A domain rule callable only from a web page is not a rule (TR-04).');
    }

    #[Test]
    public function the_view_layer_does_not_touch_the_database_or_the_network(): void
    {
        foreach (['frontend/Views', 'admin/Views'] as $root) {
            foreach (SourceFiles::in($root) as $file) {
                foreach (SourceFiles::names($file) as $name) {
                    if (
                        str_starts_with($name, 'Bulbula\\Backend\\Database')
                        || str_starts_with($name, 'Bulbula\\Backend\\Integrations')
                    ) {
                        self::fail(SourceFiles::relative($file) . ' → ' . $name);
                    }
                }
            }
        }

        self::assertDirectoryDoesNotExist(
            SourceFiles::basePath() . '/resources',
            'Templates live with their surface, not in a shared resources/ tree.',
        );
    }

    private static function isPresentation(string $name): bool
    {
        foreach (['Bulbula\\Frontend', 'Bulbula\\Api', 'Bulbula\\Admin'] as $surface) {
            if (str_starts_with($name, $surface)) {
                return true;
            }
        }

        foreach (['Request', 'Response', 'View'] as $httpClass) {
            if (str_starts_with($name, 'Bulbula\\Backend\\Core\\' . $httpClass)) {
                return true;
            }
        }

        return false;
    }
}
