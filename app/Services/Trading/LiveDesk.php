<?php

namespace App\Services\Trading;

use App\Models\MarketBar;
use App\Models\Signal;
use App\Models\SignalTrade;
use App\Services\Features\Day0Features;
use App\Services\MarketData\ExtendedQuote;
use App\Services\MarketData\MarketClock;
use App\Services\MarketData\PolygonClient;
use App\Support\AnalyticsGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The live desk: a deterministic, rule-based read of the CURRENT session
 * for one signal — should you enter, hold, or get out right now? Every
 * rule is one the research stack validated; no vibes, each verdict comes
 * with its reasons. Refreses at most once a minute per signal.
 *
 * Rules (in priority order):
 *  ENTER-side (no open position):
 *   - chasing veto: price > fire-close × 1.15 → STAND ASIDE (entries after
 *     a >15% run were net losers in every backtest slice)
 *   - crowd gone: mention pace < 25% of fire-day pace → STAND ASIDE
 *   - tape fading: below session VWAP / gap faded → WAIT
 *   - tape confirming: above VWAP on elevated volume → ENTER WINDOW
 *  EXIT-side (open position):
 *   - stop breached (legacy book's level) → EXIT
 *   - mention collapse (validated exit: < 25% of fire-day) → EXIT
 *   - time discipline: at/after the book's time-exit session → EXIT TODAY
 *   - below VWAP intraday → CAUTION
 *   - otherwise → HOLD
 */
class LiveDesk
{
    public function __construct(
        protected MarketClock $clock,
        protected ExtendedQuote $quotes,
        protected PolygonClient $polygon,
    ) {}

    /** @return array<string, mixed> */
    public function assess(Signal $signal): array
    {
        $status = $this->clock->status()['status'];

        if ($status === MarketClock::STATUS_CLOSED) {
            return [
                'market' => $status,
                'verdict' => 'market_closed',
                'headline' => 'Market closed — next assessment at the pre-market open.',
                'reasons' => [],
                'metrics' => null,
                'as_of' => now()->toIso8601String(),
            ];
        }

        return Cache::remember("livedesk:{$signal->id}", 60, function () use ($signal, $status): array {
            $signal->loadMissing('ticker');

            $quote = $this->quotes->get($signal->ticker);
            $day0 = $this->liveDay0($signal);
            $crowd = $this->crowdPace($signal);
            $trade = SignalTrade::query()
                ->where('signal_id', $signal->id)
                ->whereIn('status', ['pending_entry', 'open'])
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->first();

            $fireClose = (float) (data_get($signal->breakdown, 'market_gate.close') ?? 0);
            $price = $quote['price'] ?? null;

            [$verdict, $headline, $reasons] = $trade !== null && $trade->status === 'open'
                ? $this->exitSide($trade, $price, $day0, $crowd)
                : $this->enterSide($signal, $fireClose, $price, $day0, $crowd);

            return [
                'market' => $status,
                'verdict' => $verdict,
                'headline' => $headline,
                'reasons' => $reasons,
                'metrics' => [
                    'price' => $price,
                    'change_pct' => $quote['change_pct'] ?? null,
                    'session' => $quote['session'] ?? $status,
                    'vs_fire_close' => $price !== null && $fireClose > 0 ? round($price / $fireClose - 1, 4) : null,
                    'vwap_dist' => $day0['vwap_dist_30m'],
                    'or_return' => $day0['or_return_30m'],
                    'or_vol_share' => $day0['or_vol_share'],
                    'gap_faded' => $day0['gap_faded'],
                    'mentions_last_hour' => $crowd['last_hour'],
                    'fire_day_hourly_pace' => $crowd['fire_pace'],
                    'crowd_ratio' => $crowd['ratio'],
                    'position' => $trade?->only(['book', 'status', 'entry_price', 'stop_price', 'entry_date', 'time_exit_date']),
                ],
                'as_of' => now()->toIso8601String(),
            ];
        });
    }

    /** @return array{0: string, 1: string, 2: list<string>} */
    protected function enterSide(Signal $signal, float $fireClose, ?float $price, array $day0, array $crowd): array
    {
        $reasons = [];

        if ($price !== null && $fireClose > 0 && $price > $fireClose * 1.15) {
            return ['stand_aside', 'Stand aside — you would be chasing.', [
                sprintf('Price is %+.0f%% above the fire-day close; entries after a >15%% run were net losers in every backtest slice.', ($price / $fireClose - 1) * 100),
            ]];
        }

        if ($crowd['ratio'] !== null && $crowd['ratio'] < 0.25) {
            return ['stand_aside', 'Stand aside — the crowd has left.', [
                sprintf('Mentions running at %.0f%% of fire-day pace; collapsed attention was the validated exit signal.', $crowd['ratio'] * 100),
            ]];
        }

        if ($day0['gap_faded'] === true) {
            $reasons[] = 'The opening gap faded through the prior close — distribution, not accumulation.';
        }

        if ($day0['vwap_dist_30m'] !== null && $day0['vwap_dist_30m'] < 0) {
            $reasons[] = sprintf('Trading %.1f%% below session VWAP — the tape is not confirming yet.', abs($day0['vwap_dist_30m']) * 100);
        }

        if ($reasons !== []) {
            return ['wait', 'Wait — the tape is fading, not confirming.', $reasons];
        }

        if ($day0['vwap_dist_30m'] !== null && $day0['vwap_dist_30m'] > 0) {
            $reasons[] = sprintf('Holding %+.1f%% above session VWAP.', $day0['vwap_dist_30m'] * 100);

            if ($day0['or_vol_share'] !== null && $day0['or_vol_share'] > 0.3) {
                $reasons[] = sprintf('Opening volume already %.0f%% of a normal full day.', $day0['or_vol_share'] * 100);
            }

            if ($crowd['ratio'] !== null && $crowd['ratio'] >= 0.5) {
                $reasons[] = sprintf('Crowd still engaged (%.0f%% of fire-day pace).', $crowd['ratio'] * 100);
            }

            return ['enter_window', 'Entry window open — tape confirming.', $reasons];
        }

        return ['neutral', 'No strong read yet — tape and crowd are indeterminate.', [
            'Session VWAP/volume data still thin; re-checks every minute.',
        ]];
    }

    /** @return array{0: string, 1: string, 2: list<string>} */
    protected function exitSide(SignalTrade $trade, ?float $price, array $day0, array $crowd): array
    {
        if ($trade->stop_price !== null && $price !== null && $price <= (float) $trade->stop_price) {
            return ['exit', 'Exit — stop level breached on the live tape.', [
                sprintf('Live %.4f ≤ stop %.4f (authoritative close happens on the daily bar, but the level is gone).', $price, $trade->stop_price),
            ]];
        }

        if ($crowd['ratio'] !== null && $crowd['ratio'] < 0.25) {
            return ['exit', 'Exit — mention collapse.', [
                sprintf('Mentions at %.0f%% of fire-day pace; the crowd leaving is the validated exit for this book.', $crowd['ratio'] * 100),
            ]];
        }

        if ($trade->time_exit_date !== null && $trade->time_exit_date->lte(now())) {
            return ['exit_today', 'Time exit due — close at today\'s close.', [
                sprintf('The %s book\'s discipline exits at the close of %s.', $trade->book, $trade->time_exit_date->toDateString()),
            ]];
        }

        $reasons = [];

        if ($day0['vwap_dist_30m'] !== null && $day0['vwap_dist_30m'] < -0.03) {
            $reasons[] = sprintf('Trading %.1f%% below session VWAP — momentum leaking.', abs($day0['vwap_dist_30m']) * 100);

            return ['caution', 'Caution — tape weakening under the position.', $reasons];
        }

        if ($price !== null && $trade->entry_price !== null && $trade->entry_price > 0) {
            $reasons[] = sprintf('Unrealized %+.1f%% vs entry.', ($price / (float) $trade->entry_price - 1) * 100);
        }

        if ($crowd['ratio'] !== null) {
            $reasons[] = sprintf('Crowd at %.0f%% of fire-day pace.', $crowd['ratio'] * 100);
        }

        return ['hold', 'Hold — discipline intact.', $reasons];
    }

    /** @return array{or_return_30m: ?float, vwap_dist_30m: ?float, or_vol_share: ?float, gap_faded: ?bool} */
    protected function liveDay0(Signal $signal): array
    {
        $empty = array_fill_keys(Day0Features::FEATURE_KEYS, null);

        if (! $this->polygon->enabled()) {
            return $empty;
        }

        $today = now()->setTimezone('America/New_York')->toDateString();

        $daily = MarketBar::query()
            ->where('ticker_id', $signal->ticker_id)
            ->where('interval', '1d')
            ->where('bucket_start', '<', $today)
            ->orderByDesc('bucket_start')
            ->limit(20)
            ->get(['close', 'volume']);

        return Day0Features::compute(
            $this->polygon->minuteBars($signal->ticker->symbol, $today),
            $daily->first() !== null ? (float) $daily->first()->close : null,
            $daily->count() >= 5 ? (float) $daily->avg('volume') : null,
        );
    }

    /**
     * Crowd pace: mentions in the last 60 minutes vs the fire day's hourly
     * average — the live analogue of the mention-collapse exit.
     *
     * @return array{last_hour: int, fire_pace: ?float, ratio: ?float}
     */
    protected function crowdPace(Signal $signal): array
    {
        $gate = AnalyticsGate::mentionJoin('m');

        $lastHour = (int) (DB::selectOne(
            "SELECT COUNT(*) AS n FROM post_ticker_mentions m {$gate} WHERE m.ticker_id = ? AND m.posted_at >= ?",
            [$signal->ticker_id, now()->subHour()],
        )->n ?? 0);

        $fireDay = $signal->fired_at->toDateString();

        $fireCount = (int) (DB::selectOne(
            "SELECT COUNT(*) AS n FROM post_ticker_mentions m {$gate} WHERE m.ticker_id = ? AND date(m.posted_at) = ?",
            [$signal->ticker_id, $fireDay],
        )->n ?? 0);

        $firePace = $fireCount > 0 ? $fireCount / 24 : null;

        return [
            'last_hour' => $lastHour,
            'fire_pace' => $firePace !== null ? round($firePace, 2) : null,
            'ratio' => $firePace !== null && $firePace > 0 ? round($lastHour / $firePace, 2) : null,
        ];
    }
}
