<?php

declare(strict_types=1);

namespace Bulbula\Tests\Arch;

use Bulbula\Tests\Support\SourceFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the project is allowed to depend on.
 *
 * The dependency surface is the part of an application that ages without
 * anyone touching it, so it is enumerated here and asserted, rather than left
 * to grow one convenient package at a time.
 */
final class DependenciesTest extends TestCase
{
    #[Test]
    public function the_runtime_dependencies_are_the_four_that_were_agreed(): void
    {
        $manifest = $this->manifest();

        /** @var array<string, string> $require */
        $require = $manifest['require'];

        $packages = array_values(array_filter(
            array_keys($require),
            static fn (string $package): bool => !str_starts_with($package, 'ext-') && $package !== 'php',
        ));

        sort($packages);

        self::assertSame([
            'monolog/monolog',
            'nikic/fast-route',
            'psr/log',
            'vlucas/phpdotenv',
        ], $packages, 'A fifth runtime dependency is a decision, not an install.');
    }

    #[Test]
    public function no_framework_or_orm_is_declared(): void
    {
        $manifest = $this->manifest();

        /** @var array<string, string> $require */
        $require = $manifest['require'];

        foreach (['laravel', 'symfony', 'doctrine', 'laminas', 'mezzio', 'slim', 'cycle', 'propel', 'cakephp'] as $forbidden) {
            foreach (array_keys($require) as $package) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $package,
                    'A framework in the runtime dependencies is the end of the no-framework rule.',
                );
            }
        }

        // Development tooling is enumerated, not merely "not a framework":
        // laravel/pint is a formatter, and a formatter is not an architecture.
        /** @var array<string, string> $requireDev */
        $requireDev = $manifest['require-dev'];

        $devTools = array_keys($requireDev);
        sort($devTools);

        self::assertSame([
            'laravel/pint',
            'phpstan/phpstan',
            'phpunit/phpunit',
        ], $devTools, 'A new development tool is a decision, not an install.');
    }

    #[Test]
    public function the_php_version_is_pinned_to_the_language_the_code_uses(): void
    {
        self::assertSame('^8.4', $this->manifest()['require']['php']);
    }

    #[Test]
    public function composer_maps_one_namespace_root_per_surface(): void
    {
        $manifest = $this->manifest();

        /** @var array<string, string> $psr4 */
        $psr4 = $manifest['autoload']['psr-4'];

        ksort($psr4);

        self::assertSame([
            'Bulbula\\Admin\\'    => 'admin/',
            'Bulbula\\Api\\'      => 'api/',
            'Bulbula\\Backend\\'  => 'backend/',
            'Bulbula\\Frontend\\' => 'frontend/',
        ], $psr4, 'A new namespace root is a structure change, not a convenience.');

        self::assertSame(
            ['backend/Support/helpers.php'],
            $manifest['autoload']['files'],
            'Exactly one file is force-loaded: the template helpers.',
        );
    }

    #[Test]
    public function the_gate_scripts_are_the_ones_the_documentation_names(): void
    {
        $manifest = $this->manifest();

        /** @var array<string, mixed> $scripts */
        $scripts = $manifest['scripts'];

        self::assertSame('phpunit', $scripts['test']);
        self::assertStringContainsString('phpstan analyse', (string) $scripts['analyse']);
        self::assertSame('pint --test', $scripts['lint']);
        self::assertSame(['@lint', '@analyse', '@test'], $scripts['check']);
    }

    #[Test]
    public function application_code_does_not_import_a_dev_dependency(): void
    {
        $devOnly = ['PHPUnit\\', 'PHPStan\\'];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            foreach (SourceFiles::names($file) as $name) {
                foreach ($devOnly as $prefix) {
                    self::assertStringStartsNotWith(
                        $prefix,
                        $name,
                        SourceFiles::relative($file) . ' must not depend on a development tool.',
                    );
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents(SourceFiles::basePath() . '/composer.json');

        self::assertIsString($contents);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
