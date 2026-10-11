<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads the application's own source, so an architecture test can assert
 * something about the code rather than about a runtime object.
 *
 * Comments and string literals are removed before a rule is applied to tokens:
 * a docblock that says "no SQL in this layer" must not be the thing that
 * triggers the rule, and a message that mentions a forbidden call must not
 * count as making it.
 */
final class SourceFiles
{
    /** The directories that hold application code. */
    public const ROOTS = ['backend', 'frontend', 'api', 'admin', 'routes', 'bin', 'database'];

    /** @return list<string> absolute paths, sorted */
    public static function in(string ...$roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            $path = self::basePath() . '/' . $root;

            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($entries as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** Every file under a directory, whatever its extension. @return list<string> */
    public static function filesIn(string $root): array
    {
        $path = self::basePath() . '/' . trim($root, '/');

        if (!is_dir($path)) {
            return [];
        }

        $files = [];

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /** The repository-relative path of a file, for a readable failure message. */
    public static function relative(string $file): string
    {
        return ltrim(str_replace(self::basePath(), '', $file), '/');
    }

    /** True when the file sits inside one of the given repository-relative directories. */
    public static function isInside(string $file, string ...$directories): bool
    {
        $relative = self::relative($file);

        foreach ($directories as $directory) {
            if (str_starts_with($relative, rtrim($directory, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /** The file's contents with comments removed. */
    public static function withoutComments(string $file): string
    {
        $out = '';

        foreach (token_get_all(self::contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    /**
     * Every class, interface and namespace name the file mentions — used
     * names, fully qualified names and bare class references alike.
     *
     * @return list<string>
     */
    public static function names(string $file): array
    {
        $names = [];

        foreach (token_get_all(self::contents($file)) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (
                in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                || ($token[0] === T_NAMESPACE || $token[0] === T_USE)
            ) {
                if ($token[0] === T_NAMESPACE || $token[0] === T_USE) {
                    continue;
                }

                $names[] = ltrim($token[1], '\\');
            }
        }

        return array_values(array_unique($names));
    }

    private static function contents(string $file): string
    {
        $contents = file_get_contents($file);

        return $contents === false ? '' : $contents;
    }
}
