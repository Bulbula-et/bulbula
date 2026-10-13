<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console\Commands;

use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Repositories\Directory\AliasRepository;
use Bulbula\Backend\Repositories\Directory\AreaRepository;
use Bulbula\Backend\Repositories\Directory\CategoryRepository;
use Bulbula\Backend\Repositories\Directory\SubCityRepository;
use Bulbula\Backend\Services\Search\TextNormaliser;
use Bulbula\Backend\Support\Clock;
use DateTimeImmutable;
use RuntimeException;

/**
 * `php bin/console reference:import <file>` — load catalogue and location
 * content (TR-177, D-56).
 *
 * The Category catalogue and the Area set are **content**, not application
 * schema: a migration that ships them is a defect (REL-6.10). They arrive
 * through this command, from a PHP file that returns:
 *
 *     return [
 *         'sub_cities'    => [ ['slug' => 'bole', 'name' => 'Bole', 'name_am' => 'ቦሌ', 'city' => 'Addis Ababa'], … ],
 *         'areas'         => [ ['slug' => 'bole-bulbula', 'name' => 'Bole Bulbula', 'sub_city' => 'bole'], … ],
 *         'categories'    => [ ['slug' => 'pharmacies', 'name' => 'Pharmacies'], … ],
 *         'subcategories' => [ ['parent' => 'pharmacies', 'slug' => '24-hour', 'name' => '24 hour'], … ],
 *         'aliases'       => [ ['target' => 'category', 'slug' => 'pharmacies', 'alias' => 'drug store'], … ],
 *     ];
 *
 * Every entry is matched by slug and updated in place, so a re-import is
 * safe to re-run and never duplicates a row. Adding an Area or an Alias is a
 * data operation with no deployment (GEO-4, AL-5).
 */
final class ReferenceImportCommand implements Command
{
    public function __construct(
        private readonly SubCityRepository $subCities,
        private readonly AreaRepository $areas,
        private readonly CategoryRepository $categories,
        private readonly AliasRepository $aliases,
        private readonly TextNormaliser $normaliser,
        private readonly Clock $clock,
        private readonly Output $output,
    ) {
    }

    public function name(): string
    {
        return 'reference:import';
    }

    public function description(): string
    {
        return 'Import catalogue and location content (categories, areas, aliases)';
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $path = $arguments[0] ?? '';

        if ($path === '') {
            $this->output->error('Usage: reference:import <file>');

            return 1;
        }

        if (!is_file($path)) {
            $this->output->error('No such import file: ' . $path);

            return 1;
        }

        /** @var mixed $payload */
        $payload = require $path;

        if (!is_array($payload)) {
            $this->output->error('The import file must return an array.');

            return 1;
        }

        $now = $this->clock->now();

        try {
            $counts = $this->import($payload, $now);
        } catch (RuntimeException $error) {
            $this->output->error($error->getMessage());

            return 1;
        }

        foreach ($counts as $what => $count) {
            $this->output->line($what . ': ' . $count);
        }

        return 0;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, int>
     */
    private function import(array $payload, DateTimeImmutable $now): array
    {
        $subCities = 0;
        $areas = 0;
        $categories = 0;

        foreach ($this->rows($payload, 'sub_cities') as $row) {
            $slug = Slug::fromString($this->requireString($row, 'slug', 'sub_cities'));

            if ($this->subCities->findBySlug($slug) === null) {
                $this->subCities->create(
                    $slug,
                    $this->requireString($row, 'name', 'sub_cities'),
                    $this->optionalString($row, 'name_am'),
                    $this->requireString($row, 'city', 'sub_cities'),
                    $now,
                );
            }

            $subCities++;
        }

        foreach ($this->rows($payload, 'areas') as $row) {
            $slug = Slug::fromString($this->requireString($row, 'slug', 'areas'));
            $parent = $this->subCities->findBySlug(Slug::fromString($this->requireString($row, 'sub_city', 'areas')));

            if ($parent === null) {
                throw new RuntimeException('Area ' . $slug->value . ' names a Sub-city that does not exist.');
            }

            if ($this->areas->findBySlug($slug) === null) {
                $this->areas->create(
                    $parent->id,
                    $slug,
                    $this->requireString($row, 'name', 'areas'),
                    $this->optionalString($row, 'name_am'),
                    $now,
                );
            }

            $areas++;
        }

        foreach (['categories' => null, 'subcategories' => 'parent'] as $section => $parentKey) {
            foreach ($this->rows($payload, $section) as $row) {
                $slug = Slug::fromString($this->requireString($row, 'slug', $section));
                $parentId = null;

                if ($parentKey !== null) {
                    $parent = $this->categories->findBySlug(Slug::fromString($this->requireString($row, $parentKey, $section)));

                    if ($parent === null) {
                        throw new RuntimeException('Subcategory ' . $slug->value . ' names a Category that does not exist.');
                    }

                    $parentId = $parent->id;
                }

                if ($this->categories->findBySlug($slug) === null) {
                    $this->categories->create(
                        $parentId,
                        $slug,
                        $this->requireString($row, 'name', $section),
                        $this->optionalString($row, 'name_am'),
                        $now,
                    );
                }

                $categories++;
            }
        }

        $aliasCount = 0;

        foreach ($this->rows($payload, 'aliases') as $row) {
            $targetType = $this->requireString($row, 'target', 'aliases');
            $targetSlug = Slug::fromString($this->requireString($row, 'slug', 'aliases'));
            $aliasText = $this->requireString($row, 'alias', 'aliases');

            $target = $targetType === 'area'
                ? $this->areas->findBySlug($targetSlug)
                : $this->categories->findBySlug($targetSlug);

            if ($target === null) {
                throw new RuntimeException('Alias target does not exist: ' . $targetType . ' ' . $targetSlug->value);
            }

            $added = $this->aliases->add(
                $targetType,
                $target->id,
                $aliasText,
                $this->normaliser->normalise($aliasText),
                $this->optionalString($row, 'language'),
                DatabaseTime::format($now),
            );

            if ($added) {
                $aliasCount++;
            }
        }

        return [
            'sub-cities seen' => $subCities,
            'areas seen'      => $areas,
            'categories seen' => $categories,
            'aliases added'   => $aliasCount,
        ];
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<array<array-key, mixed>>
     */
    private function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? [];

        if (!is_array($rows)) {
            throw new RuntimeException('"' . $key . '" must be a list of rows.');
        }

        $filtered = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function requireString(array $row, string $key, string $section): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Every ' . $section . ' row needs a "' . $key . '".');
        }

        return trim($value);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function optionalString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
