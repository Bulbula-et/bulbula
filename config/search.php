<?php

declare(strict_types=1);

/**
 * Search configuration (TR-196, OR-2).
 *
 * Everything a person might reasonably tune lives here and nowhere else: the
 * suite runs against these values, an operator reviews them, and a change is a
 * deliberate edit rather than a rewrite of matching code.
 *
 * `ranking_keys` is the ordering within a match stage, expressed as a
 * lexicographic key chain — most significant first — over the inputs §9.1 of
 * search-design.md admits, and **nothing else**. There is deliberately **no
 * numeric weighting** (OR-1, D-09 open): inventing weights would settle a
 * policy question by accident. The order below is provisional and reviewable;
 * changing it is a decision to be recorded, not a tuning session (OR-8).
 */

return [
    // Result pages (component-spec §27): a bounded page, real links, no
    // infinite scroll (IAR-30).
    'page_size' => 20,

    // Query bounds (MT-4): an excessive query is rejected cleanly and never
    // run. Terms beyond `max_terms` are dropped from matching, longest first,
    // because a query nobody can read is a query nobody meant.
    'max_query_chars' => 120,
    'max_terms'       => 8,

    // Tokenisation (§5.1): minimum token length and the stopword list are
    // configuration, not hard-coded — defaults suited to prose damage short
    // directory names ("Bole 24"). The list below is the conservative English
    // set; Amharic carries no stopword list in V1 (§5.2), because an invented
    // one would silently drop real terms from a morphologically rich language.
    'min_token_length' => 2,
    'stopwords'        => [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
        'in', 'is', 'it', 'of', 'on', 'or', 'the', 'to', 'with',
    ],

    // The ordering within a match stage (§9.2 OR-1/OR-2). Keys are matched in
    // order; `id` last so pagination is stable (SRCH-6, OR-5).
    'ranking_keys' => [
        'name_match',      // a name match is a stronger signal (§9.1)
        'verified',        // verification state…
        'verified_at',     // …and recency
        'completeness',    // defined under D-09; NULL until then, so neutral
        'rating_count',    // rating average and count together — count first:
        'rating_average',  // one five-star review is not better than forty four-star
        'id',              // deterministic tiebreaker
    ],
];
