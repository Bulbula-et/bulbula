<?php

declare(strict_types=1);

namespace Bulbula\Tests\Arch;

use Bulbula\Tests\Support\SourceFiles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The conventions that make the codebase predictable. Individually small,
 * collectively the difference between a codebase you can navigate and one you
 * have to read.
 */
final class ConventionsTest extends TestCase
{
    /** Calls that belong in a debugger, never in a commit. */
    private const DEBUG_CALLS = ['var_dump', 'print_r', 'var_export', 'debug_zval_dump', 'dd', 'dump', 'ray'];

    /** Calls that block or sleep in a request path. */
    private const BLOCKING_CALLS = ['sleep', 'usleep', 'time_nanosleep'];

    #[Test]
    public function every_file_declares_strict_types(): void
    {
        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            $declaration = strpos(SourceFiles::withoutComments($file), 'declare(strict_types=1)');

            self::assertNotFalse(
                $declaration,
                SourceFiles::relative($file) . ' must declare strict_types.',
            );
        }
    }

    #[Test]
    public function classes_are_final_unless_they_are_meant_to_be_extended(): void
    {
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (!is_array($token) || $token[0] !== T_CLASS) {
                    continue;
                }

                $previous = self::previousMeaningfulToken($tokens, $i);

                // `Foo::class` is a constant and `new class` is an expression:
                // neither is a declaration this rule has an opinion about.
                if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }

                $declared = self::classKeywordIsModified($tokens, $i);

                if (!$declared && !self::isFollowedBy($tokens, $i, T_EXTENDS)) {
                    $offenders[] = SourceFiles::relative($file);
                }
            }
        }

        self::assertSame([], array_values(array_unique($offenders)), 'A class that is not final must say why.');
    }

    #[Test]
    public function no_debug_call_reaches_the_repository(): void
    {
        $offenders = [];

        foreach (SourceFiles::in(...SourceFiles::ROOTS) as $file) {
            $code = SourceFiles::withoutComments($file);

            foreach (self::DEBUG_CALLS as $call) {
                if (preg_match('/\b' . $call . '\s*\(/', $code) === 1) {
                    $offenders[] = SourceFiles::relative($file) . ' calls ' . $call . '()';
                }
            }

            foreach (self::BLOCKING_CALLS as $call) {
                if (preg_match('/\b' . $call . '\s*\(/', $code) === 1) {
                    $offenders[] = SourceFiles::relative($file) . ' calls ' . $call . '()';
                }
            }
        }

        self::assertSame([], $offenders);
    }

    #[Test]
    public function controllers_and_exceptions_are_named_for_what_they_are(): void
    {
        foreach (SourceFiles::in('frontend', 'api', 'admin') as $file) {
            if (!str_contains($file, '/Controllers/')) {
                continue;
            }

            $class = basename($file, '.php');

            self::assertStringEndsWith('Controller', $class, SourceFiles::relative($file));
        }

        foreach (SourceFiles::in('backend/Exceptions') as $file) {
            $class = basename($file, '.php');

            self::assertStringEndsWith('Exception', $class, SourceFiles::relative($file));
        }
    }

    #[Test]
    public function templates_live_with_the_surface_that_renders_them(): void
    {
        foreach (SourceFiles::in('frontend/Views', 'admin/Views') as $file) {
            self::assertMatchesRegularExpression(
                '#^(frontend|admin)/Views/(layouts|pages|partials|components)/#',
                SourceFiles::relative($file),
                'A template belongs in a known kind of view directory.',
            );
        }

        self::assertSame(['public/index.php'], array_map(
            SourceFiles::relative(...),
            glob(SourceFiles::basePath() . '/public/*.php') ?: [],
        ), 'public/ holds exactly one executable entry point (TR-09, TR-184).');
    }

    #[Test]
    public function the_public_directory_holds_no_configuration_or_backup_file(): void
    {
        $forbidden = ['env', 'log', 'sql', 'bak', 'old', 'orig', 'sh', 'lock', 'ini', 'dist'];
        $allowedDotfiles = ['.htaccess', '.gitkeep'];
        $offenders = [];

        foreach (SourceFiles::filesIn('public') as $path) {
            $name = basename($path);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            $isForbiddenDotfile = str_starts_with($name, '.') && !in_array($name, $allowedDotfiles, true);

            if (in_array($extension, $forbidden, true) || $isForbiddenDotfile) {
                $offenders[] = SourceFiles::relative($path);
            }
        }

        self::assertSame([], $offenders, 'A dotfile or a backup inside the document root is a disclosure.');
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * True when the class keyword carries `final` or `abstract` — including
     * `final readonly class`, where a readonly token sits between them.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function classKeywordIsModified(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                return false;
            }

            if ($token[0] === T_FINAL || $token[0] === T_ABSTRACT) {
                return true;
            }

            if (!in_array($token[0], [T_WHITESPACE, T_READONLY], true)) {
                return false;
            }
        }

        return false;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function isFollowedBy(array $tokens, int $index, int $id): bool
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count && $i < $index + 40; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === $id) {
                return true;
            }

            if (is_string($token) && $token === '{') {
                return false;
            }
        }

        return false;
    }
}
