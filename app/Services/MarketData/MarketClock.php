<?php

namespace App\Services\MarketData;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * US equity market session state for the UI ("Market open", "Pre-market",
 * "After hours", "Market closed"). Primary source is Polygon's market
 * status endpoint (holiday-aware); when Polygon is unavailable we fall
 * back to the regular NYSE schedule computed from the clock (no holiday
 * awareness — good enough for a badge, never used for trading logic).
 */
class MarketClock
{
    public const STATUS_OPEN = 'open';

    public const STATUS_EARLY = 'early_hours';

    public const STATUS_AFTER = 'after_hours';

    public const STATUS_CLOSED = 'closed';

    public function __construct(protected PolygonClient $polygon) {}

    /** @return array{status: string, as_of: string, source: string} */
    public function status(): array
    {
        return Cache::remember('market-clock:status', 60, function (): array {
            $payload = rescue(fn (): ?array => $this->polygon->marketStatus(), null, report: false);

            if (is_array($payload) && isset($payload['market'])) {
                return [
                    'status' => $this->normalize($payload),
                    'as_of' => (string) ($payload['serverTime'] ?? now()->toIso8601String()),
                    'source' => 'polygon',
                ];
            }

            return [
                'status' => $this->fromClock(now()),
                'as_of' => now()->toIso8601String(),
                'source' => 'schedule',
            ];
        });
    }

    /** @param array<string, mixed> $payload */
    protected function normalize(array $payload): string
    {
        if (($payload['market'] ?? null) === 'open') {
            return self::STATUS_OPEN;
        }

        if (($payload['earlyHours'] ?? false) === true) {
            return self::STATUS_EARLY;
        }

        if (($payload['afterHours'] ?? false) === true) {
            return self::STATUS_AFTER;
        }

        return self::STATUS_CLOSED;
    }

    /** Regular NYSE schedule (America/New_York), no holiday calendar. */
    protected function fromClock(CarbonInterface $now): string
    {
        $et = $now->copy()->setTimezone('America/New_York');

        if ($et->isWeekend()) {
            return self::STATUS_CLOSED;
        }

        $minutes = $et->hour * 60 + $et->minute;

        return match (true) {
            $minutes >= 4 * 60 && $minutes < 9 * 60 + 30 => self::STATUS_EARLY,
            $minutes >= 9 * 60 + 30 && $minutes < 16 * 60 => self::STATUS_OPEN,
            $minutes >= 16 * 60 && $minutes < 20 * 60 => self::STATUS_AFTER,
            default => self::STATUS_CLOSED,
        };
    }
}
