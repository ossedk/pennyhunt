<?php

use App\Jobs\Backtesting\RunBacktest;
use App\Jobs\Ingestion\PollApeWisdom;
use App\Jobs\Ingestion\PollRedditSubreddit;
use App\Jobs\Ingestion\PollRedditViaApify;
use App\Jobs\Ingestion\PollTradestie;
use App\Jobs\Ingestion\PollTwitterViaApify;
use App\Jobs\Ingestion\SyncTickerUniverse;
use App\Jobs\Ingestion\SyncTrendingNews;
use App\Jobs\Metrics\BuildAuthorLeaderboard;
use App\Jobs\Metrics\BuildTickerMetrics;
use App\Jobs\Metrics\ScoreAuthorPumpRisk;
use App\Jobs\Metrics\ScoreAuthorTrackRecords;
use App\Jobs\Nlp\ClassifyNewsCatalysts;
use App\Jobs\Nlp\GenerateMarketBrief;
use App\Jobs\Signals\ComputeSignals;
use App\Jobs\Signals\GradeSignals;
use App\Jobs\Trading\ManageSignalTrades;
use App\Jobs\Trading\RefreshOpenTradeQuotes;
use App\Models\BacktestRun;
use App\Models\Source;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Ingestion cadence
|--------------------------------------------------------------------------
| Reddit primary path: one batched Apify run every 15 minutes covering all
| enabled subreddits. Steady-state runs take ~5 min (backfill runs up to
| ~13 min), so 15 min never overlaps; the job is also ShouldBeUnique. Cost
| tracks new-post volume (postDateLimit), not poll frequency.
| Fallback (no APIFY_KEY): native OAuth polling every 2 minutes per sub.
| Aggregators (ApeWisdom / Tradestie): every 30 minutes.
*/

Schedule::call(function (): void {
    if (filled(config('pennyhunt.apify.token'))) {
        PollRedditViaApify::dispatch();
    }
})->everyFifteenMinutes()->name('poll-reddit-apify')->onOneServer();

Schedule::call(function (): void {
    if (filled(config('pennyhunt.apify.token'))) {
        return; // Apify path is active; avoid double ingestion.
    }

    Source::query()
        ->where('type', 'reddit')
        ->where('enabled', true)
        ->each(fn (Source $source) => PollRedditSubreddit::dispatch($source->id));
})->everyTwoMinutes()->name('poll-reddit')->onOneServer();

// X/Twitter cashtag confirmation: hourly, only for tickers already trending
// on Reddit (cost-bounded). Requires PENNYHUNT_TWITTER_ENABLED + paid Apify.
Schedule::call(function (): void {
    if (filled(config('pennyhunt.apify.token')) && config('pennyhunt.apify.twitter.enabled')) {
        PollTwitterViaApify::dispatch();
    }
})->hourly()->name('poll-twitter-apify')->onOneServer();

Schedule::call(function (): void {
    Source::query()
        ->where('key', 'apewisdom')
        ->where('enabled', true)
        ->each(fn (Source $source) => PollApeWisdom::dispatch($source->id));
})->everyThirtyMinutes()->name('poll-apewisdom')->onOneServer();

Schedule::call(function (): void {
    Source::query()
        ->where('key', 'tradestie')
        ->where('enabled', true)
        ->each(fn (Source $source) => PollTradestie::dispatch($source->id));
})->everyThirtyMinutes()->name('poll-tradestie')->onOneServer();

/*
|--------------------------------------------------------------------------
| Metrics & signals
|--------------------------------------------------------------------------
*/

Schedule::job(new BuildTickerMetrics('5m'))->everyFiveMinutes()->name('rollup-5m')->onOneServer();
Schedule::job(new BuildTickerMetrics('1h'))->everyFifteenMinutes()->name('rollup-1h')->onOneServer();
Schedule::job(new BuildTickerMetrics('1d'))->hourly()->name('rollup-1d')->onOneServer();

Schedule::job(new ComputeSignals)->everyFiveMinutes()->name('compute-signals')->onOneServer();
Schedule::job(new GradeSignals)->dailyAt('06:00')->name('grade-signals')->onOneServer();

// Forward-test trade lifecycle: fill pending entries from the fresh bars
// (synced 05:00) and walk open positions for stop / time exits.
Schedule::job(new ManageSignalTrades)->dailyAt('05:10')->name('manage-signal-trades')->onOneServer();

// Indicative quotes for open positions during US market hours.
Schedule::job(new RefreshOpenTradeQuotes)
    ->everyFifteenMinutes()
    ->timezone('America/New_York')
    ->between('9:30', '16:15')
    ->weekdays()
    ->name('refresh-trade-quotes')
    ->onOneServer();
