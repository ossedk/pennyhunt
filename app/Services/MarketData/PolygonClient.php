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
