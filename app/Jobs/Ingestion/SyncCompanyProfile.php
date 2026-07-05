<?php

namespace App\Jobs\Ingestion;

use App\Models\Ticker;
use App\Models\TickerFinancial;
use App\Models\TickerProfile;
use App\Services\MarketData\PolygonClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pulls the Polygon company profile + standardized financials for one ticker.
 * Dispatched lazily from the ticker page when the profile is missing or
 * stale (> 7 days), so we only spend requests on names people actually view.
 */
class SyncCompanyProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $tickerId)
    {
        $this->onQueue('ingestion');
    }

    public function uniqueId(): string
    {
        return (string) $this->tickerId;
    }

    public function handle(PolygonClient $polygon): void
    {
        $ticker = Ticker::find($this->tickerId);

        if ($ticker === null || ! $polygon->enabled()) {
            return;
        }

        $details = $polygon->tickerDetails($ticker->symbol);

        if ($details !== null) {
            TickerProfile::updateOrCreate(
                ['ticker_id' => $ticker->id],
                [
                    'description' => $details['description'] ?? null,
                    'sic_description' => $details['sic_description'] ?? null,
                    'homepage_url' => $details['homepage_url'] ?? null,
                    'primary_exchange' => $details['primary_exchange'] ?? null,
                    'locale' => $details['locale'] ?? null,
                    'city' => data_get($details, 'address.city'),
                    'state' => data_get($details, 'address.state'),
                    'total_employees' => $details['total_employees'] ?? null,
                    'list_date' => $details['list_date'] ?? null,
                    'market_cap' => isset($details['market_cap']) ? (int) $details['market_cap'] : null,
                    'shares_outstanding' => isset($details['share_class_shares_outstanding'])
                        ? (int) $details['share_class_shares_outstanding'] : null,
                    'weighted_shares_outstanding' => isset($details['weighted_shares_outstanding'])
                        ? (int) $details['weighted_shares_outstanding'] : null,
                    'synced_at' => now(),
                ],
            );

            // Polygon's market cap is fresher than our universe sync; SIC
            // code feeds the sector-heat features.
            $ticker->update(array_filter([
                'market_cap' => isset($details['market_cap']) ? (int) $details['market_cap'] : null,
                'sic_code' => isset($details['sic_code']) ? substr((string) $details['sic_code'], 0, 4) : null,
            ]));
        } else {
            // Remember the attempt so the page doesn't re-dispatch every view.
            TickerProfile::updateOrCreate(['ticker_id' => $ticker->id], ['synced_at' => now()]);
        }

        foreach ($polygon->financials($ticker->symbol, 'quarterly', 8) as $period) {
            $this->storePeriod($ticker, $period);
        }

        foreach ($polygon->financials($ticker->symbol, 'annual', 3) as $period) {
            $this->storePeriod($ticker, $period);
        }
    }

    /** @param array<string, mixed> $period */
    protected function storePeriod(Ticker $ticker, array $period): void
    {
        if (empty($period['end_date'])) {
            return;
        }

        $value = fn (string $path): ?float => data_get($period, "financials.{$path}.value") !== null
            ? (float) data_get($period, "financials.{$path}.value")
            : null;

        TickerFinancial::updateOrCreate(
            [
                'ticker_id' => $ticker->id,
                'timeframe' => $period['timeframe'] ?? 'quarterly',
                'end_date' => $period['end_date'],
            ],
            [
                'fiscal_period' => $period['fiscal_period'] ?? null,
                'fiscal_year' => $period['fiscal_year'] ?? null,
                'filing_date' => $period['filing_date'] ?? null,
                'revenue' => $value('income_statement.revenues'),
                'operating_expenses' => $value('income_statement.operating_expenses'),
                'net_income' => $value('income_statement.net_income_loss'),
                'eps_basic' => $value('income_statement.basic_earnings_per_share'),
                'operating_cash_flow' => $value('cash_flow_statement.net_cash_flow_from_operating_activities'),
                'cash' => $value('balance_sheet.cash'),
                'current_assets' => $value('balance_sheet.current_assets'),
                'current_liabilities' => $value('balance_sheet.current_liabilities'),
                'total_assets' => $value('balance_sheet.assets'),
                'total_liabilities' => $value('balance_sheet.liabilities'),
                'equity' => $value('balance_sheet.equity'),
            ],
        );
    }
}
