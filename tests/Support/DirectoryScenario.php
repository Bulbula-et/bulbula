<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Branch;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Domain\Directory\SubCity;
use Bulbula\Backend\Domain\Operations\PermissionMethod;
use Bulbula\Backend\Domain\Operations\PermissionRecord;
use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Repositories\Directory\AliasRepository;
use Bulbula\Backend\Repositories\Directory\AreaRepository;
use Bulbula\Backend\Repositories\Directory\BranchRepository;
use Bulbula\Backend\Repositories\Directory\BusinessRepository;
use Bulbula\Backend\Repositories\Directory\CategoryRepository;
use Bulbula\Backend\Repositories\Directory\SubCityRepository;
use Bulbula\Backend\Repositories\Operations\PermissionRecordRepository;
use Bulbula\Backend\Repositories\Platform\AuditEntryRepository;
use Bulbula\Backend\Repositories\Platform\RedirectRepository;
use Bulbula\Backend\Repositories\Search\SearchDocumentRepository;
use Bulbula\Backend\Services\Directory\PublicationGate;
use Bulbula\Backend\Services\Directory\PublishListingService;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Backend\Services\Search\SearchDocumentBuilder;
use Bulbula\Backend\Services\Search\SearchIndexer;
use Bulbula\Backend\Services\Search\TextNormaliser;
use Bulbula\Backend\Support\Clock;
use Psr\Log\NullLogger;

/**
 * A seeded Directory, for the tests that exercise the business/listing rules.
 *
 * The rows are created through the repositories — the same statements the
 * application uses — so a test never asserts against a schema the application
 * cannot actually write. Reference data is created once per scenario and
 * reused; a Business, a Branch and a Permission are created on demand, because
 * the rules under test are precisely about which combinations of them are
 * allowed to exist.
 */
final class DirectoryScenario
{
    public readonly BusinessRepository $businesses;

    public readonly BranchRepository $branches;

    public readonly CategoryRepository $categories;

    public readonly AreaRepository $areas;

    public readonly SubCityRepository $subCities;

    public readonly PermissionRecordRepository $permissions;

    public readonly RedirectRepository $redirects;

    public readonly AliasRepository $aliases;

    private ?SubCity $subCity = null;

    private ?Area $area = null;

