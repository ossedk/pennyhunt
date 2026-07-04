<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * FINRA Reg SHO daily short-sale volume files (free, keyless, published
 * nightly). CNMS covers NMS-listed symbols, CORF covers OTC — together the
 * whole penny-stock universe. Pipe-delimited:
 *
 *   Date|Symbol|ShortVolume|ShortExemptVolume|TotalVolume|Market
 */
class RegShoClient
{
    protected const FACILITIES = ['CNMS', 'CORF'];

    /**
     * All rows for one date (YYYYMMDD), keyed by symbol. Empty array on
     * weekends/holidays (files don't exist).
     *
     * @return array<string, array{short_volume: float, total_volume: float}>
     */
    public function dailyShortVolume(string $yyyymmdd): array
    {
        $out = [];

        foreach (self::FACILITIES as $facility) {
            $response = Http::timeout(60)
                ->get("https://cdn.finra.org/equity/regsho/daily/{$facility}shvol{$yyyymmdd}.txt");

            if (! $response->successful()) {
                continue;
            }

            foreach (explode("\n", trim($response->body())) as $line) {
                $parts = explode('|', trim($line));

                // Header, footer ("...Trade Date" record count) and blanks.
                if (count($parts) < 5 || ! is_numeric($parts[2])) {
                    continue;
                }

                $symbol = strtoupper($parts[1]);

                $out[$symbol] = [
                    'short_volume' => ($out[$symbol]['short_volume'] ?? 0.0) + (float) $parts[2],
                    'total_volume' => ($out[$symbol]['total_volume'] ?? 0.0) + (float) $parts[4],
                ];
            }
        }

        return $out;
    }
}
