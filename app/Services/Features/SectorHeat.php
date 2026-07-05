<?php

namespace App\Services\Features;

use App\Support\AnalyticsGate;
use Illuminate\Support\Facades\DB;

/**
 * Sympathy-play features: penny explosions cluster by sector — when one
 * quantum/biotech name rips, peers follow within days. Sector = 2-digit SIC
 * major group (EDGAR/Polygon), peers = tickers in the loaded universe
 * (mentioned names with bars — the social universe we can actually trade).
 *
 *  - sector_heat: fraction of same-sector peers (excluding self) whose
 *    close rose >= 20% over the trailing 5 sessions ending at/before the
 *    signal day. 0.3 = 30% of the sector is already running.
 *  - sector_mention_z: today's sector-wide mention count z-scored against
 *    the sector's trailing 30 days — social contagion before price.
 *
 * As-of by construction: both features only read data at/before the day.
 */
class SectorHeat
{
    public const FEATURE_KEYS = ['sector_heat', 'sector_mention_z'];

    protected const HOT_RETURN = 0.20;

    protected const HOT_WINDOW = 5;

    /** @var array<int, string> [tickerId => sic2] */
    protected array $sectorOf = [];

    /** @var array<string, array<int, array{n: int, hot: int}>> [sic2 => [dayIdx => stats]] */
    protected array $sectorDays = [];

    /** @var array<int, array<int, bool>> [tickerId => [dayIdx => isHot]] */
    protected array $tickerHot = [];

    /** @var array<string, array<string, int>> [sic2 => [date => mentions]] */
    protected array $sectorMentions = [];

    /**
     * @param  array<int, int>  $tickerIds
     * @param  array<int, array<int, array{date: string, open: float, high: float, low: float, close: float, volume: float}>>  $barsByTicker  ascending bars keyed by ticker id
     */
    public static function load(array $tickerIds, array $barsByTicker, string $from, string $to): self
    {
        $self = new self;

        if ($tickerIds === []) {
            return $self;
        }

        $self->sectorOf = DB::table('tickers')
            ->whereIn('id', $tickerIds)
            ->whereNotNull('sic_code')
            ->pluck('sic_code', 'id')
            ->map(fn ($sic): string => substr((string) $sic, 0, 2))
            ->all();

        $self->buildPriceHeat($barsByTicker, $from, $to);
        $self->loadSectorMentions($from, $to);

        return $self;
    }

    /**
     * Live path (SignalEngine): the candidate set is small, but sector heat
     * must be measured against the whole sector, so peers = active tickers in
     * the same SIC major group mentioned in the last 30 days. Their trailing
     * bars are pulled in one query.
     *
     * @param  array<int, int>  $tickerIds
     */
    public static function loadForDay(array $tickerIds, string $day): self
    {
        $self = new self;

        if ($tickerIds === []) {
            return $self;
        }

        $self->sectorOf = DB::table('tickers')
            ->whereIn('id', $tickerIds)
            ->whereNotNull('sic_code')
            ->pluck('sic_code', 'id')
            ->map(fn ($sic): string => substr((string) $sic, 0, 2))
            ->all();

        $sectors = array_values(array_unique($self->sectorOf));

        if ($sectors === []) {
            return $self;
        }

        $peerIds = DB::table('tickers')
            ->where('is_active', true)
            ->whereNotNull('sic_code')
            ->whereIn(DB::raw('substr(sic_code, 1, 2)'), $sectors)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('post_ticker_mentions')
                ->whereColumn('post_ticker_mentions.ticker_id', 'tickers.id')
                ->where('posted_at', '>=', now()->subDays(30)))
            ->pluck('sic_code', 'id')
            ->map(fn ($sic): string => substr((string) $sic, 0, 2))
            ->all();

        $bars = [];

        DB::table('market_bars')
            ->whereIn('ticker_id', array_keys($peerIds))
            ->where('interval', '1d')
            ->where('bucket_start', '>=', gmdate('Y-m-d', strtotime($day.' UTC') - 15 * 86400))
            ->where('bucket_start', '<=', $day.' 23:59:59')
            ->orderBy('bucket_start')
            ->select('ticker_id', 'bucket_start', 'close')
            ->each(function ($bar) use (&$bars): void {
                $bars[(int) $bar->ticker_id][] = [
                    'date' => substr((string) $bar->bucket_start, 0, 10),
                    'close' => (float) $bar->close,
                ];
            });

        $self->sectorOf += $peerIds; // candidates keep their mapping; peers extend it
        $self->buildPriceHeat($bars, $day, $day);
        $self->loadSectorMentions($day, $day);

