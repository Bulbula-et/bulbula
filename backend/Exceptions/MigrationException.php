<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

use RuntimeException;

/**
 * A schema change that cannot be applied safely.
 *
 * Distinct from a configuration error: the configuration can be right and the
 * migration history still be wrong (a branch from last week, a deploy that
 * went backwards). It fails the command rather than guessing.
 */
final class MigrationException extends RuntimeException
{
}
