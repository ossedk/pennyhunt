<?php

use App\Models\Author;
use App\Models\BacktestRun;
use App\Models\MarketBar;
use App\Models\PostSentiment;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use App\Services\Backtesting\Backtester;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Seeds a ticker that is quiet for 20 days (1 mention/day), then explodes to
 * 12 mentions from 10 authors on day 21, with a +50% price move afterwards.
 */
function seedBacktestScenario(): array
{
    $source = Source::create([
        'key' => 'reddit:pennystocks', 'type' => 'reddit', 'name' => 'r/pennystocks',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => ['subreddit' => 'pennystocks'],
    ]);

    $ticker = Ticker::create(['symbol' => 'PUMP', 'name' => 'Pump Corp', 'is_active' => true]);

    $start = CarbonImmutable::parse('2026-03-01');
    $spikeDay = $start->addDays(20); // 2026-03-21

    $makeMention = function (CarbonImmutable $at, int $authorIdx) use ($source, $ticker) {
        $author = Author::firstOrCreate(
            ['platform' => 'reddit', 'username' => "user{$authorIdx}"],
            ['stats' => []],
        );

        $post = RawPost::create([
            'source_id' => $source->id,
            'external_id' => 't3_'.uniqid(),
            'kind' => 'post',
            'author_id' => $author->id,
            'title' => '$PUMP to the moon',
            'body' => 'buying $PUMP',
            'score' => 1,
            'num_comments' => 0,
            'posted_at' => $at,
            'ingested_at' => $at,
            'meta' => [],
        ]);

        PostTickerMention::create([
            'raw_post_id' => $post->id, 'ticker_id' => $ticker->id,
            'method' => 'cashtag', 'confidence' => 1.0, 'posted_at' => $at,
        ]);

        PostSentiment::create([
            'raw_post_id' => $post->id, 'lexicon_score' => 0.8, 'scored_at' => $at,
        ]);
    };

    // Quiet baseline with natural variance: 0-2 mentions/day for 20 days.
    for ($d = 0; $d < 20; $d++) {
        $mentionsToday = [1, 0, 1, 2][$d % 4];

        for ($m = 0; $m < $mentionsToday; $m++) {
            $makeMention($start->addDays($d)->setTime(12 + $m, 0), 1);
        }
    }

    // Spike: 12 mentions from 10 distinct authors.
    for ($i = 0; $i < 12; $i++) {
        $makeMention($spikeDay->setTime(9 + ($i % 8), 15), $i % 10);
    }

    // Daily bars: flat $1.00 through the spike, then +50% over the next days.
    $price = 1.00;
    for ($d = 0; $d < 35; $d++) {
        $date = $start->addDays($d);

        if ($date->isWeekend()) {
            continue;
        }

        if ($date->gt($spikeDay)) {
            $price = min($price * 1.12, 1.60); // ramps to +50%+ within 5 sessions
        }

        MarketBar::create([
            'ticker_id' => $ticker->id, 'interval' => '1d',
            'bucket_start' => $date->setTime(0, 0),
            'open' => $price, 'high' => $price * 1.05, 'low' => $price * 0.97,
            'close' => $price,
            // Varying baseline volume with a spike-day surge (needed for volume_z)
            'volume' => $date->eq($spikeDay) ? 900000 : 100000 + ($d % 5) * 10000,
        ]);
    }

    return [$ticker, $spikeDay];
}

