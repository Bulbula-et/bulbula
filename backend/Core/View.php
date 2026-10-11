<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Bulbula\Backend\Exceptions\ViewException;
use Throwable;

/**
 * Server-rendered templates, outside the document root.
 *
 * Views receive prepared data and nothing else: they never touch the database,
 * the request or the clock. `require` + output buffering is the whole engine —
 * no template language, no compilation step, nothing to escape.
 */
final class View
{
    /** @var list<string> */
    private array $roots;

    /**
     * Each surface keeps its own view root (frontend/Views, admin/Views).
     * Roots are searched in the order given, so a template name is unique
     * across the application or it does not belong in a shared root.
     */
    public function __construct(string ...$roots)
    {
        $this->roots = array_values(array_filter($roots, static fn (string $root): bool => $root !== ''));
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->capture($template, $data), $status);
    }

    /** @param array<string, mixed> $data */
    public function capture(string $template, array $data = []): string
    {
        $file = $this->resolve($template);

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;
        } catch (Throwable $error) {
            ob_end_clean();

            throw $error;
        }

        return (string) ob_get_clean();
    }

    /**
     * Escape for HTML text and attribute contexts.
     * ENT_QUOTES covers both quote styles; ENT_SUBSTITUTE keeps invalid UTF-8
     * from producing an empty string.
     */
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function resolve(string $template): string
    {
        $relative = ltrim($template, '/') . '.php';

        foreach ($this->roots as $root) {
            $file = $root . '/' . $relative;

            if (is_file($file)) {
                return $file;
            }
        }

        throw new ViewException('View [' . $template . '] was not found in: ' . implode(', ', $this->roots));
    }
}
