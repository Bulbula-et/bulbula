<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

/**
 * A controlled synonym (data-model §3.5).
 *
 * Aliases attach to a Category, a Subcategory or an Area — never to an
 * individual Business, which would become a keyword-stuffing channel (AL-1).
 * They are matching inputs and never display labels (AL-3): a page shows the
 * catalogue name it knows; an Alias only exists so the word someone actually
 * types can find it (AL-4 — Aliases change whether something matches, never
 * the order).
 */
final readonly class Alias
{
    public const TARGET_CATEGORY = 'category';
    public const TARGET_AREA = 'area';

    public function __construct(
        public int $id,
        public string $targetType,
        public int $targetId,
        public string $aliasText,
        public string $aliasNormalised,
        public ?string $language,
    ) {
    }
}
