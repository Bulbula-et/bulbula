<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Directory;

use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Operations\PermissionRecord;
use Bulbula\Backend\Repositories\Directory\BranchRepository;
use Bulbula\Backend\Repositories\Directory\BusinessRepository;

/**
 * The publication gate (TR-49, C-19, D-50).
 *
 * **No publication without a linked Permission record**, and no publication
 * while the required field set is incomplete. The gate is one object with one
 * answer — *what is missing* — so every path that publishes (operator action,
 * import, a future bulk job) refuses through the same reading of the rules and
 * none of them can re-implement them slightly differently.
 *
 * The requirements are the data model's own required fields, and nothing
 * invented on top:
 *
 * | Key | Requirement |
 * | --- | --- |
 * | `name` | The Business has a name (§3.1) |
 * | `exactly_one_primary_category` | Every Listing carries exactly one primary Category (D-57, §3.4) |
 * | `at_least_one_branch` | Every Business has at least one Branch (D-03, §3.2) |
 * | `exactly_one_primary_branch` | Exactly one Branch is primary (§3.2) |
 * | `permission_record` | The most recent Permission record is granted (D-50, §4.1) |
 *
 * Nothing here is a policy threshold: descriptions, contact points and hours
 * are optional in the model, so they are absent from the list, and adding a
 * requirement is a product decision that changes this class and its tests.
 */
final class PublicationGate
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BranchRepository $branches,
    ) {
    }

    /**
     * @return list<string> the unmet requirement keys, empty when publishable
     */
    public function unmetRequirements(Business $business, ?PermissionRecord $permission): array
    {
        $unmet = [];

        if (trim($business->name) === '') {
            $unmet[] = 'name';
        }

        if ($this->businesses->primaryCategoryCount($business->id) !== 1) {
            $unmet[] = 'exactly_one_primary_category';
        }

        if ($this->branches->countForBusiness($business->id) < 1) {
            $unmet[] = 'at_least_one_branch';
        } elseif ($this->branches->primaryCount($business->id) !== 1) {
            $unmet[] = 'exactly_one_primary_branch';
        }

        if ($permission === null || !$permission->isGranted()) {
            $unmet[] = 'permission_record';
        }

        return $unmet;
    }
}
