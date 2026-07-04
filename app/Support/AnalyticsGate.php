<?php

namespace App\Support;

/**
 * Source gating for the analytical pipeline (rollups, signals, backtests,
 * feature aggregates). While pennyhunt.analytics.include_twitter is false,
 * Twitter/X posts are display-only: they never enter mention counts,
 * sentiment aggregates, z-scores or GBM training data. Tweets are noisy
 * (bots, crypto-cashtag collisions, parody accounts) and their predictive
 * value is unproven — exclude until validated against backtest outcomes.
 */
final class AnalyticsGate
{
    /**
     * SQL JOIN fragment that drops Twitter-sourced rows via an
     * already-joined raw_posts alias. Empty string when Twitter is allowed.
     */
    public static function sourceJoin(string $rawPostAlias): string
    {
        if (config('pennyhunt.analytics.include_twitter')) {
            return '';
        }

        return "JOIN sources {$rawPostAlias}_gate_src ON {$rawPostAlias}_gate_src.id = {$rawPostAlias}.source_id AND {$rawPostAlias}_gate_src.type <> 'twitter'";
    }

    /**
     * SQL JOIN fragment for queries that select from post_ticker_mentions
     * without joining raw_posts. Empty string when Twitter is allowed.
     */
    public static function mentionJoin(string $mentionAlias): string
    {
        if (config('pennyhunt.analytics.include_twitter')) {
            return '';
        }

        return "JOIN raw_posts {$mentionAlias}_gate_rp ON {$mentionAlias}_gate_rp.id = {$mentionAlias}.raw_post_id
                JOIN sources {$mentionAlias}_gate_src ON {$mentionAlias}_gate_src.id = {$mentionAlias}_gate_rp.source_id AND {$mentionAlias}_gate_src.type <> 'twitter'";
    }
}
