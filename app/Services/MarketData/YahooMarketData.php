<?php

namespace App\Services\MarketData;

use App\Models\MarketBar;
use App\Models\Ticker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Keyless daily OHLC bars from Yahoo's chart API. Daily granularity is
 * sufficient for the validation-phase backtests; a licensed feed replaces
 * this only if the strategy survives the Phase 4 decision gate.
 */
class YahooMarketData
{
    /**
     * @return array<int, array{date: string, open: float, high: float, low: float, close: float, volume: int}>
     */
    public function dailyBars(string $symbol, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $response = Http::retry(2, 3000, throw: false)
            ->timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'])
            ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                'period1' => $from->startOfDay()->timestamp,
                'period2' => $to->endOfDay()->timestamp,
                'interval' => '1d',
                'events' => 'div,splits',
            ]);

        $result = $response->json('chart.result.0');

        if (! $response->successful() || $result === null) {
            return [];
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        $bars = [];

        foreach ($timestamps as $i => $ts) {
            // Null rows appear on halted days; skip them.
            if (! isset($quote['close'][$i], $quote['open'][$i])) {
                continue;
            }

            $bars[] = [
                'date' => CarbonImmutable::createFromTimestampUTC($ts)->toDateString(),
                'open' => (float) $quote['open'][$i],
                'high' => (float) ($quote['high'][$i] ?? $quote['close'][$i]),
                'low' => (float) ($quote['low'][$i] ?? $quote['close'][$i]),
                'close' => (float) $quote['close'][$i],
                'volume' => (int) ($quote['volume'][$i] ?? 0),
            ];
        }

        return $this->applySplits($bars, $result['events']['splits'] ?? []);
    }

    /**
     * Latest (15-min-delayed for OTC) market price from the chart endpoint's
     * meta block. Indicative display only — never used for authoritative
     * trade exits, which come from completed daily bars.
     *
     * @return array{price: float, at: CarbonImmutable}|null
     */
    public function latestQuote(string $symbol): ?array
    {
        $response = Http::retry(1, 2000, throw: false)
            ->timeout(15)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'])
            ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                'interval' => '1d',
                'range' => '1d',
            ]);

        $meta = $response->json('chart.result.0.meta');

        if (! $response->successful() || ! isset($meta['regularMarketPrice'])) {
            return null;
        }

        return [
            'price' => (float) $meta['regularMarketPrice'],
            'at' => CarbonImmutable::createFromTimestampUTC((int) ($meta['regularMarketTime'] ?? time())),
        ];
    }

    /**
     * Yahoo's quote series is NOT retroactively split-adjusted for many
     * small/OTC symbols; an unadjusted 1:200 reverse split fabricates a
     * "+11,000%" move. Rescale pre-split bars to the post-split basis.
     *
     * @param  array<int, array{date: string, open: float, high: float, low: float, close: float, volume: int}>  $bars
     * @param  array<int|string, array{date: int, numerator: float|int, denominator: float|int}>  $splits
     * @return array<int, array{date: string, open: float, high: float, low: float, close: float, volume: int}>
     */
    protected function applySplits(array $bars, array $splits): array
    {
        foreach ($splits as $split) {
            $numerator = (float) ($split['numerator'] ?? 0);
            $denominator = (float) ($split['denominator'] ?? 0);

            if ($numerator <= 0 || $denominator <= 0) {
                continue;
            }

            $splitDate = CarbonImmutable::createFromTimestampUTC($split['date'])->toDateString();

            // Yahoo convention: a 4:1 forward split has numerator=4,
            // denominator=1 (post-split prices are 1/4 of pre-split); a 1:200
            // reverse split has numerator=1, denominator=200. Restate
            // PRE-split bars on the POST-split basis:
            foreach ($bars as $i => $bar) {
                if ($bar['date'] >= $splitDate) {
                    continue;
                }

                $bars[$i]['open'] = $bar['open'] * $denominator / $numerator;
                $bars[$i]['high'] = $bar['high'] * $denominator / $numerator;
                $bars[$i]['low'] = $bar['low'] * $denominator / $numerator;
                $bars[$i]['close'] = $bar['close'] * $denominator / $numerator;
                $bars[$i]['volume'] = (int) round($bar['volume'] * $numerator / $denominator);
            }
        }

        return $bars;
    }

    /**
     * Fetch and upsert daily bars for a ticker. Returns the number of bars stored.
     */
    public function syncDailyBars(Ticker $ticker, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $bars = $this->dailyBars($ticker->symbol, $from, $to);

        // Reverse-split zombies (ADTX, AREB…) have split-adjusted historical
        // prices beyond 10^10, which overflow numeric(16,6) and are
        // untradeable nonsense anyway. Drop those rows, keep the valid
        // recent history instead of losing the whole ticker.
        $bars = array_values(array_filter(
            $bars,
            fn (array $bar): bool => max($bar['open'], $bar['high'], $bar['low'], $bar['close']) < 1e9,
        ));

        if ($bars === []) {
            return 0;
        }

        $rows = array_map(fn (array $bar) => [
            'ticker_id' => $ticker->id,
            'interval' => '1d',
            'bucket_start' => $bar['date'].' 00:00:00+00',
            'open' => $bar['open'],
            'high' => $bar['high'],
            'low' => $bar['low'],
            'close' => $bar['close'],
            'volume' => $bar['volume'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $bars);

        foreach (array_chunk($rows, 500) as $chunk) {
            MarketBar::upsert($chunk, ['ticker_id', 'interval', 'bucket_start'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
        }

        return count($rows);
    }
}
