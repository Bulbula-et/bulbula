<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Platform;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use DateTimeImmutable;
use Throwable;

/**
 * Counters behind the limits in `config/security.php` (TRD §34).
 *
 * Operational state, not product data, which is why it sits in Platform: the
 * same table will serve the review, report and search limits without any of
 * those areas owning it.
 *
 * A counter is not a security boundary by itself — the limiter on top decides
 * — but a counter that can silently lose its own writes is worse than none, so
 * the read-modify-write happens in a transaction and a lost race retries once
 * instead of throwing the request away.
 */
final class RateLimitRepository
{
    private const TABLE = 'rate_limits';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Count one hit and report the running total for the current window.
     *
     * The window starts on the first hit, so a limit is "per source per
     * window", not a fixed clock the caller has to reason about.
     *
     * `retry_after` is how long the caller would have to wait for the count to
     * start again — the number a `429` is required to carry
     * (`api-spec-v1.0.md` §1.5). It is a property of this row, so it is
     * answered here rather than reconstructed by the caller.
     *
     * @return array{hits: int, retry_after: int}
     */
    public function hit(string $bucket, string $key, int $windowSeconds, DateTimeImmutable $now): array
    {
        try {
            return $this->countWithin($bucket, $key, $windowSeconds, $now, true);
        } catch (Throwable) {
            // Two first hits at once: the loser of the insert race counts
            // against the row the winner created.
            return $this->countWithin($bucket, $key, $windowSeconds, $now, false);
        }
    }

    /**
     * @return array{hits: int, retry_after: int}
     */
    private function countWithin(
        string $bucket,
        string $key,
        int $windowSeconds,
        DateTimeImmutable $now,
        bool $mayInsert,
    ): array {
        $pdo = $this->connection->pdo();

        $pdo->beginTransaction();

        try {
            $read = $pdo->prepare(
                'SELECT window_start, hits FROM ' . self::TABLE . ' WHERE bucket = :bucket AND bucket_key = :key',
            );
            $read->execute(['bucket' => $bucket, 'key' => $key]);
            $row = $read->fetch();

            if (!is_array($row)) {
                if (!$mayInsert) {
                    $pdo->commit();

                    return ['hits' => 1, 'retry_after' => $windowSeconds];
                }

                $insert = $pdo->prepare(
                    'INSERT INTO ' . self::TABLE . ' (bucket, bucket_key, window_start, hits)'
                    . ' VALUES (:bucket, :key, :now, 1)',
                );
                $insert->execute(['bucket' => $bucket, 'key' => $key, 'now' => DatabaseTime::format($now)]);
                $pdo->commit();

                return ['hits' => 1, 'retry_after' => $windowSeconds];
            }

            $started = DatabaseTime::parse(Row::string($row, 'window_start'));
            $hits = Row::int($row, 'hits');

            $windowOver = $started === null
                || $started->getTimestamp() + $windowSeconds <= $now->getTimestamp();

            $hits = $windowOver ? 1 : $hits + 1;

            $endsAt = ($windowOver ? $now : $started)->getTimestamp() + $windowSeconds;
            $retryAfter = max(1, $endsAt - $now->getTimestamp());

            $update = $pdo->prepare(
                'UPDATE ' . self::TABLE . ' SET hits = :hits, window_start = :started'
                . ' WHERE bucket = :bucket AND bucket_key = :key',
            );
            $update->execute([
                'hits'    => $hits,
                'started' => DatabaseTime::format($windowOver ? $now : $started),
                'bucket'  => $bucket,
                'key'     => $key,
            ]);

            $pdo->commit();

            return ['hits' => $hits, 'retry_after' => $retryAfter];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }
    }
}
