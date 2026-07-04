<?php

namespace App\Services\Features;

use App\Models\SecFiling;
use Illuminate\Support\Facades\DB;

/**
 * Point-in-time feature store for the phase-A signal-quality features.
 * Preloads everything needed for a set of tickers over a date window, then
 * answers as-of queries with zero look-ahead:
 *
 *  - short_ratio: FINRA Reg SHO daily short volume / total volume, latest
 *    session at/before the signal day (max 6 days stale).
 *  - active_shelf: an S-3/F-3 shelf registration filed within 3 years —
 *    dilution capacity is sitting on the shelf.
 *  - atm_filed_90d: a 424B3/B4/B5 prospectus takedown within 90 days —
 *    shares are actively being sold into strength.
 *  - share_growth_12m: realized dilution from EDGAR XBRL cover-page share
 *    counts (latest observation vs the latest one >= 12 months prior).
 *  - market_ret_5d: small-cap regime via the benchmark ETF's 5-session return.
 *  - site_mention_z: site-wide daily mention count z-scored against the
 *    trailing 30 days — "is the whole casino running hot?"
 *  - vix: CBOE VIX close as-of the signal day — pumps die when volatility
 *    spikes and dip-buyers vanish.
 *  - btc_ret_5d: Bitcoin's 5-session return — the cleanest daily proxy for
 *    retail speculative risk appetite, which penny-stock flows chase.
 *  - mention_streak: consecutive days of strictly rising mentions ending at
 *    the signal day — separates "building momentum" from one-shot spikes.
 *
 * The same instance serves the Backtester (bulk, historical) and the
 * SignalEngine (today), so definitions cannot drift between research and live.
 */
class MarketIntelligence
{
    /** @var array<int, array<string, float>> [tickerId => [date => short_ratio]] */
    protected array $shortRatios = [];

    /** @var array<int, array<int, string>> [tickerId => sorted filed_at list] */
    protected array $shelfFilings = [];

    /** @var array<int, array<int, string>> [tickerId => sorted filed_at list] */
    protected array $takedownFilings = [];

    /** @var array<int, array<string, int>> [tickerId => [as_of => shares], sorted by as_of] */
    protected array $shareCounts = [];

    /** @var array<int, string> sorted benchmark bar dates */
    protected array $benchmarkDates = [];

    /** @var array<int, float> closes aligned with benchmarkDates */
    protected array $benchmarkCloses = [];

    /** @var array<string, int> [date => site-wide mention count] */
    protected array $siteMentions = [];

    /** @var array<string, float> [date => VIX close] */
    protected array $vixCloses = [];

    /** @var array<int, string> sorted BTC bar dates */
    protected array $btcDates = [];

    /** @var array<int, float> closes aligned with btcDates */
    protected array $btcCloses = [];

    /** @var array<int, array<string, int>> [tickerId => [date => mention count]] */
    protected array $tickerMentions = [];

    public const FEATURE_KEYS = [
        'short_ratio', 'atm_filed_90d', 'active_shelf', 'share_growth_12m', 'market_ret_5d', 'site_mention_z',
        'vix', 'btc_ret_5d', 'mention_streak',
    ];

    /**
     * @param  array<int, int>  $tickerIds
     */
    public static function load(array $tickerIds, string $from, string $to): self
    {
        $self = new self;

        if ($tickerIds !== []) {
            $self->loadShortVolumes($tickerIds, $from, $to);
            $self->loadFilings($tickerIds);
            $self->loadShareCounts($tickerIds);
            $self->loadTickerMentions($tickerIds, $from, $to);
        }

        $self->loadBenchmark($from, $to);
        $self->loadMacro($from, $to);
        $self->loadSiteMentions($from, $to);

        return $self;
    }

    /**
     * @return array{short_ratio: ?float, atm_filed_90d: bool, active_shelf: bool, share_growth_12m: ?float, market_ret_5d: ?float, site_mention_z: ?float, vix: ?float, btc_ret_5d: ?float, mention_streak: int}
     */
    public function features(int $tickerId, string $day): array
    {
        return [
            'short_ratio' => $this->shortRatio($tickerId, $day),
            'atm_filed_90d' => $this->hasFilingSince($this->takedownFilings[$tickerId] ?? [], $day, 90),
            'active_shelf' => $this->hasFilingSince($this->shelfFilings[$tickerId] ?? [], $day, 1095),
            'share_growth_12m' => $this->shareGrowth12m($tickerId, $day),
            'market_ret_5d' => $this->marketReturn5d($day),
            'site_mention_z' => $this->siteMentionZ($day),
            'vix' => $this->vixLevel($day),
            'btc_ret_5d' => $this->btcReturn5d($day),
            'mention_streak' => $this->mentionStreak($tickerId, $day),
        ];
    }

    /** VIX close on the last session at/before $day (max 6 days stale). */
    protected function vixLevel(string $day): ?float
    {
        for ($ts = strtotime($day.' UTC'), $i = 0; $i < 7; $i++) {
            $candidate = gmdate('Y-m-d', $ts - $i * 86400);

            if (isset($this->vixCloses[$candidate])) {
                return $this->vixCloses[$candidate];
            }
        }

        return null;
    }

