<?php

declare(strict_types=1);

namespace Bulbula\Tests\Arch;

use Bulbula\Tests\Support\SourceFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Route files against reality.
 *
 * A route file is a promise — this path, this handler — and the promise is only
 * kept when the class it names exists. When it does not, the visitor gets the
 * error page and the failure reads like an outage rather than like a missing
 * feature. `routes/admin.php` did exactly that: it registered `/admin` for a
 * controller nobody had written, and the URL answered `500` on a live host
 * before anyone noticed.
 *
 * The rule enforced here is the one `architecture.md` §6 already states: a route
 * is registered **with the slice that implements it**, and the planned paths
 * wait in a comment until then.
 *
 * This is a source test on purpose. It needs no database, no boot and no
 * environment, so it fails on the commit that introduces the dangling route
 * rather than on the host that serves it.
 */
final class RoutesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function routeFiles(): iterable
    {
        foreach (SourceFiles::in('routes') as $file) {
            yield SourceFiles::relative($file) => [$file];
        }
    }

    #[Test]
    #[DataProvider('routeFiles')]
    public function every_class_a_route_names_exists(string $file): void
    {
        $missing = [];

        foreach (self::namedClasses($file) as $class) {
            if (!class_exists($class)) {
                $missing[] = $class;
            }
        }

        self::assertSame(
            [],
            $missing,
            SourceFiles::relative($file) . ' names a handler that does not exist. Register the '
            . 'route with its slice, and leave the planned path in the comment block (architecture.md §6).',
        );
    }

    /**
     * Every `Bulbula\...` class named in a route file's *code* (comments are
     * stripped first, so a planned path in the comment block is not mistaken
     * for a promise).
     *
     * @return list<string>
     */
    private static function namedClasses(string $file): array
    {
        $classes = [];

        foreach (token_get_all(SourceFiles::withoutComments($file)) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = trim($token[1], "'\"");

            if (preg_match('/^Bulbula(\\\\[A-Z][A-Za-z0-9_]*)+$/', $value) === 1) {
                $classes[] = $value;
            }
        }

        return array_values(array_unique($classes));
    }
}
