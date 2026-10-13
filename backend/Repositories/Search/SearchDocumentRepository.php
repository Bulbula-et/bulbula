<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Search;

use Bulbula\Backend\Database\Bind;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Services\Search\MatchRow;
use Bulbula\Backend\Services\Search\SearchDocumentData;
use Bulbula\Backend\Services\Search\SearchFilters;
use Bulbula\Backend\Services\Search\SearchPort;
use UnexpectedValueException;

/**
 * Every SQL statement that touches the search-document tables (TR-16) — the
 * one MariaDB-family adapter behind the search port (TR-48).
 *
 * Matching is indexed equality and prefix-range access over the token table:
 * never a leading wildcard (MT-3), and never a server-side full-text engine
 * whose minimum token length and stopword list are global server settings a
 * shared host will not change (TR-196, IMR-12). The same statements run on
 * SQLite and MariaDB; the suite exercises them on the former and the MariaDB
 * smoke run on the latter (TR-173).
 */
final class SearchDocumentRepository implements SearchPort
{
    private const DOCUMENTS = 'search_documents';
    private const TOKENS = 'search_document_tokens';
    private const CATEGORIES = 'search_document_categories';
    private const AREAS = 'search_document_areas';

    /** The states that may appear in the index (SD-7, MT-2). */
    private const VISIBLE = ['published', 'closed'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function exactName(string $normalisedQuery, array $categoryIds, array $areaIds, SearchFilters $filters): array
    {
        $parameters = ['exact' => $normalisedQuery];
        $clauses = ['sd.name_norm = :exact'];

        if ($categoryIds !== []) {
            $names = [];

            foreach (array_values(array_unique($categoryIds)) as $index => $id) {
                $names[] = ':label_category_' . $index;
                $parameters['label_category_' . $index] = $id;
            }

            $clauses[] = 'sd.business_id IN (SELECT lc.business_id FROM ' . self::CATEGORIES
                . ' lc WHERE lc.category_id IN (' . implode(', ', $names) . '))';
        }

        if ($areaIds !== []) {
            $names = [];

            foreach (array_values(array_unique($areaIds)) as $index => $id) {
                $names[] = ':label_area_' . $index;
                $parameters['label_area_' . $index] = $id;
            }

            $clauses[] = 'sd.business_id IN (SELECT la.business_id FROM ' . self::AREAS
                . ' la WHERE la.area_id IN (' . implode(', ', $names) . '))';
        }

        $sql = 'SELECT ' . $this->columns() . ' FROM ' . self::DOCUMENTS . ' sd WHERE ('
            . implode(' OR ', $clauses) . ') AND ' . $this->filters($filters, $parameters);

        return $this->matchRows($sql, $parameters);
    }

    public function allTerms(array $terms, SearchFilters $filters): array
    {
        if ($terms === []) {
            return [];
        }

        $parameters = [];
        $names = $this->tokenNames($terms, 'term', $parameters);

        $sql = 'SELECT ' . $this->columns() . ' FROM ' . self::DOCUMENTS . ' sd WHERE sd.business_id IN ('
            . 'SELECT t.business_id FROM ' . self::TOKENS . ' t WHERE t.token IN (' . implode(', ', $names) . ')'
            . ' GROUP BY t.business_id HAVING COUNT(DISTINCT t.token) = :term_count'
            . ') AND ' . $this->filters($filters, $parameters);
        $parameters['term_count'] = count($names);

        return $this->matchRows($sql, $parameters);
    }

    public function prefix(array $requiredTerms, string $prefixTerm, SearchFilters $filters): array
    {
        $parameters = ['prefix' => $prefixTerm . '%'];
        $clauses = [];

        if ($requiredTerms !== []) {
            $names = $this->tokenNames($requiredTerms, 'required', $parameters);
            $clauses[] = 'sd.business_id IN (SELECT r.business_id FROM ' . self::TOKENS
                . ' r WHERE r.token IN (' . implode(', ', $names) . ')'
                . ' GROUP BY r.business_id HAVING COUNT(DISTINCT r.token) = :required_count)';
            $parameters['required_count'] = count($names);
        }

        // Tokens are punctuation-free by construction (N-4 strips every
        // Unicode punctuation mark), so the LIKE pattern the normaliser
        // produced cannot carry a wildcard of its own.
        $clauses[] = 'sd.business_id IN (SELECT p.business_id FROM ' . self::TOKENS
            . ' p WHERE p.token LIKE :prefix)';

        $sql = 'SELECT ' . $this->columns() . ' FROM ' . self::DOCUMENTS . ' sd WHERE '
            . implode(' AND ', $clauses) . ' AND ' . $this->filters($filters, $parameters);

        return $this->matchRows($sql, $parameters);
    }

    public function anyTerm(array $terms, SearchFilters $filters): array
    {
        if ($terms === []) {
            return [];
        }

        $parameters = [];
        $names = $this->tokenNames($terms, 'any', $parameters);

        $sql = 'SELECT ' . $this->columns() . ' FROM ' . self::DOCUMENTS . ' sd WHERE sd.business_id IN ('
            . 'SELECT a.business_id FROM ' . self::TOKENS . ' a WHERE a.token IN (' . implode(', ', $names) . ')'
            . ') AND ' . $this->filters($filters, $parameters);

        return $this->matchRows($sql, $parameters);
    }

    public function browse(SearchFilters $filters): array
    {
        $parameters = [];
        $sql = 'SELECT ' . $this->columns() . ' FROM ' . self::DOCUMENTS . ' sd WHERE '
            . $this->filters($filters, $parameters);

        return $this->matchRows($sql, $parameters);
    }

    public function nameCoverage(array $businessIds, array $terms): array
    {
        $coverage = array_fill_keys($businessIds, false);

        if ($businessIds === []) {
            return [];
        }

        if ($terms === []) {
            return array_fill_keys($businessIds, true);
        }

        $parameters = [];
        $idNames = $this->tokenNames($businessIds, 'cover_id', $parameters);
        $termNames = $this->tokenNames($terms, 'cover_term', $parameters);
        $parameters['cover_count'] = count($termNames);

        $statement = $this->connection->pdo()->prepare(
            'SELECT business_id FROM ' . self::TOKENS
            . ' WHERE business_id IN (' . implode(', ', $idNames) . ')'
            . ' AND in_name = 1 AND token IN (' . implode(', ', $termNames) . ')'
            . ' GROUP BY business_id HAVING COUNT(DISTINCT token) = :cover_count',
        );
        Bind::typed($statement, $parameters);

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $coverage[Row::int($row, 'business_id')] = true;
            }
        }

