<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * A thin wrapper over FastRoute. It registers, matches and loads route files —
 * nothing else. No business rule belongs in a route definition.
 *
 * A handler is either [Controller::class, 'method'] or a closure taking
 * (Request $request, array $vars).
 */
final class Router
{
    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $routes = [];

    public function get(string $path, mixed $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, mixed $handler): void
    {
        $this->routes[] = [strtoupper($method), $path, $handler];
    }

    /** Each route file returns a callable that receives this router. */
    public function load(string ...$files): void
    {
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $register = require $file;

            if (is_callable($register)) {
                $register($this);
            }
        }
    }

    /**
     * Match a request against the registered routes.
     *
     * FastRoute hands back a positional array; it is converted here, once, into
     * a shape the rest of the application can trust.
     */
    public function match(string $method, string $path): RouteMatch
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $collector): void {
            foreach ($this->routes as [$verb, $routePath, $handler]) {
                $collector->addRoute($verb, $routePath, $handler);
            }
        });

        $result = $dispatcher->dispatch($method, $path);

        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            /** @var list<string> $allowed */
            $allowed = array_values(array_filter((array) $result[1], 'is_string'));

            return RouteMatch::methodNotAllowed($allowed);
        }

        if ($result[0] !== Dispatcher::FOUND) {
            return RouteMatch::notFound();
        }

        $parameters = [];

        foreach ((array) $result[2] as $name => $value) {
            $parameters[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return RouteMatch::found($result[1], $parameters);
    }
}
