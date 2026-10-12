<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

use InvalidArgumentException;

/**
 * A public URL slug (TR-25, IAR-4).
 *
 * The grammar is deliberately the narrow one the routing table documents:
 * lowercase letters, digits and single hyphens between them, 1–80 characters,
 * no leading or trailing hyphen. A slug is a promise — `/b/tsegaye-pharmacy`
 * is shared in an SMS, printed on a card and indexed by search engines — so
 * widening the grammar deliberately costs nothing later, while starting wide
 * costs a class of URLs that must keep working forever.
 *
 * `fromName()` folds a display name into the grammar: case, spacing,
 * punctuation and apostrophes. A name that yields nothing usable — an
 * Amharic-only name, today — is **refused rather than guessed**: the caller
 * supplies an explicit slug. When Amharic URLs arrive (IAR-6, D-18) they
 * arrive by widening this one class and the one route constraint that uses
 * it, not by having been precluded here.
 */
final readonly class Slug
{
    /** The longest slug the column and the route constraint accept. */
    public const MAX_LENGTH = 80;

    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(public string $value)
    {
    }

    /** @throws InvalidArgumentException when the value is not a slug */
    public static function fromString(string $value): self
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                'A slug is 1 to ' . self::MAX_LENGTH
                . ' lowercase letters, digits and single hyphens, and cannot begin or end with a hyphen.',
            );
        }

        return new self($value);
    }

    /**
     * Fold a display name into the grammar.
     *
     * @throws InvalidArgumentException when the name yields nothing usable — the caller supplies a slug instead
     */
    public static function fromName(string $name): self
    {
        $folded = mb_strtolower(trim($name));
        $folded = str_replace(['’', "'", '`'], '', $folded);
        $folded = preg_replace('/[^a-z0-9]+/', '-', $folded) ?? '';
        $folded = trim($folded, '-');

        if ($folded === '') {
            throw new InvalidArgumentException(
                'That name does not produce a usable slug; supply one explicitly.',
            );
        }

        return self::fromString($folded);
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
