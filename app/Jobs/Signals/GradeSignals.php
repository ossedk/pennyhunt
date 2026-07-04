<?php

namespace App\Jobs\Signals;

use App\Models\MarketBar;
use App\Models\Signal;
use App\Services\MarketData\YahooMarketData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;

/**
 * Self-evaluation: annotate fired signals with realized forward returns
 * (t+1d / t+3d / t+5d) so the platform continuously reports its own
 * precision. Bars come from market_bars, refreshed via Yahoo (keyless);
 * signals whose 5-day window hasn't closed stay ungraded, not dropped.
 */
class GradeSignals implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('metrics');
    }

    public function handle(YahooMarketData $marketData): void
    {
        $signals = Signal::query()
            ->whereNull('graded_at')
            ->where('fired_at', '<=', now()->subDays(1))
            ->with('ticker')
            ->limit(100)
            ->get();

        foreach ($signals as $signal) {
            $firedDate = CarbonImmutable::parse($signal->fired_at)->toDateString();

            $marketData->syncDailyBars(
                $signal->ticker,
                CarbonImmutable::parse($firedDate)->subDays(3),
                CarbonImmutable::now(),
            );

            Sleep::for(300)->milliseconds();

            $closes = MarketBar::query()
                ->where('ticker_id', $signal->ticker_id)
                ->where('interval', '1d')
                ->where('bucket_start', '>=', $firedDate)
                ->orderBy('bucket_start')
                ->pluck('close')
                ->map(fn ($c) => (float) $c)
                ->values();

            $base = $closes->first();

            if ($base === null || $base <= 0.0) {
                continue;
            }

            $returnAt = fn (int $day): ?float => $closes->has($day)
                ? round(($closes[$day] - $base) / $base, 4)
                : null;

            $r5 = $returnAt(5);

            // Only stamp graded_at once the full 5-day window has resolved
            $signal->forceFill([
                'forward_return_1d' => $returnAt(1),
                'forward_return_3d' => $returnAt(3),
                'forward_return_5d' => $r5,
                'graded_at' => $r5 !== null ? now() : null,
            ])->save();
        }
    }
}
