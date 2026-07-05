<?php

namespace App\Support;

/**
 * Raise memory_limit for heavy jobs without dying on capped hosts.
 *
 * PHP 8.5 added max_memory_limit (PHP_INI_SYSTEM): when a runtime raise
 * exceeds it, ini_set emits a WARNING and clamps to the cap — and
 * Laravel's HandleExceptions promotes that warning to an ErrorException,
 * which killed backtest jobs on prod (cap 512M vs requested 3G). We
 * suppress the warning and accept whatever the host grants; the real cap
 * is lifted server-side via a PHP_INI_SCAN_DIR override (DEPLOYMENT.md).
 */
final class Memory
{
    public static function raise(string $limit): void
    {
        @ini_set('memory_limit', $limit);
    }
}
