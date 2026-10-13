<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Search;

use Bulbula\Backend\Services\Search\DocumentSources;
use Bulbula\Backend\Services\Search\SearchDocumentBuilder;
use Bulbula\Backend\Services\Search\TextNormaliser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Composition rules (search-design §3.2, §6): which text lands in which
 * column, that Aliases are matching inputs and never display labels (AL-3),
 * and that undecided inputs stay undecided (D-09 open → completeness NULL).
 */
final class SearchDocumentBuilderTest extends TestCase
{
    private function builder(): SearchDocumentBuilder
    {
        return new SearchDocumentBuilder(new TextNormaliser(2, []));
    }

    private function sources(): DocumentSources
    {
        return new DocumentSources(
            'Tsegaye Pharmacy',
            'ጸጋዬ ፋርማሲ',
            'A neighbourhood pharmacy.',
            null,
            'published',
            3,
            7,
            2,
            8.98,
            38.75,
            '2026-10-13 09:00:00',
            '2026-10-13 09:00:00',
            [
                [
                    'categoryId' => 3,
                    'isPrimary'  => true,
                    'name'       => 'Pharmacies',
                    'nameAm'     => 'ፋርማሲዎች',
                    'aliases'    => [['text' => 'drug store', 'language' => 'en']],
                ],
            ],
            [],
            [
                [
                    'id'        => 7,
                    'name'      => 'Bole Bulbula',
                    'nameAm'    => null,
                    'subCityId' => 2,
                    'aliases'   => [['text' => 'bulbula', 'language' => null]],
                ],
            ],
            [['name' => 'Bole', 'nameAm' => 'ቦሌ']],
            ['Main St'],
        );
    }

    #[Test]
    public function each_text_column_composes_from_its_own_sources(): void
    {
        $document = $this->builder()->build($this->sources());

        self::assertSame('tsegaye pharmacy ጸጋዬ ፋርማሲ main st', $document->nameText);
        self::assertSame('pharmacies ፋርማሲዎች drug store', $document->classificationText);
        self::assertSame('bole bulbula bulbula bole ቦሌ', $document->locationText);
        self::assertSame('a neighbourhood pharmacy', $document->descriptionText);
        self::assertSame('tsegaye pharmacy', $document->nameNorm);
    }

    #[Test]
    public function aliases_fold_into_matching_text_and_nowhere_else(): void
    {
        $document = $this->builder()->build($this->sources());

        // AL-3: matching inputs, never display labels — the composed text is
        // where they live, and no display field carries them.
        self::assertStringContainsString('drug store', $document->classificationText);
        self::assertStringContainsString('bulbula', $document->locationText);
        self::assertStringNotContainsString('drug store', $document->nameText);
    }

    #[Test]
    public function a_token_appearing_in_the_name_is_flagged(): void
    {
        $document = $this->builder()->build($this->sources());
        $byToken = [];

        foreach ($document->tokens as $token) {
            $byToken[$token['token']] = $token['inName'];
        }

        self::assertTrue($byToken['tsegaye']);
        self::assertTrue($byToken['pharmacy']);
        self::assertFalse($byToken['drug']);
        self::assertFalse($byToken['bole']);
    }

    #[Test]
    public function undecided_inputs_stay_undecided(): void
    {
        $document = $this->builder()->build($this->sources());

        // D-09 open: completeness is not defined yet and must not be
        // invented. Verification and ratings arrive with their modules.
        self::assertNull($document->completeness);
        self::assertFalse($document->verified);
        self::assertNull($document->verifiedAt);
        self::assertSame(0, $document->ratingCount);
        self::assertNull($document->ratingAverage);
        self::assertFalse($document->hasConfirmedHours);
    }

    #[Test]
    public function facet_rows_carry_primary_flags_and_every_area(): void
    {
        $document = $this->builder()->build($this->sources());

        self::assertSame([['categoryId' => 3, 'isPrimary' => true]], $document->categories);
        self::assertSame([7], $document->areaIds);
        self::assertSame(3, $document->primaryCategoryId);
        self::assertSame(7, $document->areaId);
    }

    #[Test]
    public function a_parent_category_path_joins_the_matching_text_but_not_the_facets(): void
    {
        $sources = $this->sources();
        $document = $this->builder()->build(new DocumentSources(
            'Night Owl Drugs',
            null,
            null,
            null,
            'published',
            4,
            7,
            2,
            null,
            null,
            '2026-10-13 09:00:00',
            '2026-10-13 09:00:00',
            [
                [
                    'categoryId' => 4,
                    'isPrimary'  => true,
                    'name'       => '24 hour',
                    'nameAm'     => null,
                    'aliases'    => [],
                ],
            ],
            [
                [
                    'name'    => 'Pharmacies',
                    'nameAm'  => null,
                    'aliases' => [['text' => 'drug store', 'language' => 'en']],
                ],
            ],
            $sources->areas,
            $sources->subCities,
            [],
        ));

        // "24 hour" *is* a Pharmacy: the parent's name and Aliases are
        // matching inputs (SR-2)…
        self::assertStringContainsString('24 hour pharmacies drug store', $document->classificationText);

        // …but the facet rows stay the Listing's own classification (D-57).
        self::assertSame([['categoryId' => 4, 'isPrimary' => true]], $document->categories);
    }
}
