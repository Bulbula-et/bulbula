<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Domain\Identity;

use Bulbula\Backend\Domain\Identity\Email;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    #[Test]
    public function it_folds_case_and_trim_whitespace(): void
    {
        self::assertSame('person@example.com', Email::fromString('  Person@Example.COM ')->value);
    }

    #[Test]
    public function equality_is_by_normalised_value(): void
    {
        self::assertTrue(Email::fromString('Person@Example.com')->equals(Email::fromString('person@example.com')));
        self::assertFalse(Email::fromString('a@example.com')->equals(Email::fromString('b@example.com')));
    }

    #[Test]
    public function dots_and_plus_tags_are_not_treated_as_the_same_mailbox(): void
    {
        // LK-3: guessing that two spellings are one mailbox is how the wrong
        // accounts get merged. Normalisation stops at case and whitespace.
        self::assertFalse(Email::fromString('a.b@example.com')->equals(Email::fromString('ab@example.com')));
        self::assertFalse(Email::fromString('a+tag@example.com')->equals(Email::fromString('a@example.com')));
    }

    #[Test]
    #[DataProvider('unusableAddresses')]
    public function it_refuses_an_address_it_could_not_use(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        Email::fromString($address);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
        yield 'no at sign' => ['person.example.com'];
        yield 'nothing before the at' => ['@example.com'];
        yield 'nothing after the at' => ['person@'];
        yield 'no dot in the domain' => ['person@example'];
        yield 'newline smuggled in' => ["person@example.com\nbcc:someone@example.com"];
        yield 'too long' => [str_repeat('a', 190) . '@example.com'];
    }
}
