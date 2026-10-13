<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Search;

use Bulbula\Backend\Services\Search\TextNormaliser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared normaliser (search-design §4): the same rules for a document
 * body and for a query, because asymmetry is "I can see it, but I can't find
 * it" (N-8).
 */
final class TextNormaliserTest extends TestCase
{
    private function normaliser(): TextNormaliser
    {
        return new TextNormaliser(2, ['the', 'a', 'of']);
    }

    #[Test]
    public function case_folds_and_collapses_whitespace(): void
    {
        self::assertSame('tsegaye pharmacy', $this->normaliser()->normalise('  Tsegaye   PHARMACY '));
    }

    #[Test]
    public function punctuation_that_is_not_meaningful_vanishes(): void
    {
        // N-4's own example: "St. Mary's" matches "St Marys".
        self::assertSame('st marys', $this->normaliser()->normalise("St. Mary's"));
        self::assertSame('st marys', $this->normaliser()->normalise('St Marys'));
    }

    #[Test]
    public function digits_are_preserved(): void
    {
        self::assertSame('bole 24', $this->normaliser()->normalise('Bole 24'));
    }

    #[Test]
    public function amharic_text_is_kept_intact_and_lowercasing_changes_nothing(): void
    {
        self::assertSame('ቤተ ሰላም', $this->normaliser()->normalise('ቤተ ሰላም'));
    }

    #[Test]
    public function amharic_word_separators_act_as_spaces(): void
    {
        // ፡ and ። are punctuation: they separate, then the gaps collapse.
        self::assertSame('፩ ሁለት', $this->normaliser()->normalise('፩፡።ሁለት'));
    }

    #[Test]
    public function tokens_drop_short_and_stop_words_but_keep_the_rest_in_order(): void
    {
        self::assertSame(
            ['tsegaye', 'pharmacy', 'bole', '24'],
            $this->normaliser()->tokens('The Tsegaye Pharmacy a of Bole 24'),
        );
    }

    #[Test]
    public function tokens_are_deduplicated(): void
    {
        self::assertSame(['pharmacy', 'bole'], $this->normaliser()->tokens('Pharmacy Bole pharmacy'));
    }

    #[Test]
    public function an_empty_phrase_has_no_tokens(): void
    {
        self::assertSame([], $this->normaliser()->tokens('   '));
        self::assertSame([], $this->normaliser()->tokens('the a of'));
    }
}
