<?php

declare(strict_types=1);

namespace Bulbula\Backend\Integrations\Google;

use RuntimeException;

/**
 * Reads a URL with the streams PHP already has.
 *
 * No curl dependency: the application ships no HTTP client, and a key set is a
 * small, cacheable document. `file://` works exactly like `https://`, which is
 * how the test suite points the verifier at keys it generated itself.
 */
final class StreamJsonFetcher implements JsonFetcher
{
    public function __construct(private readonly int $timeout = 5)
    {
    }

    public function get(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'user_agent' => 'Bulbula/1.0 (+https://bulbula.et)',
                'ignore_errors' => true,
            ],
        ]);

        $document = @file_get_contents($url, false, $context);

        if ($document === false || $document === '') {
            throw new RuntimeException('Could not read ' . $url);
        }

        return $document;
    }
}
