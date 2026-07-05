<?php

namespace App\Http\Controllers;

use App\Models\AuthorLeaderboard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Voices — the weekly leaderboard of reddit authors who are consistently
 * early on stocks that explode. Reads the latest snapshot only; all the
 * heavy lifting happens in the weekly BuildAuthorLeaderboard job.
 */
class VoicesController extends Controller
{
    public function index(): Response
    {
        $week = AuthorLeaderboard::currentWeek();

        $rows = $week === null ? collect() : AuthorLeaderboard::query()
            ->where('week_start', $week)
            ->orderBy('rank')
            ->with('author:id,platform,username,karma,stats,pump_risk_score,account_created_at')
            ->get()
            ->map(fn (AuthorLeaderboard $row): array => [
                'rank' => $row->rank,
                'platform' => $row->platform,
                'author' => [
                    'username' => $row->author?->username,
                    // Reddit reach = karma; X reach = followers.
                    'karma' => $row->platform === 'twitter'
                        ? data_get($row->author?->stats, 'followers')
                        : $row->author?->karma,
                    'pump_risk_score' => $row->author?->pump_risk_score,
                    'account_created_at' => $row->author?->account_created_at?->toDateString(),
                ],
                'calls' => $row->calls,
                'wins' => $row->wins,
                'flats' => $row->flats,
                'losses' => $row->losses,
                'hit_rate' => $row->hit_rate,
                'wilson_lb' => $row->wilson_lb,
                'avg_peak_return' => $row->avg_peak_return,
                'best_peak_return' => $row->best_peak_return,
                'best_call' => $row->best_call,
                'top_tickers' => $row->top_tickers,
                'recent_calls' => $row->recent_calls,
            ]);

        return Inertia::render('voices', [
            'week' => $week,
            'boards' => [
                'reddit' => $rows->where('platform', 'reddit')->values(),
                'twitter' => $rows->where('platform', 'twitter')->values(),
            ],
            'thresholds' => [
                'win' => (float) config('pennyhunt.voices.win_threshold'),
                'loss' => (float) config('pennyhunt.voices.loss_threshold'),
                'horizon' => (int) config('pennyhunt.voices.horizon_sessions'),
                'min_calls' => (int) config('pennyhunt.voices.min_calls'),
                'min_calls_twitter' => (int) config('pennyhunt.voices.min_calls_twitter'),
                'active_days' => (int) config('pennyhunt.voices.active_days'),
            ],
        ]);
    }
}