        return $self;
    }

    /**
     * @return array{sector_heat: ?float, sector_mention_z: ?float}
     */
    public function features(int $tickerId, string $day): array
    {
        $sic2 = $this->sectorOf[$tickerId] ?? null;

        if ($sic2 === null) {
            return ['sector_heat' => null, 'sector_mention_z' => null];
        }

        return [
            'sector_heat' => $this->heat($tickerId, $sic2, $day),
            'sector_mention_z' => $this->mentionZ($sic2, $day),
        ];
    }

    protected function heat(int $tickerId, string $sic2, string $day): ?float
    {
        $dayIdx = intdiv((int) strtotime($day.' 00:00:00 UTC'), 86400);

        // Latest sector snapshot at/before the day (weekends → Friday).
        for ($i = 0; $i < 5; $i++) {
            $stats = $this->sectorDays[$sic2][$dayIdx - $i] ?? null;

            if ($stats === null) {
                continue;
            }

            $selfHot = ($this->tickerHot[$tickerId][$dayIdx - $i] ?? false) ? 1 : 0;
            $peers = $stats['n'] - 1;

            return $peers >= 3 ? round(($stats['hot'] - $selfHot) / $peers, 4) : null;
        }

        return null;
    }

    protected function mentionZ(string $sic2, string $day): ?float
    {
        $counts = $this->sectorMentions[$sic2] ?? [];
        $ts = strtotime($day.' UTC');
        $trailing = [];

        for ($i = 1; $i <= 30; $i++) {
            $trailing[] = (float) ($counts[gmdate('Y-m-d', $ts - $i * 86400)] ?? 0);
        }

        if (count(array_filter($trailing)) < 10) {
            return null;
        }

        $mean = array_sum($trailing) / count($trailing);
        $variance = 0.0;

        foreach ($trailing as $v) {
            $variance += ($v - $mean) ** 2;
        }

        $sd = sqrt($variance / (count($trailing) - 1));

        return $sd > 0 ? round((((float) ($counts[$day] ?? 0)) - $mean) / $sd, 2) : null;
    }

    /**
     * Per (sector, session) hot-peer counts from the bar arrays the caller
     * already holds — no extra bar queries.
     *
     * @param  array<int, array<int, array{date: string, close: float}>>  $barsByTicker
     */
    protected function buildPriceHeat(array $barsByTicker, string $from, string $to): void
    {
        $fromIdx = intdiv((int) strtotime($from.' 00:00:00 UTC'), 86400) - 7;
        $toIdx = intdiv((int) strtotime($to.' 00:00:00 UTC'), 86400);

        foreach ($barsByTicker as $tickerId => $bars) {
            $sic2 = $this->sectorOf[$tickerId] ?? null;

            if ($sic2 === null) {
                continue;
            }

            $n = count($bars);

            for ($i = self::HOT_WINDOW; $i < $n; $i++) {
                $dayIdx = intdiv((int) strtotime($bars[$i]['date'].' 00:00:00 UTC'), 86400);

                if ($dayIdx < $fromIdx || $dayIdx > $toIdx) {
                    continue;
                }

                $base = $bars[$i - self::HOT_WINDOW]['close'];
                $hot = $base > 0 && ($bars[$i]['close'] / $base - 1) >= self::HOT_RETURN;

                $stats = $this->sectorDays[$sic2][$dayIdx] ?? ['n' => 0, 'hot' => 0];
                $stats['n']++;

                if ($hot) {
                    $stats['hot']++;
                    $this->tickerHot[$tickerId][$dayIdx] = true;
                }

                $this->sectorDays[$sic2][$dayIdx] = $stats;
            }
        }
    }

    protected function loadSectorMentions(string $from, string $to): void
    {
        $gate = AnalyticsGate::mentionJoin('m');

        $rows = DB::select(<<<SQL
            SELECT t.sic_code, date(m.posted_at) AS day, COUNT(*) AS mentions
            FROM post_ticker_mentions m
            JOIN tickers t ON t.id = m.ticker_id AND t.sic_code IS NOT NULL
            {$gate}
            WHERE m.posted_at >= ? AND m.posted_at < ?
            GROUP BY t.sic_code, day
        SQL, [
            gmdate('Y-m-d', strtotime($from.' UTC') - 35 * 86400),
            gmdate('Y-m-d', strtotime($to.' UTC') + 86400),
        ]);

        foreach ($rows as $row) {
            $sic2 = substr((string) $row->sic_code, 0, 2);
            $this->sectorMentions[$sic2][(string) $row->day] =
                ($this->sectorMentions[$sic2][(string) $row->day] ?? 0) + (int) $row->mentions;
        }
    }
}
