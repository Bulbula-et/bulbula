<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

/**
 * Composes one search document from source rows (search-design §3.2).
 *
 * The four text columns are weighted by *which column* — name, classification,
 * location, description — rather than by an invented numeric weight, so a name
 * match can be treated differently from a description match without asserting
 * by how much (§3.2; the policy question stays open under D-09). Aliases fold
 * into the classification and location text only (AL-3).
 *
 * Normalisation is the shared `TextNormaliser` on both sides (N-8): this
 * class never re-implements a rule the query path does not use.
 */
final class SearchDocumentBuilder
{
    public function __construct(private readonly TextNormaliser $normaliser)
    {
    }

    public function build(DocumentSources $sources): SearchDocumentData
    {
        $nameText = $this->joinText([
            $sources->name,
            $sources->nameAm,
            ...$sources->branchLabels,
        ]);

        $classificationParts = [];

        foreach (array_merge($sources->categories, $sources->parentCategories) as $category) {
            $classificationParts[] = $category['name'];
            $classificationParts[] = $category['nameAm'];

            foreach ($category['aliases'] as $alias) {
                $classificationParts[] = $alias['text'];
            }
        }

        $locationParts = [];

        foreach ($sources->areas as $area) {
            $locationParts[] = $area['name'];
            $locationParts[] = $area['nameAm'];

            foreach ($area['aliases'] as $alias) {
                $locationParts[] = $alias['text'];
            }
        }

        foreach ($sources->subCities as $subCity) {
            $locationParts[] = $subCity['name'];
            $locationParts[] = $subCity['nameAm'];
        }

        $locationText = $this->joinText($locationParts);

        $descriptionText = $this->joinText([
            $sources->shortDescription,
            $sources->longDescription,
        ]);

        $nameTokens = $this->normaliser->tokens($nameText);
        $classificationText = $this->joinText($classificationParts);
        $otherTokens = array_merge(
            $this->normaliser->tokens($classificationText),
            $this->normaliser->tokens($locationText),
            $this->normaliser->tokens($descriptionText),
        );

        $tokens = [];
        $inName = array_fill_keys($nameTokens, true);

        foreach ($nameTokens as $token) {
            $tokens[$token] = ['token' => $token, 'inName' => true];
        }

        foreach ($otherTokens as $token) {
            if (!isset($tokens[$token])) {
                $tokens[$token] = ['token' => $token, 'inName' => isset($inName[$token])];
            }
        }

        $categories = [];

        foreach ($sources->categories as $category) {
            $categories[] = [
                'categoryId' => $category['categoryId'],
                'isPrimary'  => $category['isPrimary'],
            ];
        }

        $areaIds = [];

        foreach ($sources->areas as $area) {
            $areaIds[$area['id']] = $area['id'];
        }

        return new SearchDocumentData(
            $sources->status,
            $this->normaliser->normalise($sources->name),
            $this->normaliser->normalise($nameText),
            $this->normaliser->normalise($classificationText),
            $this->normaliser->normalise($locationText),
            $this->normaliser->normalise($descriptionText),
            $sources->primaryCategoryId,
            $sources->areaId,
            $sources->subCityId,
            false,
            null,
            null,
            0,
            null,
            false,
            $sources->latitude,
            $sources->longitude,
            $sources->createdAt,
            $sources->updatedAt,
            array_values($tokens),
            $categories,
            array_values($areaIds),
        );
    }

    /**
     * @param list<?string> $parts
     */
    private function joinText(array $parts): string
    {
        $kept = [];

        foreach ($parts as $part) {
            if ($part !== null && trim($part) !== '') {
                $kept[] = trim($part);
            }
        }

        return implode(' ', $kept);
    }
}