Schedule::job(new ScoreAuthorPumpRisk)->dailyAt('04:30')->name('score-pump-risk')->onOneServer();
Schedule::job(new ScoreAuthorTrackRecords)->dailyAt('06:30')->name('score-author-track-records')->onOneServer();

// Voices leaderboard: weekly Monday build after the daily bar sync, so the
// prior week's calls grade against complete forward windows.
Schedule::job(new BuildAuthorLeaderboard)->weeklyOn(1, '07:30')->name('build-author-leaderboard')->onOneServer();

// Fresh daily bars for every recently-mentioned ticker so the SignalEngine's
// market-confirmation gate (price cap + volume z) rarely needs on-demand
// Yahoo fetches. Runs after the US close, before grading.
Schedule::command('pennyhunt:sync-market-bars --months=2 --min-mentions=2')
    ->dailyAt('05:00')
    ->name('sync-market-bars')
    ->onOneServer();

// FINRA Reg SHO daily short volume (free, keyless). Published after the
// close; 3-day window self-heals missed nights.
Schedule::command('pennyhunt:sync-short-volume --days=3')
    ->dailyAt('05:15')
    ->name('sync-short-volume')
    ->onOneServer();

// EDGAR dilution data for recently-mentioned tickers. skip-synced-days keeps
// the nightly delta small (only new/never-synced tickers hit the API).
Schedule::command('pennyhunt:sync-sec-filings --months=3 --min-mentions=2 --skip-synced-days=7')
    ->dailyAt('05:30')
    ->name('sync-sec-filings')
    ->onOneServer();

// Form 4 insider purchases/sales — the bullish side of EDGAR. Weekly delta
// per ticker (skip-synced-days), spread across the same mentioned universe.
Schedule::command('pennyhunt:sync-insider-trades --months=3 --min-mentions=2 --skip-synced-days=7')
    ->dailyAt('05:45')
    ->name('sync-insider-trades')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| ML: daily LLM-feature refresh + GBM retrain (NO auto-activation)
|--------------------------------------------------------------------------
| While the historical LLM classification backfill runs (days), fold the
| newly-classified posts into the latest run's events and retrain the GBM
| so we can watch the LLM features' importance grow as coverage climbs.
| The retrained model is imported INACTIVE — activation stays a human
| decision (pennyhunt:train-gbm --activate) once the metrics justify it.
| 07:00: after bars (05:00), grading (06:00) and track records (06:30).
*/

// Weekly rolling-window backtest (24 months ending yesterday): the nightly
// retrain trains on "latest done run", so without this the training set
// would be frozen at whenever someone last ran a backtest by hand. This
// keeps ~5 new sessions/week flowing into training automatically.
Schedule::call(function (): void {
    $run = BacktestRun::create([
        'status' => 'pending',
        'params' => [
            'from' => now()->subMonths(24)->toDateString(),
            'to' => now()->subDay()->toDateString(),
            'threshold' => 0.65,
            'min_daily_mentions' => 3,
            'hit_threshold' => 0.3,
            'friction' => 0.05,
            'min_volume_z' => 2,
            'max_entry_price' => 5,
            'stop_loss' => 0.1,
            'baseline_days' => 30,
            'cooldown_days' => 3,
            'note' => 'weekly rolling training window',
        ],
    ]);

    RunBacktest::dispatch($run->id);
})->weeklyOn(7, '02:00')->name('weekly-rolling-backtest')->onOneServer();

Schedule::command('pennyhunt:backfill-llm-features')
    ->dailyAt('07:00')
    ->name('backfill-llm-features')
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ml-nightly.log'));

Schedule::command('pennyhunt:train-gbm')
    ->dailyAt('07:15')
    ->name('train-gbm-shadow')
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ml-nightly.log'));

/*
|--------------------------------------------------------------------------
| Desk: news + LLM market brief
|--------------------------------------------------------------------------
| News stays warm for the tickers people are talking about (per-ticker 6h
| cooldown lives inside SyncTickerNews). The market brief regenerates
| hourly through the trading day (UTC ≈ pre-market through after-hours);
| the Desk also dispatches on-demand when it renders with a stale brief.
*/

Schedule::job(new SyncTrendingNews)->hourly()->name('sync-trending-news')->onOneServer();

// Catalyst-type classification for new headlines (feeds news_catalyst_7d /
// news_offering_7d features + the UI badges). Offset from the news sync.
Schedule::job(new ClassifyNewsCatalysts)
    ->hourlyAt(20)
    ->name('classify-news-catalysts')
    ->onOneServer();

Schedule::job(new GenerateMarketBrief)
    ->hourly()
    ->between('10:00', '23:00')
    ->weekdays()
    ->name('generate-market-brief')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Reference data
|--------------------------------------------------------------------------
*/

Schedule::job(new SyncTickerUniverse)->weeklyOn(1, '05:00')->name('sync-tickers')->onOneServer();
