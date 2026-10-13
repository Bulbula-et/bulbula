<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console\Commands;

use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Services\Search\SearchIndexer;

/**
 * `php bin/console search:reindex` — rebuild every search document from
 * source (SD-4).
 *
 * Safe to re-run at any time: the document is derived and the entity tables
 * are the truth (SD-1, SD-6). Losing the index loses nothing, and this
 * command is the whole recovery procedure.
 */
final class SearchReindexCommand implements Command
{
    public function __construct(
        private readonly SearchIndexer $indexer,
        private readonly Output $output,
    ) {
    }

    public function name(): string
    {
        return 'search:reindex';
    }

    public function description(): string
    {
        return 'Rebuild every search document from the entity tables';
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $report = $this->indexer->rebuildAll();

        $this->output->line('rebuilt  ' . $report['rebuilt'] . ' document(s).');
        $this->output->line('removed  ' . $report['removed'] . ' stale document(s).');

        return 0;
    }
}
