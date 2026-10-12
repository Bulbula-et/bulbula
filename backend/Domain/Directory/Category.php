<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

/**
 * A Category or Subcategory (data-model §3.4). `parentId === null` is a
 * Category; anything else is its Subcategory. Exactly two levels exist (D-06),
 * so there is no depth field and nothing to recurse over.
 */
final readonly class Category
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public Slug $slug,
        public string $name,
        public ?string $nameAm,
        public int $displayOrder,
        public bool $visible,
    ) {
    }

    public function isTopLevel(): bool
    {
        return $this->parentId === null;
    }
}
