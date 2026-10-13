<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Search;

use Bulbula\Backend\Console\Commands\ReferenceImportCommand;
use Bulbula\Backend\Console\Commands\SearchReindexCommand;
use Bulbula\Backend\Console\Commands\SearchReindexDriftCommand;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Services\Search\TextNormaliser;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three content and index commands (TR-177, SD-4, SD-5): catalogue
 * content arrives by import, never by migration; the rebuild is safe to
 * re-run; and the drift check reports without writing.
 */
final class SearchCommandsTest extends DatabaseTestCase
{
    private DirectoryScenario $scenario;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = new DirectoryScenario($this->database(), $this->clock);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function reference_import_loads_the_catalogue_and_is_safe_to_re_run(): void
    {
        $file = $this->importFile([
            'sub_cities'    => [['slug' => 'bole', 'name' => 'Bole', 'name_am' => 'ቦሌ', 'city' => 'Addis Ababa']],
            'areas'         => [['slug' => 'bole-bulbula', 'name' => 'Bole Bulbula', 'sub_city' => 'bole']],
            'categories'    => [['slug' => 'pharmacies', 'name' => 'Pharmacies']],
            'subcategories' => [['parent' => 'pharmacies', 'slug' => '24-hour', 'name' => '24 hour']],
            'aliases'       => [['target' => 'category', 'slug' => 'pharmacies', 'alias' => 'drug store']],
        ]);

        $first = $this->capture();
        self::assertSame(0, $this->importer($first)->run([$file]));
        self::assertStringContainsString('aliases added: 1', $first->stdout);

        $second = $this->capture();
        self::assertSame(0, $this->importer($second)->run([$file]));
        self::assertStringContainsString('aliases added: 0', $second->stdout);
        self::assertSame('', $second->stderr);

        // Matched by slug and updated in place: a re-import duplicates nothing.
        self::assertSame(1, $this->rowCount('sub_cities'));
        self::assertSame(2, $this->rowCount('categories'));
        self::assertSame(1, $this->rowCount('areas'));
        self::assertSame(1, $this->rowCount('aliases'));
    }

    #[Test]
    public function reference_import_refuses_an_alias_target_that_does_not_exist(): void
    {
        $file = $this->importFile([
            'aliases' => [['target' => 'category', 'slug' => 'missing', 'alias' => 'nope']],
        ]);

        $output = $this->capture();

        self::assertSame(1, $this->importer($output)->run([$file]));
        self::assertStringContainsString('Alias target does not exist', $output->stderr);
    }

    #[Test]
    public function reindex_rebuilds_from_source_and_drift_reports_clean(): void
    {
        $this->scenario->publishedBusiness();

        $this->database()->pdo()->exec('DELETE FROM search_documents');

        $rebuild = $this->capture();
        self::assertSame(0, $this->reindexer($rebuild)->run([]));
        self::assertStringContainsString('rebuilt  1 document(s)', $rebuild->stdout);

        $drift = $this->capture();
        self::assertSame(0, $this->drifter($drift)->run([]));
        self::assertStringContainsString('No drift', $drift->stdout);
    }

    #[Test]
    public function drift_names_the_missing_document_and_exits_non_zero(): void
    {
        $business = $this->scenario->publishedBusiness();
        $this->database()->pdo()->exec('DELETE FROM search_documents');

        $drift = $this->capture();

        self::assertSame(1, $this->drifter($drift)->run([]));
        self::assertStringContainsString('missing:                  ' . $business->id, $drift->stdout);
        self::assertStringContainsString('run `search:reindex`', $drift->stderr);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function importFile(array $payload): string
    {
        $file = sys_get_temp_dir() . '/bulbula-import-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\n\nreturn " . var_export($payload, true) . ";\n");
        $this->temporaryFiles[] = $file;

        return $file;
    }

    private function importer(Output $output): ReferenceImportCommand
    {
        return new ReferenceImportCommand(
            $this->scenario->subCities,
            $this->scenario->areas,
            $this->scenario->categories,
            $this->scenario->aliases,
            new TextNormaliser(2, []),
            $this->clock,
            $output,
        );
    }

    private function reindexer(Output $output): SearchReindexCommand
    {
        return new SearchReindexCommand($this->scenario->indexer(), $output);
    }

    private function drifter(Output $output): SearchReindexDriftCommand
    {
        return new SearchReindexDriftCommand($this->scenario->indexer(), $output);
    }

    private function rowCount(string $table): int
    {
        $row = $this->database()->pdo()->query('SELECT COUNT(*) AS n FROM ' . $table)->fetch();

        return is_array($row) ? (int) $row['n'] : 0;
    }

    private function capture(): Output
    {
        return new class () implements Output {
            public string $stdout = '';

            public string $stderr = '';

            public function line(string $text = ''): void
            {
                $this->stdout .= $text . "\n";
            }

            public function error(string $text): void
            {
                $this->stderr .= $text . "\n";
            }
        };
    }
}
