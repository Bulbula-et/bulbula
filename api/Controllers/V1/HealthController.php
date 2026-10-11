<?php

declare(strict_types=1);

namespace Bulbula\Api\Controllers\V1;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Support\Health\HealthChecker;

/**
 * The operational probes.
 *
 * Two questions, four paths: liveness at `/health` and `/api/v1/health`,
 * readiness at `/health/ready` and `/api/v1/health/ready`. They live with the
 * API controllers because that is what they emit — JSON, in every case,
 * including failure — and the controller does nothing but ask the checker and
 * serialise the answer.
 *
 * Nothing about the deployment appears in a response: no version, no host, no
 * path, no credential, and no exception message.
 */
final class HealthController
{
    public function __construct(private readonly HealthChecker $health)
    {
    }

    /** Liveness: `/health`, `/api/v1/health`. */
    public function live(Request $request): Response
    {
        return $this->noStore(Response::json([
            'status' => $this->health->liveness() ? 'pass' : 'fail',
        ]));
    }

    /** Readiness: `/health/ready`, `/api/v1/health/ready`. */
    public function ready(Request $request): Response
    {
        $report = $this->health->readiness();

        return $this->noStore(Response::json(
            $report->toArray(),
            $report->passes() ? 200 : 503,
        ));
    }

    /** A cached probe result is worse than no probe result. */
    private function noStore(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
