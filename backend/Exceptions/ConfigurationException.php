<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

use RuntimeException;

/**
 * Thrown while booting. A misconfigured application must fail loudly at the
 * first request rather than quietly running with a wrong default.
 */
final class ConfigurationException extends RuntimeException
{
}