        return $coverage;
    }

    public function nameCoveragePrefix(array $businessIds, array $requiredTerms, string $prefixTerm): array
    {
        $coverage = array_fill_keys($businessIds, false);

        if ($businessIds === []) {
            return [];
        }

        $parameters = ['name_prefix' => $prefixTerm . '%'];
        $idNames = $this->tokenNames($businessIds, 'pre_id', $parameters);

        $having = 'COUNT(CASE WHEN token LIKE :name_prefix_dup THEN 1 END) >= 1';
        $parameters['name_prefix_dup'] = $prefixTerm . '%';

        if ($requiredTerms !== []) {
            $termNames = $this->tokenNames($requiredTerms, 'pre_term', $parameters);
            $orNames = $this->tokenNames($requiredTerms, 'pre_or_term', $parameters);
            $requiredSql = 'COUNT(DISTINCT CASE WHEN token IN (' . implode(', ', $termNames) . ') THEN token END)';
            $having = $requiredSql . ' = :pre_required_count AND ' . $having;
            $parameters['pre_required_count'] = count($termNames);
            $orSql = ' OR token IN (' . implode(', ', $orNames) . ')';
        } else {
            $orSql = '';
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT business_id FROM ' . self::TOKENS
            . ' WHERE business_id IN (' . implode(', ', $idNames) . ') AND in_name = 1'
            . ' AND (token LIKE :name_prefix' . $orSql . ')'
            . ' GROUP BY business_id'
            . ' HAVING ' . $having,
        );

        // The prefix appears twice in the statement; the placeholder must not
        // (PDO binds a named parameter once per statement) — same value, two
        // names.
        Bind::typed($statement, $parameters);

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $coverage[Row::int($row, 'business_id')] = true;
            }
        }

        return $coverage;
    }

    public function distinctBusinessesForCategories(array $categoryIds): int
    {
        if ($categoryIds === []) {
            return 0;
        }

        $parameters = [];
        $names = $this->tokenNames($categoryIds, 'count_category', $parameters);

        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(DISTINCT business_id) AS n FROM ' . self::CATEGORIES
            . ' WHERE category_id IN (' . implode(', ', $names) . ')',
        );
        Bind::typed($statement, $parameters);
        $row = $statement->fetch();

        return is_array($row) ? Row::int($row, 'n') : 0;
    }

    public function distinctBusinessesForArea(int $areaId): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(DISTINCT business_id) AS n FROM ' . self::AREAS . ' WHERE area_id = :facet_area',
        );
        Bind::typed($statement, ['facet_area' => $areaId]);
        $row = $statement->fetch();

        return is_array($row) ? Row::int($row, 'n') : 0;
    }

    public function areaFacetsForCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $parameters = [];
        $names = $this->tokenNames($categoryIds, 'facet_category', $parameters);

        $statement = $this->connection->pdo()->prepare(
            'SELECT sda.area_id AS facet_id, COUNT(DISTINCT sda.business_id) AS n FROM ' . self::AREAS . ' sda'
            . ' WHERE sda.business_id IN (SELECT sdc.business_id FROM ' . self::CATEGORIES
            . ' sdc WHERE sdc.category_id IN (' . implode(', ', $names) . '))'
            . ' GROUP BY sda.area_id',
        );
        Bind::typed($statement, $parameters);

        $facets = [];

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $facets[Row::int($row, 'facet_id')] = Row::int($row, 'n');
            }
        }

        return $facets;
    }

    public function categoryFacetsForArea(int $areaId): array
    {
        // Rolled up to the top level in SQL: a 24-hour pharmacy *is* a
        // pharmacy (D-06), and COUNT(DISTINCT) keeps a Listing classified
        // under both levels from being counted twice.
        $statement = $this->connection->pdo()->prepare(
            'SELECT COALESCE(c.parent_id, c.id) AS facet_id, COUNT(DISTINCT sdc.business_id) AS n'
            . ' FROM ' . self::CATEGORIES . ' sdc'
            . ' INNER JOIN categories c ON c.id = sdc.category_id'
            . ' WHERE sdc.business_id IN (SELECT sda.business_id FROM ' . self::AREAS
            . ' sda WHERE sda.area_id = :rollup_area)'
            . ' GROUP BY facet_id',
        );
        Bind::typed($statement, ['rollup_area' => $areaId]);

        $facets = [];

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $facets[Row::int($row, 'facet_id')] = Row::int($row, 'n');
            }
        }

        return $facets;
    }

    public function replaceDocument(int $businessId, SearchDocumentData $document): void
    {
        $pdo = $this->connection->pdo();

        // Nesting-safe: the write services call this inside *their*
        // transaction (SD-2), and a rebuild runs it on its own (SD-4). PDO
        // refuses to open a transaction twice, so the atomicity is owned by
        // whoever started the outermost one.
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->deleteRows($businessId);

            $statement = $pdo->prepare(
                'INSERT INTO ' . self::DOCUMENTS
                . ' (business_id, status, name_norm, name_text, classification_text, location_text, description_text,'
                . ' primary_category_id, area_id, sub_city_id, verified, verified_at, rating_avg, rating_count,'
                . ' completeness, has_confirmed_hours, latitude, longitude, created_at, updated_at)'
                . ' VALUES (:business_id, :status, :name_norm, :name_text, :classification_text, :location_text,'
                . ' :description_text, :primary_category_id, :area_id, :sub_city_id, :verified, :verified_at,'
                . ' :rating_avg, :rating_count, :completeness, :has_confirmed_hours, :latitude, :longitude,'
                . ' :created_at, :updated_at)',
            );
            $statement->execute([
                'business_id'          => $businessId,
                'status'               => $document->status,
                'name_norm'            => mb_substr($document->nameNorm, 0, 191),
                'name_text'            => $document->nameText,
                'classification_text'  => $document->classificationText,
                'location_text'        => $document->locationText,
                'description_text'     => $document->descriptionText,
                'primary_category_id'  => $document->primaryCategoryId,
                'area_id'              => $document->areaId,
                'sub_city_id'          => $document->subCityId,
                'verified'             => $document->verified ? 1 : 0,
                'verified_at'          => $document->verifiedAt,
                'rating_avg'           => $document->ratingAverage,
                'rating_count'         => $document->ratingCount,
                'completeness'         => $document->completeness,
                'has_confirmed_hours'  => $document->hasConfirmedHours ? 1 : 0,
                'latitude'             => $document->latitude,
                'longitude'            => $document->longitude,
                'created_at'           => $document->createdAt,
                'updated_at'           => $document->updatedAt,
            ]);

            $this->insertTokens($businessId, $document->tokens);
            $this->insertCategories($businessId, $document->categories);
            $this->insertAreas($businessId, $document->areaIds);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }

    public function deleteDocument(int $businessId): void
    {
        $this->deleteRows($businessId);
    }

    public function documentSummaries(): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT business_id, status, updated_at FROM ' . self::DOCUMENTS . ' ORDER BY business_id ASC',
        );
        $statement->execute();

        $summaries = [];

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $summaries[] = [
                    'businessId' => Row::int($row, 'business_id'),
                    'status'     => Row::string($row, 'status'),
                    'updatedAt'  => Row::string($row, 'updated_at'),
                ];
            }
        }

        return $summaries;
    }

    private function columns(): string
    {
        return 'sd.business_id, sd.status, sd.verified, sd.verified_at, sd.rating_count, sd.rating_avg, sd.completeness';
    }

    /**
     * The filter set of §8.1 as indexed predicates, appended to every stage's
     * statement (MT-1). Each named parameter is written once per statement.
     *
     * @param array<string, mixed> $parameters
     */
    private function filters(SearchFilters $filters, array &$parameters): string
    {
        $clauses = ["sd.status IN ('" . implode("', '", self::VISIBLE) . "')"];

        if ($filters->categoryIds !== []) {
            $names = [];

            foreach (array_values(array_unique($filters->categoryIds)) as $index => $id) {
                $names[] = ':filter_category_' . $index;
                $parameters['filter_category_' . $index] = $id;
            }

            $clauses[] = 'sd.business_id IN (SELECT fc.business_id FROM ' . self::CATEGORIES
                . ' fc WHERE fc.category_id IN (' . implode(', ', $names) . '))';
        }

        if ($filters->areaId !== null) {
            $clauses[] = 'sd.business_id IN (SELECT fa.business_id FROM ' . self::AREAS . ' fa WHERE fa.area_id = :filter_area)';
            $parameters['filter_area'] = $filters->areaId;
        }

        if ($filters->subCityId !== null) {
            $clauses[] = 'sd.business_id IN (SELECT fs.business_id FROM ' . self::AREAS
                . ' fs INNER JOIN areas subarea ON subarea.id = fs.area_id WHERE subarea.sub_city_id = :filter_sub_city)';
            $parameters['filter_sub_city'] = $filters->subCityId;
        }

        if ($filters->openNow) {
            // ON-2/ON-4: unknown hours are excluded, never treated as closed.
            $clauses[] = 'sd.has_confirmed_hours = 1';
        }

        if ($filters->minRating !== null) {
            // TR-63: a Listing with no Reviews is excluded by a rating
            // filter, never treated as zero.
            $clauses[] = 'sd.rating_avg IS NOT NULL';
            $clauses[] = 'sd.rating_avg >= :filter_min_rating';
            $parameters['filter_min_rating'] = $filters->minRating;
        }

        if ($filters->verifiedOnly) {
            $clauses[] = 'sd.verified = 1';
        }

        return implode(' AND ', $clauses);
    }

    /**
     * @param list<int|string>    $values
     * @param array<string, mixed> $parameters
     *
     * @return list<string>
     */
    private function tokenNames(array $values, string $prefix, array &$parameters): array
    {
        $names = [];

        foreach (array_values($values) as $index => $value) {
            $name = $prefix . '_' . $index;
            $names[] = ':' . $name;
            $parameters[$name] = $value;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<MatchRow>
     */
    private function matchRows(string $sql, array $parameters): array
    {
        $statement = $this->connection->pdo()->prepare($sql);
        Bind::typed($statement, $parameters);

        $rows = [];

        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = new MatchRow(
                Row::int($row, 'business_id'),
                Row::string($row, 'status'),
                Row::int($row, 'verified') === 1,
                DatabaseTime::parse(Row::nullableString($row, 'verified_at')),
                Row::int($row, 'rating_count'),
                $this->nullableFloat($row, 'rating_avg'),
                $this->nullableInt($row, 'completeness'),
            );
        }

        return $rows;
    }

    /**
     * A DECIMAL and an integer-or-NULL column, narrowed without coercion —
     * the two engines hand these back as floats, strings or null and only a
     * numeric value is acceptable (the `Row` philosophy, one column wider).
     *
     * @param array<array-key, mixed> $row
     */
    private function nullableFloat(array $row, string $column): ?float
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new UnexpectedValueException('Column [' . $column . '] should be a number or null.');
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function nullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Column [' . $column . '] should be an integer or null.');
    }

    private function deleteRows(int $businessId): void
    {
        foreach ([self::AREAS, self::CATEGORIES, self::TOKENS, self::DOCUMENTS] as $table) {
            $statement = $this->connection->pdo()->prepare('DELETE FROM ' . $table . ' WHERE business_id = :business_id');
            Bind::typed($statement, ['business_id' => $businessId]);
        }
    }

    /**
     * @param list<array{token: string, inName: bool}> $tokens
     */
    private function insertTokens(int $businessId, array $tokens): void
    {
        // Chunked: SQLite bounds the parameters of one statement, and a long
        // description can carry hundreds of tokens.
        foreach (array_chunk($tokens, 100) as $chunk) {
            $values = [];
            $parameters = [];

            foreach ($chunk as $index => $token) {
                $values[] = '(:token_business_' . $index . ', :token_text_' . $index . ', :token_in_name_' . $index . ')';
                $parameters['token_business_' . $index] = $businessId;
                $parameters['token_text_' . $index] = mb_substr($token['token'], 0, 191);
                $parameters['token_in_name_' . $index] = $token['inName'] ? 1 : 0;
            }

            $statement = $this->connection->pdo()->prepare(
                'INSERT INTO ' . self::TOKENS . ' (business_id, token, in_name) VALUES ' . implode(', ', $values),
            );
            Bind::typed($statement, $parameters);
        }
    }

    /**
     * @param list<array{categoryId: int, isPrimary: bool}> $categories
     */
    private function insertCategories(int $businessId, array $categories): void
    {
        foreach ($categories as $index => $category) {
            $statement = $this->connection->pdo()->prepare(
                'INSERT INTO ' . self::CATEGORIES . ' (business_id, category_id, is_primary)'
                . ' VALUES (:category_business, :category_id, :category_primary)',
            );
            $statement->execute([
                'category_business' => $businessId,
                'category_id'       => $category['categoryId'],
                'category_primary'  => $category['isPrimary'] ? 1 : 0,
            ]);
        }
    }

    /**
     * @param list<int> $areaIds
     */
    private function insertAreas(int $businessId, array $areaIds): void
    {
        foreach ($areaIds as $areaId) {
            $statement = $this->connection->pdo()->prepare(
                'INSERT INTO ' . self::AREAS . ' (business_id, area_id) VALUES (:area_business, :area_id)',
            );
            $statement->execute(['area_business' => $businessId, 'area_id' => $areaId]);
        }
    }
}
