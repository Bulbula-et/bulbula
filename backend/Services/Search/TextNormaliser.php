<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use Normalizer;

/**
 * The one text normaliser (search-design §4).
 *
 * Applied identically to indexed content and to queries — asymmetry here is
 * the classic cause of "I can see it, but I can't find it" (N-8: divergence is
 * a defect, which is why there is exactly one class and both paths call it).
 *
 * The steps are the specification's, in order:
 *
 *   N-1  Unicode normalisation to a canonical form (essential for Amharic,
 *        where visually identical text can differ in encoding)
 *   N-2  case folding (Latin script; Amharic has no case)
 *   N-3  trim and collapse whitespace
 *   N-4  strip punctuation that is not meaningful — "St. Mary's" matches
 *        "St Marys"; Amharic word separators (፡ ።) act as separators
 *   N-5  digits and alphanumeric tokens are preserved ("Bole 24")
 *   N-6  no stemming in V1
 *   N-7  no transliteration guessing between Latin and Ge'ez
 *
 * What is deliberately absent is as important as what is present: no
 * stemming, no phonetics, no synonym expansion beyond curated Aliases
 * (§5.3, §6). Where an algorithm would guess, staff-recorded Aliases state
 * the answer.
 */
final class TextNormaliser
{
    /**
     * @param list<string> $stopwords lowercase terms the matcher ignores
     */
    public function __construct(
        private readonly int $minTokenLength = 2,
        private readonly array $stopwords = [],
    ) {
    }

    /**
     * Normalise a phrase to its comparable form (N-1…N-5): the canonical
     * shape used for exact matching and stored in `name_norm`.
     */
    public function normalise(string $text): string
    {
        $text = $this->canonical($text);
        $text = mb_strtolower($text, 'UTF-8');
        // N-4: the marks that are not meaningful are *stripped* — deleted, not
        // spaced: "St. Mary's" matches "St Marys" (the specification's own
        // example), "AT&T" matches "ATT".
        $text = (string) preg_replace('/[.\'’&-]+/u', '', $text);
        // Everything else separable becomes a space — commas, Amharic word
        // separators (፡ ።), and the rest of the punctuation classes.
        $text = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);
        // N-3: trim and collapse.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * The significant tokens of a phrase (N-3…N-6): normalised, split,
     * bounded below by `min_token_length`, cleared of stopwords, and
     * de-duplicated in first-seen order. This is the matching vocabulary —
     * for a document body and for a query alike.
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        $normalised = $this->normalise($text);

        if ($normalised === '') {
            return [];
        }

        $tokens = [];
        $seen = [];

        foreach (explode(' ', $normalised) as $token) {
            if (mb_strlen($token, 'UTF-8') < $this->minTokenLength) {
                continue;
            }

            if (in_array($token, $this->stopwords, true)) {
                continue;
            }

            // A list, not a key map: a numeric token ("24") would become an
            // integer array key and lose its type on the way out.
            if (!isset($seen[$token])) {
                $seen[$token] = true;
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * N-1: one canonical form on both sides. `Normalizer` (intl) is present
     * on the deployment (PHP 8.4); the guard exists so a build without intl
     * degrades to byte comparison rather than fatalling — a difference the
     * MariaDB smoke run outside the suite is there to catch (TR-173).
     */
    private function canonical(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $normalised = Normalizer::normalize($text, Normalizer::FORM_KC);

            return is_string($normalised) ? $normalised : $text;
        }

        return $text;
    }
}
