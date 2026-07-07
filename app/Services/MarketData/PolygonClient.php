<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Polygon.io Stocks Starter client. Two surfaces we pay for:
 * company reference data (ticker details) and standardized SEC XBRL
 * financials — both power the company/accounting panels on ticker pages.
 */
class PolygonClient
{
    protected const BASE = 'https://api.polygon.io';

    public function enabled(): bool
    {
        return filled(config('pennyhunt.polygon.api_key'));
    }

    /** @return array<string, mixed>|null ticker details `results` object */
    public function tickerDetails(string $symbol): ?array
    {
        $response = $this->get('/v3/reference/tickers/'.rawurlencode(strtoupper($symbol)));

        return $response?->json('results');
    }

    /** @return array<int, array<string, mixed>> financials `results` list, newest first */
    public function financials(string $symbol, string $timeframe = 'quarterly', int $limit = 8): array
    {
        $response = $this->get('/vX/reference/financials', [
            'ticker' => strtoupper($symbol),
            'timeframe' => $timeframe,
            'order' => 'desc',
            'sort' => 'period_of_report_date',
            'limit' => $limit,
        ]);

        return $response?->json('results') ?? [];
    }

    /** @return array<string, mixed>|null raw /v1/marketstatus/now payload */
    public function marketStatus(): ?array
    {
        return $this->get('/v1/marketstatus/now')?->json();
    }

    /** @return array<int, array<string, mixed>> news `results` list, newest first */
    public function news(string $symbol, int $limit = 10): array
    {
        $response = $this->get('/v2/reference/news', [
            'ticker' => strtoupper($symbol),
            'order' => 'desc',
            'sort' => 'published_utc',
            'limit' => $limit,
        ]);

        return $response?->json('results') ?? [];
    }

    /**
     * Minute aggregates for one ticker-day (regular + extended session).
     *
     * @return array<int, array{t: int, o: float, h: float, l: float, c: float, v: float}>
     */
    public function minuteBars(string $symbol, string $date): array
    {
        return $this->rangeAggregates($symbol, 1, 'minute', $date, $date);
    }

    /**
     * Arbitrary aggregate bars over a date range (regular + extended
     * session) — powers the intraday charts (5-min / hourly).
     *
     * @return array<int, array{t: int, o: float, h: float, l: float, c: float, v: float}>
     */
    public function rangeAggregates(string $symbol, int $multiplier, string $timespan, string $from, string $to): array
    {
        $response = $this->get(sprintf(
            '/v2/aggs/ticker/%s/range/%d/%s/%s/%s',
            rawurlencode(strtoupper($symbol)),
            $multiplier,
            $timespan,
            $from,
            $to,
        ), ['adjusted' => 'true', 'sort' => 'asc', 'limit' => 50000]);

        return array_map(fn (array $bar): array => [
            't' => (int) $bar['t'],
            'o' => (float) $bar['o'],
            'h' => (float) $bar['h'],
            'l' => (float) $bar['l'],
            'c' => (float) $bar['c'],
            'v' => (float) $bar['v'],
        ], $response?->json('results') ?? []);
    }

    /**
     * Historical news window for backfills (max 1000 per call).
     *
     * @return array<int, array<string, mixed>>
     */
    public function newsBetween(string $symbol, string $from, string $to, int $limit = 1000): array
    {
        $response = $this->get('/v2/reference/news', [
            'ticker' => strtoupper($symbol),
            'published_utc.gte' => $from,
            'published_utc.lte' => $to,
            'order' => 'desc',
            'sort' => 'published_utc',
            'limit' => min($limit, 1000),
        ]);

        return $response?->json('results') ?? [];
    }

    /** @param array<string, mixed> $query */
    protected function get(string $path, array $query = []): ?Response
    {
        if (! $this->enabled()) {
            return null;
        }

        $response = Http::timeout(30)
            ->retry(2, 2000, throw: false)
            ->get(self::BASE.$path, [
                ...$query,
                'apiKey' => config('pennyhunt.polygon.api_key'),
            ]);

        // 404 = Polygon doesn't know the ticker (delisted/OTC gaps) — not an error.
        if ($response->status() === 404) {
            return null;
        }

        return $response->throw();
    }
}
