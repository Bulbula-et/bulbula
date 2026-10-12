<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Domain\Directory;

use Bulbula\Backend\Domain\Directory\Slug;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The URL grammar (TR-25, IAR-4): lowercase, hyphenated, bounded, and nothing
 * else. The refusal cases matter as much as the acceptance cases — every slug
 * that is rejected here is a URL that never gets printed on a card and then
 * has to keep working forever.
 */
final class SlugTest extends TestCase
{
    #[Test]
    public function it_accepts_lowercase_hyphenated_words(): void
    {
        self::assertSame('tsegaye-pharmacy', Slug::fromString('tsegaye-pharmacy')->value);
        self::assertSame('24-hour', Slug::fromString('24-hour')->value);
        self::assertSame('a', Slug::fromString('a')->value);
    }

    #[Test]
    public function it_folds_a_display_name_into_the_grammar(): void
    {
        self::assertSame('tsegayes-pharmacy', Slug::fromName('Tsegaye’s Pharmacy')->value);
        self::assertSame('blue-nile-hotel', Slug::fromName('  Blue   Nile  Hotel ')->value);
        self::assertSame('bole-bulbula', Slug::fromName('Bole / Bulbula')->value);
    }

    #[Test]
    public function it_refuses_a_name_that_produces_nothing_rather_than_guessing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Slug::fromName('ቡልቡላ ፋርማሲ');
    }

    #[Test]
    public function it_refuses_uppercase_space_and_bad_hyphens(): void
    {
        foreach (['Tsegaye', 'tsegaye pharmacy', '-tsegaye', 'tsegaye-', 'tsegaye--pharmacy', ''] as $invalid) {
            try {
                Slug::fromString($invalid);
                self::fail('[' . $invalid . '] should not be a slug.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function it_refuses_a_slug_longer_than_the_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Slug::fromString(str_repeat('a', Slug::MAX_LENGTH + 1));
    }

    #[Test]
    public function equality_is_by_value(): void
    {
        self::assertTrue(Slug::fromString('bole')->equals(Slug::fromString('bole')));
        self::assertFalse(Slug::fromString('bole')->equals(Slug::fromString('bulbula')));
    }
}
