<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

use InvalidArgumentException;

/**
 * An email address, normalised once at the boundary.
 *
 * Normalisation is case folding and surrounding whitespace — nothing more.
 * Provider-specific aliasing (dots in Gmail, plus-addressing) is deliberately
 * **not** treated as equivalence: guessing that two spellings are one mailbox
 * is how the wrong accounts get merged (LK-3).
 *
 * The address is personal data, so this object is also the boundary that
 * refuses an unusable one: nothing downstream has to wonder whether it is
 * looking at a string that might be empty.
 */
final readonly class Email
{
    /** The maximum length a routed address may have, and what the column holds. */
    private const MAX_LENGTH = 191;

    private function __construct(public string $value)
    {
    }

    /** @throws InvalidArgumentException when the address is not one */
    public static function fromString(string $address): self
    {
        $normalized = mb_strtolower(trim($address));

        if ($normalized === '' || mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('An email address must be between 1 and ' . self::MAX_LENGTH . ' characters.');
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('That is not an email address.');
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