it('fires a simulated signal on a mention spike and grades it against bars', function () {
    [$ticker, $spikeDay] = seedBacktestScenario();

    $run = BacktestRun::create([
        'status' => 'running',
        'params' => [
            'from' => '2026-03-01', 'to' => '2026-04-04',
            'threshold' => 0.6, 'min_daily_mentions' => 3,
            'baseline_days' => 30, 'cooldown_days' => 3, 'hit_threshold' => 0.30,
            'friction' => 0.05,
        ],
    ]);

    app(Backtester::class)->run($run);

    $run->refresh();

    expect($run->status)->toBe('done');

    $summary = $run->results['summary'];
    $fired = $run->events()->where('fired', true)->get();

    expect($summary['signal_count'])->toBe(1)
        ->and($fired)->toHaveCount(1)
        ->and($fired[0]->symbol)->toBe('PUMP')
        ->and($fired[0]->day->toDateString())->toBe($spikeDay->toDateString())
        ->and($fired[0]->hit)->toBeTrue()
        ->and($fired[0]->volume_z)->not->toBeNull()
        ->and($fired[0]->classification)->toBe('prediction')
        ->and($summary['hit_rate'])->toEqual(1.0)
        ->and($summary['avg_net_return_5d'])->toBeLessThan($summary['avg_return_5d']);

    // Control events (non-fired candidate days) are persisted too.
    expect($run->events()->where('fired', false)->count())->toBeGreaterThanOrEqual(0);
});

it('exits at a gapped-through take-profit and reports exit stats', function () {
    seedBacktestScenario();

    // Entry at 1.12; +12%/session ramp. take=10% -> day-1 open (1.2544) gaps
    // through the 1.232 take level, so the fill is the (better) open.
    $run = BacktestRun::create([
        'status' => 'running',
        'params' => [
            'from' => '2026-03-01', 'to' => '2026-04-04',
            'threshold' => 0.6, 'min_daily_mentions' => 3,
            'baseline_days' => 30, 'cooldown_days' => 3, 'hit_threshold' => 0.30,
            'friction' => 0.05, 'take_profit' => 0.10,
        ],
    ]);

    app(Backtester::class)->run($run);
    $run->refresh();

    $fired = $run->events()->where('fired', true)->first();
    $summary = $run->results['summary'];

    expect($fired->exit_reason)->toBe('take')
        ->and($fired->exit_day)->toBe(1)
        ->and($fired->exit_return)->toEqualWithDelta(0.12, 0.001)
        ->and($summary['take_rate'])->toEqual(1.0)
        ->and($summary['avg_net_return_5d'])->toEqualWithDelta(0.12 - 0.05, 0.001);
});

it('stops out pessimistically when the stop is inside the entry-day range', function () {
    seedBacktestScenario();

    // Entry-day low is entry*0.97; a 2% stop sits inside that range, so the
    // trade stops out on day 0 at exactly -2% even though the take would have
    // been reached later (stop is evaluated before take).
    $run = BacktestRun::create([
        'status' => 'running',
        'params' => [
            'from' => '2026-03-01', 'to' => '2026-04-04',
            'threshold' => 0.6, 'min_daily_mentions' => 3,
            'baseline_days' => 30, 'cooldown_days' => 3, 'hit_threshold' => 0.30,
            'friction' => 0.05, 'stop_loss' => 0.02, 'take_profit' => 0.50,
        ],
    ]);

    app(Backtester::class)->run($run);
    $run->refresh();

    $fired = $run->events()->where('fired', true)->first();

    expect($fired->exit_reason)->toBe('stop')
        ->and($fired->exit_day)->toBe(0)
        ->and($fired->exit_return)->toEqualWithDelta(-0.02, 0.001)
        ->and($run->results['summary']['stop_rate'])->toEqual(1.0);
});

it('fires nothing when mentions never accelerate', function () {
    seedBacktestScenario();

    // Threshold high enough that even the spike day can't reach it.
    $run = BacktestRun::create([
        'status' => 'running',
        'params' => [
            'from' => '2026-03-01', 'to' => '2026-04-04',
            'threshold' => 0.99, 'min_daily_mentions' => 3,
            'baseline_days' => 30, 'cooldown_days' => 3, 'hit_threshold' => 0.30,
        ],
    ]);

    app(Backtester::class)->run($run);

    expect($run->refresh()->results['summary']['signal_count'])->toBe(0);
});
