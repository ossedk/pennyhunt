<?php

namespace App\Services\Features;

use App\Models\SecFiling;
use App\Support\AnalyticsGate;
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

    /** @var array<string, array{dates: array<int, string>, closes: array<int, float>}> extra macro series (spy, xbi) */
    protected array $macroSeries = [];

    /** @var array<int, array<string, int>> [tickerId => [date => mention count]] */
    protected array $tickerMentions = [];

    /** @var array<int, array<int, array{date: string, buy: float, sell: float, buys: int}>> [tickerId => filed_at-sorted insider aggregates] */
    protected array $insiderTrades = [];

    /** @var array<int, array<int, array{date: string, offering: bool}>> [tickerId => published-sorted catalyst articles] */
    protected array $newsCatalysts = [];

    public const FEATURE_KEYS = [
        'short_ratio', 'atm_filed_90d', 'active_shelf', 'share_growth_12m', 'market_ret_5d', 'site_mention_z',
        'vix', 'btc_ret_5d', 'mention_streak',
        'smallcap_rel_20d', 'xbi_ret_5d',
        'insider_buys_90d', 'insider_net_value_90d',
        'news_catalyst_7d', 'news_offering_7d',
        'si_days_to_cover', 'si_pct_change', 'ftd_log', 'borrow_fee', 'halted_5d',
    ];

    /** Bi-monthly SI publishes ~9 business days after settlement. */
    protected const SI_VISIBILITY_LAG_DAYS = 13;

    /** SEC FTD half-month files land 2-4 weeks after settlement. */
    protected const FTD_VISIBILITY_LAG_DAYS = 21;

    /** @var array<int, array<int, array{date: string, shares: int, dtc: ?float}>> [tickerId => settlement-sorted SI rows] */
    protected array $shortInterest = [];

    /** @var array<int, array<int, array{date: string, fails: int}>> [tickerId => settlement-sorted FTD rows] */
    protected array $ftds = [];

    /** @var array<int, array<string, float>> [tickerId => [day => fee]] */
    protected array $borrowFees = [];

    /** @var array<int, array<int, string>> [tickerId => sorted halt dates Y-m-d] */
    protected array $halts = [];

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
            $self->loadInsiderTrades($tickerIds, $from, $to);
            $self->loadNewsCatalysts($tickerIds, $from, $to);
            $self->loadSqueezeFuel($tickerIds, $from, $to);
        }

        $self->loadBenchmark($from, $to);
        $self->loadMacro($from, $to);
        $self->loadSiteMentions($from, $to);

        return $self;
    }

    /**
     * @return array<string, mixed> keyed by FEATURE_KEYS
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
            'smallcap_rel_20d' => $this->smallcapRelative20d($day),
            'xbi_ret_5d' => $this->seriesReturn('xbi', $day, 5),
            ...$this->insiderFeatures($tickerId, $day),
            ...$this->newsFeatures($tickerId, $day),
            ...$this->squeezeFeatures($tickerId, $day),
        ];
    }

    /**
     * Squeeze-fuel features as-of $day, honoring publication lags:
     *  - si_days_to_cover / si_pct_change: latest VISIBLE bi-monthly SI
     *    (settlement + 13d) and its change vs the prior period.
     *  - ftd_log: log10(1 + max daily fails) over the visible trailing 30
     *    settlement days (settlement + 21d lag).
     *  - borrow_fee: latest fee at/before day (max 5 days stale).
     *  - halted_5d: trade halts in the last 5 days (forward-only data).
     *
     * @return array{si_days_to_cover: ?float, si_pct_change: ?float, ftd_log: ?float, borrow_fee: ?float, halted_5d: int}
     */
    protected function squeezeFeatures(int $tickerId, string $day): array
    {
        $out = ['si_days_to_cover' => null, 'si_pct_change' => null, 'ftd_log' => null, 'borrow_fee' => null, 'halted_5d' => 0];

        // Short interest: rows sorted by settlement; visible = settled
        // at least SI_VISIBILITY_LAG_DAYS before $day.
        $siCutoff = gmdate('Y-m-d', strtotime($day.' UTC') - self::SI_VISIBILITY_LAG_DAYS * 86400);
        $visible = [];

        foreach ($this->shortInterest[$tickerId] ?? [] as $row) {
            if ($row['date'] <= $siCutoff) {
                $visible[] = $row;
            }
        }

        if ($visible !== []) {
            $latest = end($visible);
            $out['si_days_to_cover'] = $latest['dtc'];

            if (count($visible) >= 2) {
                $prior = $visible[count($visible) - 2];
                $out['si_pct_change'] = $prior['shares'] > 0
                    ? round($latest['shares'] / $prior['shares'] - 1, 4)
                    : null;
            }
        }

        // FTDs: max daily fails over the visible trailing 30 settlement days.
        $ftdCutoff = gmdate('Y-m-d', strtotime($day.' UTC') - self::FTD_VISIBILITY_LAG_DAYS * 86400);
        $ftdFloor = gmdate('Y-m-d', strtotime($ftdCutoff.' UTC') - 30 * 86400);
        $maxFails = 0;

        foreach ($this->ftds[$tickerId] ?? [] as $row) {
            if ($row['date'] <= $ftdCutoff && $row['date'] >= $ftdFloor) {
                $maxFails = max($maxFails, $row['fails']);
            }
        }

        if ($maxFails > 0) {
            $out['ftd_log'] = round(log10(1 + $maxFails), 4);
        }

        // Borrow fee: latest at/before day, max 5 days stale (forward-only).
        $fees = $this->borrowFees[$tickerId] ?? [];

        for ($ts = strtotime($day.' UTC'), $i = 0; $i < 6; $i++) {
            $candidate = gmdate('Y-m-d', $ts - $i * 86400);

            if (isset($fees[$candidate])) {
                $out['borrow_fee'] = $fees[$candidate];
                break;
            }
        }

        // Halts in the trailing 5 days (inclusive of $day).
        $haltFloor = gmdate('Y-m-d', strtotime($day.' UTC') - 5 * 86400);

        foreach ($this->halts[$tickerId] ?? [] as $haltDay) {
            if ($haltDay >= $haltFloor && $haltDay <= $day) {
                $out['halted_5d']++;
            }
        }

        return $out;
    }

    /** @param array<int, int> $tickerIds */
    protected function loadSqueezeFuel(array $tickerIds, string $from, string $to): void
    {
        // Padding covers visibility lags + prior-period comparisons.
        $siFloor = gmdate('Y-m-d', strtotime($from.' UTC') - 75 * 86400);

        DB::table('short_interest')
            ->whereIn('ticker_id', $tickerIds)
            ->where('settlement_date', '>=', $siFloor)
            ->where('settlement_date', '<=', $to)
            ->orderBy('settlement_date')
            ->select('ticker_id', 'settlement_date', 'shares_short', 'days_to_cover')
            ->each(function ($row): void {
                $this->shortInterest[(int) $row->ticker_id][] = [
                    'date' => substr((string) $row->settlement_date, 0, 10),
                    'shares' => (int) $row->shares_short,
                    'dtc' => $row->days_to_cover !== null ? (float) $row->days_to_cover : null,
                ];
            });

        $ftdFloor = gmdate('Y-m-d', strtotime($from.' UTC') - 60 * 86400);

        DB::table('ftd_reports')
            ->whereIn('ticker_id', $tickerIds)
            ->where('settlement_date', '>=', $ftdFloor)
            ->where('settlement_date', '<=', $to)
            ->orderBy('settlement_date')
            ->select('ticker_id', 'settlement_date', 'fails_quantity')
            ->each(function ($row): void {
                $this->ftds[(int) $row->ticker_id][] = [
                    'date' => substr((string) $row->settlement_date, 0, 10),
                    'fails' => (int) $row->fails_quantity,
                ];
            });

        DB::table('borrow_rates')
            ->whereIn('ticker_id', $tickerIds)
            ->where('day', '>=', gmdate('Y-m-d', strtotime($from.' UTC') - 10 * 86400))
            ->where('day', '<=', $to)
            ->whereNotNull('fee')
            ->orderBy('day')
            ->select('ticker_id', 'day', 'fee')
            ->each(function ($row): void {
                $this->borrowFees[(int) $row->ticker_id][substr((string) $row->day, 0, 10)] = (float) $row->fee;
            });

        DB::table('trade_halts')
            ->whereIn('ticker_id', $tickerIds)
            ->where('halted_at', '>=', gmdate('Y-m-d', strtotime($from.' UTC') - 10 * 86400))
            ->where('halted_at', '<=', $to.' 23:59:59')
            ->orderBy('halted_at')
            ->select('ticker_id', 'halted_at')
            ->each(function ($row): void {
                $this->halts[(int) $row->ticker_id][] = substr((string) $row->halted_at, 0, 10);
            });
    }

    /**
     * Small-cap risk appetite: IWM 20-session return minus SPY 20-session
     * return. Positive = small caps leading (buzz converts), negative =
     * flight to quality (pumps die on the vine).
     */
    protected function smallcapRelative20d(string $day): ?float
    {
        $iwm = $this->benchmarkReturn($day, 20);
        $spy = $this->seriesReturn('spy', $day, 20);

        return $iwm !== null && $spy !== null ? round($iwm - $spy, 4) : null;
    }

    /**
     * Insider flow as-of $day (point-in-time by FILED date — the market
     * can only see a Form 4 once it's filed):
     *  - insider_buys_90d: open-market purchase transactions in 90 days
     *  - insider_net_value_90d: signed log10 of net dollar flow
     *    (+5 = $100k net buying, -6 = $1M net selling)
     *
     * @return array{insider_buys_90d: int, insider_net_value_90d: ?float}
     */
    protected function insiderFeatures(int $tickerId, string $day): array
    {
        $rows = $this->insiderTrades[$tickerId] ?? [];

        if ($rows === []) {
            return ['insider_buys_90d' => 0, 'insider_net_value_90d' => null];
        }

        $cutoff = gmdate('Y-m-d', strtotime($day.' UTC') - 90 * 86400);
        $buys = 0;
        $net = 0.0;

        foreach ($rows as $row) {
            if ($row['date'] > $day) {
                break; // sorted ascending — nothing later is visible
            }

            if ($row['date'] >= $cutoff) {
                $buys += $row['buys'];
                $net += $row['buy'] - $row['sell'];
            }
        }

        return [
            'insider_buys_90d' => $buys,
            'insider_net_value_90d' => $net !== 0.0
                ? round(($net > 0 ? 1 : -1) * log10(1 + abs($net)), 4)
                : 0.0,
        ];
    }

    /**
     * News catalysts as-of $day (by published date):
     *  - news_catalyst_7d: any positive-catalyst article within 7 days
     *  - news_offering_7d: any offering/dilution article within 7 days
     *
     * @return array{news_catalyst_7d: bool, news_offering_7d: bool}
     */
    protected function newsFeatures(int $tickerId, string $day): array
    {
        $rows = $this->newsCatalysts[$tickerId] ?? [];
        $cutoff = gmdate('Y-m-d', strtotime($day.' UTC') - 7 * 86400);
        $catalyst = false;
        $offering = false;

        foreach ($rows as $row) {
            if ($row['date'] > $day) {
                break;
            }

            if ($row['date'] >= $cutoff) {
                if ($row['offering']) {
                    $offering = true;
                } else {
                    $catalyst = true;
                }
            }
        }

        return ['news_catalyst_7d' => $catalyst, 'news_offering_7d' => $offering];
    }

    /** N-session return of an extra macro series (spy, xbi) as-of $day. */
    protected function seriesReturn(string $key, string $day, int $sessions): ?float
    {
        $series = $this->macroSeries[$key] ?? null;

        if ($series === null || count($series['dates']) < $sessions + 1) {
            return null;
        }

        $idx = self::lastIndexAtOrBefore($series['dates'], $day);

        if ($idx < $sessions || $series['closes'][$idx - $sessions] <= 0) {
            return null;
        }

        return round($series['closes'][$idx] / $series['closes'][$idx - $sessions] - 1, 4);
    }

    /** N-session benchmark (IWM) return as-of $day. */
    protected function benchmarkReturn(string $day, int $sessions): ?float
    {
        if (count($this->benchmarkDates) < $sessions + 1) {
            return null;
        }

        $idx = self::lastIndexAtOrBefore($this->benchmarkDates, $day);

        if ($idx < $sessions || $this->benchmarkCloses[$idx - $sessions] <= 0) {
            return null;
        }

        return round($this->benchmarkCloses[$idx] / $this->benchmarkCloses[$idx - $sessions] - 1, 4);
    }

    /** @param array<int, string> $dates sorted ascending */
    protected static function lastIndexAtOrBefore(array $dates, string $day): int
    {
        $idx = -1;

        for ($lo = 0, $hi = count($dates) - 1; $lo <= $hi;) {
            $mid = intdiv($lo + $hi, 2);

            if ($dates[$mid] <= $day) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $idx;
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
        return $this->benchmarkReturn($day, 5);
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
            // 45 calendar days ≈ 30 sessions of padding — enough for the
            // 20-session small-cap relative-strength window.
            ->where('bucket_start', '>=', gmdate('Y-m-d', strtotime($from.' UTC') - 45 * 86400))
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

        $fromPadded = gmdate('Y-m-d', strtotime($from.' UTC') - 45 * 86400);

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

        // Extra macro series with generic date/close arrays (spy, xbi).
        foreach (['spy', 'xbi'] as $key) {
            if (! isset($symbols[$key], $ids[$symbols[$key]])) {
                continue;
            }

            $this->macroSeries[$key] = ['dates' => [], 'closes' => []];

            DB::table('market_bars')
                ->where('ticker_id', $ids[$symbols[$key]])
                ->where('interval', '1d')
                ->where('bucket_start', '>=', $fromPadded)
                ->where('bucket_start', '<=', $to.' 23:59:59')
                ->orderBy('bucket_start')
                ->select('bucket_start', 'close')
                ->each(function ($bar) use ($key): void {
                    $this->macroSeries[$key]['dates'][] = substr((string) $bar->bucket_start, 0, 10);
                    $this->macroSeries[$key]['closes'][] = (float) $bar->close;
                });
        }
    }

    /** @param array<int, int> $tickerIds */
    protected function loadInsiderTrades(array $tickerIds, string $from, string $to): void
    {
        // Aggregate per (ticker, filed date) — features only need daily sums.
        DB::table('insider_trades')
            ->whereIn('ticker_id', $tickerIds)
            ->where('filed_at', '>=', gmdate('Y-m-d', strtotime($from.' UTC') - 95 * 86400))
            ->where('filed_at', '<=', $to)
            ->selectRaw("ticker_id, filed_at,
                SUM(CASE WHEN code = 'P' THEN COALESCE(value, 0) ELSE 0 END) AS buy_value,
                SUM(CASE WHEN code = 'S' THEN COALESCE(value, 0) ELSE 0 END) AS sell_value,
                SUM(CASE WHEN code = 'P' THEN 1 ELSE 0 END) AS buys")
            ->groupBy('ticker_id', 'filed_at')
            ->orderBy('filed_at')
            ->each(function ($row): void {
                $this->insiderTrades[(int) $row->ticker_id][] = [
                    'date' => substr((string) $row->filed_at, 0, 10),
                    'buy' => (float) $row->buy_value,
                    'sell' => (float) $row->sell_value,
                    'buys' => (int) $row->buys,
                ];
            });
    }

    /** @param array<int, int> $tickerIds */
    protected function loadNewsCatalysts(array $tickerIds, string $from, string $to): void
    {
        DB::table('ticker_news')
            ->whereIn('ticker_id', $tickerIds)
            ->whereNotNull('catalyst_type')
            ->whereNotIn('catalyst_type', ['none', 'other'])
            ->where('published_at', '>=', gmdate('Y-m-d', strtotime($from.' UTC') - 10 * 86400))
            ->where('published_at', '<=', $to.' 23:59:59')
            ->select('ticker_id', 'published_at', 'catalyst_type')
            ->orderBy('published_at')
            ->each(function ($row): void {
                $this->newsCatalysts[(int) $row->ticker_id][] = [
                    'date' => substr((string) $row->published_at, 0, 10),
                    'offering' => $row->catalyst_type === 'offering',
                ];
            });
    }

    /** @param array<int, int> $tickerIds */
    protected function loadTickerMentions(array $tickerIds, string $from, string $to): void
    {
        $gate = AnalyticsGate::mentionJoin('m');

        $rows = DB::select(<<<SQL
            SELECT m.ticker_id, date(m.posted_at) AS day, COUNT(*) AS mentions
            FROM post_ticker_mentions m
            {$gate}
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
        $gate = AnalyticsGate::mentionJoin('m');

        $rows = DB::select(<<<SQL
            SELECT date(m.posted_at) AS day, COUNT(*) AS mentions
            FROM post_ticker_mentions m
            {$gate}
            WHERE m.posted_at >= ? AND m.posted_at < ?
            GROUP BY day
        SQL, [gmdate('Y-m-d', strtotime($from.' UTC') - 35 * 86400), gmdate('Y-m-d', strtotime($to.' UTC') + 86400)]);

        foreach ($rows as $row) {
            $this->siteMentions[(string) $row->day] = (int) $row->mentions;
        }
    }
}
