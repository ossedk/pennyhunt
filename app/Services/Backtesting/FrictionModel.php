<?php

namespace App\Services\Backtesting;

/**
 * Per-ticker round-trip cost estimate (spread + slippage + fees), replacing
 * the flat 5% haircut. Grounded in how penny-stock spreads actually scale:
 * price band sets the tick-relative spread, dollar volume sets the impact.
 *
 *   base by entry price:  <$0.50: 5.0%, <$1: 4.0%, <$2.50: 3.0%, else 2.2%
 *   liquidity adjustment: dollar volume <$250k: +1.5%, <$1M: +0.5%,
 *                         >$5M: −0.7%
 *   clamped to [1.5%, 8%]
 *
 * Deliberately conservative — this should overtax, never flatter.
 */
class FrictionModel
{
    public static function roundTrip(?float $entryPrice, ?float $dollarVolume): float
    {
        $price = $entryPrice ?? 1.0;

        $base = match (true) {
            $price < 0.50 => 0.050,
            $price < 1.00 => 0.040,
            $price < 2.50 => 0.030,
            default => 0.022,
        };

        $adjustment = match (true) {
            $dollarVolume === null => 0.005,
            $dollarVolume < 250_000 => 0.015,
            $dollarVolume < 1_000_000 => 0.005,
            $dollarVolume > 5_000_000 => -0.007,
            default => 0.0,
        };

        return min(max($base + $adjustment, 0.015), 0.08);
    }
}
