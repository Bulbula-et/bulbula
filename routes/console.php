<?php

declare(strict_types=1);

/**
 * Console: the command table of contents (TD-06, TD-07 territory).
 *
 * The names are declared here and wired in the composition root, exactly like
 * routes and controllers: a name in this list with no factory is a boot-time
 * error, and a factory with no name is unreachable. That keeps one question —
 * "what can this deployment do from the command line?" — answerable by reading
 * one short file.
 *
 * Commands arrive with the slice that needs them:
 *
 *   M1  migrate · migration:status · rollback
 *   M2  reference:import (categories, areas) · search:reindex
 *   M3  search:reindex:drift · jobs:work (the queued-job drain, TD-06)
 */
return [
    'migrate',
    'migration:status',
    'rollback',
];
