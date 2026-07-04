<?php

namespace App\Services\Signals;

/**
 * The component formulas shared verbatim between the live SignalEngine and
 * the Backtester, so backtest results describe the code we actually run.
 */
class SignalMath
{
    public const WEIGHTS = [
        'acceleration' => 0.40,
        'breadth' => 0.20,
        'sentiment' => 0.25,
        'cross_source' => 0.15,
    ];

    /** Sigmoid squash of the mention z-score: z=0 -> 0.5, z=2 -> ~0.88, z=4 -> ~0.98 */
    public static function acceleration(?float $zscoreMentions): float
    {
        return 1 / (1 + exp(-1.0 * ($zscoreMentions ?? 0.0)));
    }

    /** Unique authors / mentions: 1.0 = fully organic, low = one account spamming. */
    public static function breadth(int $uniqueAuthors, int $mentionCount): float
    {
        return $mentionCount > 0 ? min($uniqueAuthors / $mentionCount, 1.0) : 0.0;
    }

    /** Sentiment is -1..1; shift to 0..1. */
    public static function sentiment(?float $weightedSentiment): float
    {
        return (($weightedSentiment ?? 0.0) + 1) / 2;
    }

    /**
     * Weighted composite. When a component has no data (e.g. no historical
     * aggregator snapshots in a backtest) pass null and its weight is
     * renormalized across the remaining components.
     *
     * @param  array{acceleration: float, breadth: float, sentiment: float, cross_source: ?float}  $components
     */
    public static function composite(array $components): float
    {
        $available = array_filter($components, fn ($value) => $value !== null);
        $weightSum = array_sum(array_intersect_key(self::WEIGHTS, $available));

        if ($weightSum <= 0) {
            return 0.0;
        }

        $score = 0.0;

        foreach ($available as $key => $value) {
            $score += $value * self::WEIGHTS[$key];
        }

        return $score / $weightSum;
    }
}
