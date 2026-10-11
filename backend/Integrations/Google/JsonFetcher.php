<?php

declare(strict_types=1);

namespace Bulbula\Backend\Integrations\Google;

/**
 * Fetching a document from a URL, as one method.
 *
 * Small on purpose: the Google key set is the only caller today, and the seam
 * is what lets every test run against a key set on disk instead of the
 * internet (TR-07's adapter rule, one level down).
 */
interface JsonFetcher
{
    /** @throws \RuntimeException when the document cannot be read */
    public function get(string $url): string;
}