    private ?Category $category = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
        $this->businesses = new BusinessRepository($connection);
        $this->branches = new BranchRepository($connection);
        $this->categories = new CategoryRepository($connection);
        $this->areas = new AreaRepository($connection);
        $this->subCities = new SubCityRepository($connection);
        $this->permissions = new PermissionRecordRepository($connection);
        $this->redirects = new RedirectRepository($connection);
        $this->aliases = new AliasRepository($connection);
    }

    /** The real search indexer, wired as the application wires it (SD-2). */
    public function indexer(): SearchIndexer
    {
        return new SearchIndexer(
            $this->businesses,
            $this->categories,
            $this->branches,
            $this->areas,
            $this->subCities,
            $this->aliases,
            new SearchDocumentBuilder(new TextNormaliser(2, [])),
            new SearchDocumentRepository($this->connection),
        );
    }

    public function subCity(): SubCity
    {
        return $this->subCity ??= $this->subCities->create(
            Slug::fromString('bole'),
            'Bole',
            'ቦሌ',
            'Addis Ababa',
            $this->clock->now(),
        );
    }

    public function area(): Area
    {
        return $this->area ??= $this->areas->create(
            $this->subCity()->id,
            Slug::fromString('bole-bulbula'),
            'Bole Bulbula',
            null,
            $this->clock->now(),
        );
    }

    public function category(string $slug = 'pharmacies', string $name = 'Pharmacies'): Category
    {
        return $this->category ??= $this->categories->create(
            null,
            Slug::fromString($slug),
            $name,
            null,
            $this->clock->now(),
        );
    }

    public function business(string $name = 'Tsegaye Pharmacy', ?Slug $slug = null): Business
    {
        return $this->businesses->create(
            $slug ?? Slug::fromName($name),
            $name,
            null,
            null,
            $this->clock->now(),
            null,
        );
    }

    public function branch(Business $business, bool $isPrimary = true, ?Area $area = null): Branch
    {
        return $this->branches->create(
            $business->id,
            ($area ?? $this->area())->id,
            $this->subCity()->id,
            null,
            'Meskel Flower Road',
            9.010000,
            38.760000,
            '+251911000000',
            null,
            null,
            $isPrimary,
            $this->clock->now(),
        );
    }

    public function permission(Business $business, string $person = 'Tsegaye Bekele'): PermissionRecord
    {
        return $this->permissions->grant(
            $business->id,
            $person,
            'Owner',
            PermissionMethod::InPerson,
            $this->clock->now(),
            $this->clock->now(),
        );
    }

    /**
     * A Business in the state the public read path requires: classified under
     * one primary Category, with a Branch and a granted Permission record,
     * published through the real gate — so a read test never asserts against a
     * state the application could not actually reach (TR-49).
     */
    public function publishedBusiness(string $name = 'Tsegaye Pharmacy', ?Slug $slug = null): Business
    {
        $business = $this->business($name, $slug);
        $this->branch($business);
        $this->businesses->assignCategory($business->id, $this->category()->id, true, $this->clock->now());
        $this->permission($business);

        return $this->publisher()->publish($business->id, ActorType::Operator, 7);
    }

    /** The real publication path, wired as the application wires it. */
    private function publisher(): PublishListingService
    {
        return new PublishListingService(
            $this->businesses,
            $this->permissions,
            new PublicationGate($this->businesses, $this->branches),
            new AuditRecorder(new AuditEntryRepository($this->connection), $this->clock),
            $this->indexer(),
            $this->connection,
            new NullLogger(),
            $this->clock,
        );
    }

    /**
     * The audit trail, as the tests read it: append-only rows, newest last.
     *
     * @return list<array{action: string, target_id: int|null, reason: string|null}>
     */
    public function auditActions(): array
    {
        $rows = $this->connection->pdo()
            ->query('SELECT action, target_id, reason FROM audit_entries ORDER BY id ASC')
            ->fetchAll();

        $actions = [];

        foreach ($rows as $row) {
            $target = $row['target_id'] ?? null;

            $actions[] = [
                'action'    => (string) $row['action'],
                'target_id' => is_int($target) || is_string($target) ? (int) $target : null,
                'reason'    => isset($row['reason']) && is_string($row['reason']) ? $row['reason'] : null,
            ];
        }

        return $actions;
    }

    /** @param list<string> $needles */
    public function auditHas(string $action, ?int $targetId = null, ?string $reasonContains = null): bool
    {
        foreach ($this->auditActions() as $entry) {
            if ($entry['action'] !== $action) {
                continue;
            }

            if ($targetId !== null && $entry['target_id'] !== $targetId) {
                continue;
            }

            if ($reasonContains !== null && ($entry['reason'] === null || !str_contains($entry['reason'], $reasonContains))) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** The stored state, read the way any other client of the database would read it. */
    public function storedStatus(int $businessId): string
    {
        $statement = $this->connection->pdo()->prepare('SELECT status FROM businesses WHERE id = :id');
        $statement->execute(['id' => $businessId]);

        return (string) $statement->fetchColumn();
    }

    public function storedVersion(int $businessId): int
    {
        $statement = $this->connection->pdo()->prepare('SELECT version FROM businesses WHERE id = :id');
        $statement->execute(['id' => $businessId]);

        return (int) $statement->fetchColumn();
    }

    public function storedColumn(int $businessId, string $column): ?string
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . $column . ' FROM businesses WHERE id = :id',
        );
        $statement->execute(['id' => $businessId]);

        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function storedTimestamp(int $businessId, string $column): ?string
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . $column . ' FROM businesses WHERE id = :id',
        );
        $statement->execute(['id' => $businessId]);

        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /** The clock's own formatted "now", for asserting a stored timestamp. */
    public function clockNow(): string
    {
        return DatabaseTime::format($this->clock->now());
    }
}
