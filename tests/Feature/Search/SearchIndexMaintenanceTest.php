<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Search;

use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Exceptions\PublicationRefusedException;
use Bulbula\Backend\Repositories\Platform\AuditEntryRepository;
use Bulbula\Backend\Services\Directory\BusinessService;
use Bulbula\Backend\Services\Directory\PublicationGate;
use Bulbula\Backend\Services\Directory\PublishListingService;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * Search-document maintenance (search-design §3.3, SD-1…SD-7): the document
 * follows the listing through every state change, in the same transaction
 * (SD-2), and the rebuild commands repair whatever drift produces (SD-4, SD-5).
 */
final class SearchIndexMaintenanceTest extends DatabaseTestCase
{
    private DirectoryScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = new DirectoryScenario($this->database(), $this->clock);
    }

    #[Test]
    public function publishing_indexes_the_document_and_unpublishing_removes_it(): void
    {
        $business = $this->scenario->publishedBusiness();

        self::assertSame(1, $this->documentCount());
        self::assertSame('published', $this->documentStatus($business->id));

        $this->publisher()->unpublish($business->id, 'withdrawn', ActorType::Operator, 7);

        // SD-7: unpublishing deletes the row.
        self::assertSame(0, $this->documentCount());
    }

    #[Test]
    public function closure_keeps_the_listing_findable_with_its_state_recorded(): void
    {
        $business = $this->scenario->publishedBusiness();
        $this->businessService()->close($business->id, 'owner retired', ActorType::Operator, 7);

        // MT-2 excludes draft, unpublished and removed — not closed (PR-2):
        // a closed Business stays findable and shows as closed.
        self::assertSame(1, $this->documentCount());
        self::assertSame('closed', $this->documentStatus($business->id));
    }

    #[Test]
    public function a_profile_edit_updates_the_document_immediately(): void
    {
        $business = $this->scenario->publishedBusiness();

        $this->businessService()->updateProfile(
            $business->id,
            ['name' => 'Belay Pharmacy'],
            $business->version,
            ActorType::Operator,
            7,
        );

        // SD-2: the same transaction, so a staff edit is visible at once.
        $row = $this->document($business->id);

        self::assertSame('belay pharmacy', $row['name_norm']);
        self::assertStringContainsString('belay pharmacy', $row['name_text']);
    }

    #[Test]
    public function a_draft_has_no_document(): void
    {
        $this->scenario->business('Not Ready Yet');

        self::assertSame(0, $this->documentCount());
    }

    #[Test]
    public function rebuild_is_idempotent_and_repairs_a_lost_document(): void
    {
        $business = $this->scenario->publishedBusiness();

        $this->database()->pdo()->exec('DELETE FROM search_documents');

        self::assertSame(0, $this->documentCount());

        $first = $this->scenario->indexer()->rebuildAll();
        $second = $this->scenario->indexer()->rebuildAll();

        self::assertSame(1, $first['rebuilt']);
        self::assertSame(1, $second['rebuilt']);
        self::assertSame(1, $this->documentCount());
        self::assertSame('published', $this->documentStatus($business->id));
    }

    #[Test]
    public function drift_reports_a_missing_document_and_is_clean_after_a_rebuild(): void
    {
        // The document is built by hand-stating the business's rows — no
        // indexer call — which is exactly what a half-failed deploy looks like.
        $this->scenario->publishedBusiness();
        $this->database()->pdo()->exec('DELETE FROM search_documents');

        $drift = $this->scenario->indexer()->driftReport();

        self::assertSame(1, $drift['expected']);
        self::assertSame(0, $drift['indexed']);
        self::assertNotSame([], $drift['missing']);

        $this->scenario->indexer()->rebuildAll();

        $clean = $this->scenario->indexer()->driftReport();

        self::assertSame(1, $clean['indexed']);
        self::assertSame([], $clean['missing']);
        self::assertSame([], $clean['stale']);
        self::assertSame([], $clean['orphaned']);
    }

    #[Test]
    public function a_failed_publication_never_leaves_a_document(): void
    {
        $business = $this->scenario->business('Gate Refused');

        try {
            $this->publisher()->publish($business->id, ActorType::Operator, 7);
            self::fail('The gate should have refused an unclassified listing.');
        } catch (PublicationRefusedException) {
            // expected — no Branch, no Category, no permission.
        }

        self::assertSame(0, $this->documentCount());
    }

    private function publisher(): PublishListingService
    {
        return new PublishListingService(
            $this->scenario->businesses,
            $this->scenario->permissions,
            new PublicationGate($this->scenario->businesses, $this->scenario->branches),
            new AuditRecorder(new AuditEntryRepository($this->database()), $this->clock),
            $this->scenario->indexer(),
            $this->database(),
            new NullLogger(),
            $this->clock,
        );
    }

    private function businessService(): BusinessService
    {
        return new BusinessService(
            $this->scenario->businesses,
            $this->scenario->branches,
            $this->scenario->categories,
            $this->scenario->areas,
            $this->scenario->subCities,
            $this->scenario->redirects,
            new AuditRecorder(new AuditEntryRepository($this->database()), $this->clock),
            $this->scenario->indexer(),
            $this->database(),
            $this->clock,
        );
    }

    private function documentCount(): int
    {
        $row = $this->database()->pdo()->query('SELECT COUNT(*) AS n FROM search_documents')->fetch();

        return is_array($row) ? (int) $row['n'] : 0;
    }

    private function documentStatus(int $businessId): string
    {
        return (string) $this->document($businessId)['status'];
    }

    /** @return array<string, mixed> */
    private function document(int $businessId): array
    {
        $statement = $this->database()->pdo()->prepare('SELECT * FROM search_documents WHERE business_id = :business_id');
        $statement->execute(['business_id' => $businessId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : [];
    }
}
