<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use FastRoute\Dispatcher;

/**
 * The outcome of matching a request against the route table: a handler, a
 * rejection, or a miss. A value object rather than an array, so the kernel
 * cannot misread it and the analyser can see which case it is holding.
 */
final class RouteMatch
{
    /** @param array<string, string> $parameters */
    private function __construct(
        public readonly int $status,
        public readonly mixed $handler = null,
        public readonly array $parameters = [],
        /** @var list<string> */
        public readonly array $allowedMethods = [],
    ) {
    }

    /** @param array<string, string> $parameters */
    public static function found(mixed $handler, array $parameters = []): self
    {
        return new self(Dispatcher::FOUND, $handler, $parameters);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(Dispatcher::METHOD_NOT_ALLOWED, null, [], $allowed);
    }

    public static function notFound(): self
    {
        return new self(Dispatcher::NOT_FOUND);
    }

    public function isFound(): bool
    {
        return $this->status === Dispatcher::FOUND;
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->status === Dispatcher::METHOD_NOT_ALLOWED;
    }
}