    protected function btcReturn5d(string $day): ?float
    {
        $n = count($this->btcDates);

        if ($n < 6) {
            return null;
        }

        $idx = -1;

        for ($lo = 0, $hi = $n - 1; $lo <= $hi;) {
            $mid = intdiv($lo + $hi, 2);

            if ($this->btcDates[$mid] <= $day) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($idx < 5 || $this->btcCloses[$idx - 5] <= 0) {
            return null;
        }

        return round($this->btcCloses[$idx] / $this->btcCloses[$idx - 5] - 1, 4);
    }

    /**
     * Consecutive calendar days of strictly rising mentions ending at $day
     * (capped at 7). 0 = today's buzz did not exceed yesterday's — the spike
     * is not building.
     */
    protected function mentionStreak(int $tickerId, string $day): int
    {
        $counts = $this->tickerMentions[$tickerId] ?? [];
        $ts = strtotime($day.' UTC');
        $streak = 0;

        for ($i = 0; $i < 7; $i++) {
            $current = $counts[gmdate('Y-m-d', $ts - $i * 86400)] ?? 0;
            $previous = $counts[gmdate('Y-m-d', $ts - ($i + 1) * 86400)] ?? 0;

            if ($current > $previous) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    protected function shortRatio(int $tickerId, string $day): ?float
    {
        $ratios = $this->shortRatios[$tickerId] ?? [];

        for ($ts = strtotime($day.' UTC'), $i = 0; $i < 7; $i++) {
            $candidate = gmdate('Y-m-d', $ts - $i * 86400);

            if (isset($ratios[$candidate])) {
                return $ratios[$candidate];
            }
        }

        return null;
    }

    /** @param array<int, string> $filedDates sorted ascending */
    protected function hasFilingSince(array $filedDates, string $day, int $windowDays): bool
    {
        $cutoff = gmdate('Y-m-d', strtotime($day.' UTC') - $windowDays * 86400);

        // Filings are sorted; the newest one at/before $day decides.
        for ($i = count($filedDates) - 1; $i >= 0; $i--) {
            if ($filedDates[$i] <= $day) {
                return $filedDates[$i] >= $cutoff;
            }
        }

        return false;
    }

    protected function shareGrowth12m(int $tickerId, string $day): ?float
    {
        $counts = $this->shareCounts[$tickerId] ?? [];

        if (count($counts) < 2) {
            return null;
        }

        $yearAgo = gmdate('Y-m-d', strtotime($day.' UTC') - 365 * 86400);

        $current = null;
        $prior = null;

        foreach ($counts as $asOf => $shares) {
            if ($asOf <= $day) {
                $current = $shares;
            }

            if ($asOf <= $yearAgo) {
                $prior = $shares;
            }
        }

        // Stale current observations (> ~15 months old) say nothing about today.
        if ($current === null || $prior === null || $prior <= 0) {
            return null;
        }

        return round($current / $prior - 1, 4);
    }

    protected function marketReturn5d(string $day): ?float
    {
        $n = count($this->benchmarkDates);

        if ($n < 6) {
            return null;
        }

        // Last benchmark bar at/before $day (binary search).
        $lo = 0;
        $hi = $n - 1;
        $idx = -1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($this->benchmarkDates[$mid] <= $day) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($idx < 5 || $this->benchmarkCloses[$idx - 5] <= 0) {
            return null;
        }

        return round($this->benchmarkCloses[$idx] / $this->benchmarkCloses[$idx - 5] - 1, 4);
    }

    protected function siteMentionZ(string $day): ?float
    {
        $ts = strtotime($day.' UTC');
        $trailing = [];

        for ($i = 1; $i <= 30; $i++) {
            $trailing[] = (float) ($this->siteMentions[gmdate('Y-m-d', $ts - $i * 86400)] ?? 0);
        }

        $nonZero = count(array_filter($trailing, fn (float $v): bool => $v > 0));

        if ($nonZero < 10) {
            return null; // not enough archive coverage to form a baseline
        }

        $mean = array_sum($trailing) / count($trailing);
        $variance = 0.0;

        foreach ($trailing as $v) {
            $variance += ($v - $mean) ** 2;
        }

        $sd = sqrt($variance / (count($trailing) - 1));

        if ($sd <= 0) {
            return null;
        }

        return round((((float) ($this->siteMentions[$day] ?? 0)) - $mean) / $sd, 2);
    }

    /** @param array<int, int> $tickerIds */
    protected function loadShortVolumes(array $tickerIds, string $from, string $to): void
    {
        DB::table('short_volumes')
            ->whereIn('ticker_id', $tickerIds)
            ->where('day', '>=', gmdate('Y-m-d', strtotime($from.' UTC') - 10 * 86400))
            ->where('day', '<=', $to)
            ->select('ticker_id', 'day', 'short_ratio')
            ->orderBy('day')
            ->each(function ($row): void {
                $this->shortRatios[(int) $row->ticker_id][substr((string) $row->day, 0, 10)] = (float) $row->short_ratio;
            });
    }

    /** @param array<int, int> $tickerIds */
    protected function loadFilings(array $tickerIds): void
    {
        DB::table('sec_filings')
            ->whereIn('ticker_id', $tickerIds)
            ->whereIn('form', [...SecFiling::SHELF_FORMS, ...SecFiling::TAKEDOWN_FORMS])
            ->select('ticker_id', 'form', 'filed_at')
            ->orderBy('filed_at')
            ->each(function ($row): void {
                $date = substr((string) $row->filed_at, 0, 10);

                if (in_array($row->form, SecFiling::SHELF_FORMS, true)) {
                    $this->shelfFilings[(int) $row->ticker_id][] = $date;
                } else {
                    $this->takedownFilings[(int) $row->ticker_id][] = $date;
                }
            });
    }

    /** @param array<int, int> $tickerIds */
    protected function loadShareCounts(array $tickerIds): void
    {
        DB::table('ticker_share_counts')
            ->whereIn('ticker_id', $tickerIds)
            ->select('ticker_id', 'as_of', 'shares')
            ->orderBy('as_of')
            ->each(function ($row): void {
                $this->shareCounts[(int) $row->ticker_id][substr((string) $row->as_of, 0, 10)] = (int) $row->shares;
            });
    }

    protected function loadBenchmark(string $from, string $to): void
    {
        $benchmarkId = DB::table('tickers')
            ->where('symbol', config('pennyhunt.benchmark_symbol', 'IWM'))
            ->value('id');

        if ($benchmarkId === null) {
            return;
        }

        DB::table('market_bars')
            ->where('ticker_id', $benchmarkId)
            ->where('interval', '1d')
            ->where('bucket_start', '>=', gmdate('Y-m-d', strtotime($from.' UTC') - 20 * 86400))
            ->where('bucket_start', '<=', $to.' 23:59:59')
            ->orderBy('bucket_start')
            ->select('bucket_start', 'close')
            ->each(function ($bar): void {
                $this->benchmarkDates[] = substr((string) $bar->bucket_start, 0, 10);
                $this->benchmarkCloses[] = (float) $bar->close;
            });
    }

    protected function loadMacro(string $from, string $to): void
    {
        $symbols = config('pennyhunt.macro_symbols', []);

        $ids = DB::table('tickers')
            ->whereIn('symbol', array_values($symbols))
            ->pluck('id', 'symbol');

        $fromPadded = gmdate('Y-m-d', strtotime($from.' UTC') - 20 * 86400);

        if (isset($symbols['vix'], $ids[$symbols['vix']])) {
            DB::table('market_bars')
                ->where('ticker_id', $ids[$symbols['vix']])
                ->where('interval', '1d')
                ->where('bucket_start', '>=', $fromPadded)
                ->where('bucket_start', '<=', $to.' 23:59:59')
                ->orderBy('bucket_start')
                ->select('bucket_start', 'close')
                ->each(function ($bar): void {
                    $this->vixCloses[substr((string) $bar->bucket_start, 0, 10)] = (float) $bar->close;
                });
        }

        if (isset($symbols['btc'], $ids[$symbols['btc']])) {
            DB::table('market_bars')
                ->where('ticker_id', $ids[$symbols['btc']])
                ->where('interval', '1d')
                ->where('bucket_start', '>=', $fromPadded)
                ->where('bucket_start', '<=', $to.' 23:59:59')
                ->orderBy('bucket_start')
                ->select('bucket_start', 'close')
                ->each(function ($bar): void {
                    $this->btcDates[] = substr((string) $bar->bucket_start, 0, 10);
                    $this->btcCloses[] = (float) $bar->close;
                });
        }
    }

    /** @param array<int, int> $tickerIds */
    protected function loadTickerMentions(array $tickerIds, string $from, string $to): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT m.ticker_id, date(m.posted_at) AS day, COUNT(*) AS mentions
            FROM post_ticker_mentions m
            WHERE m.posted_at >= ? AND m.posted_at < ?
            GROUP BY m.ticker_id, day
        SQL, [gmdate('Y-m-d', strtotime($from.' UTC') - 10 * 86400), gmdate('Y-m-d', strtotime($to.' UTC') + 86400)]);

        $wanted = array_flip($tickerIds);

        foreach ($rows as $row) {
            if (isset($wanted[(int) $row->ticker_id])) {
                $this->tickerMentions[(int) $row->ticker_id][(string) $row->day] = (int) $row->mentions;
            }
        }
    }

    protected function loadSiteMentions(string $from, string $to): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT date(posted_at) AS day, COUNT(*) AS mentions
            FROM post_ticker_mentions
            WHERE posted_at >= ? AND posted_at < ?
            GROUP BY day
        SQL, [gmdate('Y-m-d', strtotime($from.' UTC') - 35 * 86400), gmdate('Y-m-d', strtotime($to.' UTC') + 86400)]);

        foreach ($rows as $row) {
            $this->siteMentions[(string) $row->day] = (int) $row->mentions;
        }
    }
}
