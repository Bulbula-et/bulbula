<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

/**
 * One page of search results — or the designed zero-result destination.
 *
 * `stage` records which matching stage answered (`none`, `browse`, `exact`,
 * `full`, `prefix`, `relaxed`), so behaviour is observable (§7.1) and a
 * relaxed set can be **labelled as broader**, never passed off as an exact
 * match (UFL-A7.2). `rejected` is the clean "query too long" answer (MT-4):
 * the query is never run, never truncated silently.
 */
final readonly class SearchResults
{
    /**
     * @param list<SearchResultItem> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public string $stage,
        public string $query,
        public bool $rejected = false,
        public ?ZeroResultPlan $zero = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isBroader(): bool
    {
        return $this->stage === 'relaxed';
    }

    public function hasPages(): bool
    {
        return $this->total > $this->pageSize;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->pageSize)));
    }
}
