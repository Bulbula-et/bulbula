<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Bulbula\Backend\Exceptions\HttpException;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Exceptions\MethodNotAllowedException;
use Bulbula\Backend\Exceptions\ServerErrorException;
use Closure;
use Throwable;

/**
 * Request in, response out. The kernel owns five things and nothing else:
 * correlation ids, routing, error rendering, calling the handler, and the
 * caching floor every response passes through on its way out.
 */
final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly ControllerFactory $controllers,
        private readonly ErrorRenderer $errors,
    ) {
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            $response = $this->route($request);
        } catch (HttpException $error) {
            $response = $this->errors->render($error, $error->status, $request->wantsJson(), $requestId);

            foreach ($error->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        } catch (Throwable $error) {
            $response = $this->errors->render($error, 500, $request->wantsJson(), $requestId);
        }

        // Every response leaves with a caching policy of its own, so that the
        // host's default is never the one that decides (CachePolicy).
        return CachePolicy::apply($response)->withHeader('X-Request-Id', $requestId);
    }

    private function route(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);

        if ($match->isMethodNotAllowed()) {
            throw new MethodNotAllowedException($match->allowedMethods);
        }

        if (!$match->isFound()) {
            throw new HttpNotFoundException('No route matches [' . $request->method . ' ' . $request->path . '].');
        }

        return $this->invoke($match->handler, $request, $match->parameters);
    }

    /** @param array<string, string> $vars */
    private function invoke(mixed $handler, Request $request, array $vars): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request, $vars);
        } elseif (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            $method = $handler[1];

            $controller = $this->controllers->make($handler[0]);

            if (!method_exists($controller, $method)) {
                throw new ServerErrorException('Handler [' . $handler[0] . '::' . $method . '] does not exist.');
            }

            /** @var list<mixed> $extra */
            $extra = array_values(array_slice($handler, 2));

            $result = $controller->{$method}($request, $vars, ...$extra);
        } else {
            throw new ServerErrorException('The route handler is not callable.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        throw new ServerErrorException('A route handler must return a Response.');
    }
}
