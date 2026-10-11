<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Bulbula\Backend\Exceptions\ValidationException;
use Throwable;

/**
 * Turns a failure into a response that is useful in private and safe in public.
 *
 * - JSON callers get the documented error envelope with a correlation id.
 * - Everyone else gets a readable page with a route forward.
 * - Stack traces, SQL and file paths appear only when debug is on, and debug
 *   is forced off in production by config/app.php.
 */
final class ErrorRenderer
{
    public function __construct(private readonly bool $debug)
    {
    }

    public function render(Throwable $error, int $status, bool $wantsJson, string $requestId): Response
    {
        $this->log($error, $status, $requestId);

        return $wantsJson
            ? $this->asJson($error, $status, $requestId)
            : $this->asHtml($error, $status, $requestId);
    }

    private function asJson(Throwable $error, int $status, string $requestId): Response
    {
        $payload = [
            'error' => [
                'code'       => $this->code($status),
                'message'    => $this->publicMessage($error, $status),
                'request_id' => $requestId,
            ],
        ];

        // E-4: field-level detail appears only for a validation failure, and
        // only because the caller can act on it.
        if ($error instanceof ValidationException && $error->errors !== []) {
            $details = [];

            foreach ($error->errors as $field => $message) {
                $details[] = [
                    'field'   => $field,
                    'code'    => 'invalid',
                    'message' => $message,
                ];
            }

            $payload['error']['details'] = $details;
        }

        if ($this->debug) {
            $payload['error']['debug'] = [
                'class'   => $error::class,
                'message' => $error->getMessage(),
                'file'    => $error->getFile() . ':' . $error->getLine(),
            ];
        }

        return Response::json($payload, $status);
    }

    private function asHtml(Throwable $error, int $status, string $requestId): Response
    {
        $heading = $this->heading($status);
        $message = $this->publicMessage($error, $status);

        $detail = '';
        if ($this->debug) {
            $detail = '<pre style="overflow:auto;padding:1rem;background:#fff4ec;border-radius:8px;font-size:.85rem">'
                . View::escape($error::class . ': ' . $error->getMessage())
                . "\n" . View::escape($error->getFile() . ':' . $error->getLine())
                . '</pre>';
        }

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . View::escape($heading) . ' — Bulbula</title>'
            . '<style>body{margin:0;padding:4rem 1.5rem;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;color:#14213d;background:#fff}'
            . 'main{max-width:36rem;margin:0 auto}h1{color:#f4711f;margin:0 0 .5rem;font-size:2rem}'
            . 'a{color:#1b6ec2}code{color:#5b6472}</style></head><body><main>'
            . '<h1>' . View::escape($heading) . '</h1>'
            . '<p>' . View::escape($message) . '</p>'
            . $detail
            . '<p><a href="/">Back to the homepage</a> &middot; <a href="/contact">Contact us</a></p>'
            . '<p><code>Reference ' . View::escape($requestId) . '</code></p>'
            . '</main></body></html>';

        return Response::html($html, $status);
    }

    /** The message a user sees. Never an exception message in production. */
    private function publicMessage(Throwable $error, int $status): string
    {
        if ($this->debug) {
            return $error->getMessage() !== '' ? $error->getMessage() : $this->heading($status);
        }

        return match ($status) {
            401     => 'You need to sign in to do that.',
            403     => 'You do not have permission to do that.',
            404     => 'We could not find that page.',
            405     => 'That action is not allowed here.',
            419     => 'Your session expired. Please try again.',
            422     => 'Some of the information was not accepted.',
            429     => 'Too many requests. Please slow down and try again shortly.',
            503     => 'Bulbula is briefly unavailable. Please try again shortly.',
            default => 'Something went wrong on our side. The reference below identifies it.',
        };
    }

    private function heading(int $status): string
    {
        return match ($status) {
            401     => 'Sign in required',
            403     => 'Not permitted',
            404     => 'Page not found',
            405     => 'Method not allowed',
            419     => 'Session expired',
            422     => 'Check the form',
            429     => 'Too many requests',
            503     => 'Temporarily unavailable',
            default => 'Something went wrong',
        };
    }

    private function code(int $status): string
    {
        return match ($status) {
            400     => 'bad_request',
            401     => 'unauthenticated',
            403     => 'forbidden',
            404     => 'not_found',
            405     => 'method_not_allowed',
            419     => 'csrf_token_mismatch',
            422     => 'validation_failed',
            429     => 'too_many_requests',
            503     => 'service_unavailable',
            default => 'server_error',
        };
    }

    /**
     * Logged through error_log() on purpose: the logger itself may be the thing
     * that failed, and an error path must not depend on the system it reports on.
     */
    private function log(Throwable $error, int $status, string $requestId): void
    {
        error_log(sprintf(
            '[bulbula] %s request=%s %s: %s in %s:%d',
            (string) $status,
            $requestId,
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
        ));
    }
}
