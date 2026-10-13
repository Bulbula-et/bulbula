<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console\Commands;

use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Services\Search\SearchIndexer;

/**
 * `php bin/console search:reindex:drift` — compare the index against its
 * source and report, without writing (SD-5).
 *
 * The operational question this answers is "can a visitor see everything they
 * should?" — a missing or stale document is a listing the search page will
 * not show, and the first person to notice should be an operator, not a
 * visitor.
 */
final class SearchReindexDriftCommand implements Command
{
    public function __construct(
        private readonly SearchIndexer $indexer,
        private readonly Output $output,
    ) {
    }

    public function name(): string
    {
        return 'search:reindex:drift';
    }

    public function description(): string
    {
        return 'Report search documents that are missing, stale or orphaned';
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $report = $this->indexer->driftReport();

        $this->output->line('public Listings expected: ' . $report['expected']);
        $this->output->line('documents indexed:        ' . $report['indexed']);
        $this->output->line('missing:                  ' . $this->list($report['missing']));
        $this->output->line('stale:                    ' . $this->list($report['stale']));
        $this->output->line('orphaned:                 ' . $this->list($report['orphaned']));

        if ($report['missing'] === [] && $report['stale'] === [] && $report['orphaned'] === []) {
            $this->output->line('No drift.');

            return 0;
        }

        $this->output->error('Drift found — run `search:reindex` to repair.');

        return 1;
    }

    /**
     * @param list<int> $ids
     */
    private function list(array $ids): string
    {
        return $ids === [] ? 'none' : implode(', ', $ids);
    }
}
