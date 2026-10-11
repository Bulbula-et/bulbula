<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

/**
 * Where a session is being used. Informational (data-model §5.4).
 *
 * A surface is not an identity: the Telegram Mini App is the web application
 * in an embedded webview, and it carries no claim the server trusts (D-33,
 * TR-33). It is recorded because abuse review needs to know where a session
 * came from.
 */
enum Surface: string
{
    case Web = 'web';
    case Telegram = 'telegram';
    case Api = 'api';
}
