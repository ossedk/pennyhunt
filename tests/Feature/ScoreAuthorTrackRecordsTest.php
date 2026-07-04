<?php

use App\Jobs\Metrics\ScoreAuthorTrackRecords;
use App\Models\Author;
use App\Models\BacktestEvent;
use App\Models\BacktestRun;
use App\Models\PostTickerMention;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('grades authors against backtest candidate days with Laplace smoothing', function () {
    $run = BacktestRun::create(['status' => 'done', 'params' => [], 'results' => []]);
    $ticker = Ticker::create(['symbol' => 'TRAK', 'name' => 'Track Corp', 'is_active' => true]);

    $source = Source::create([
        'key' => 'reddit:test', 'type' => 'reddit', 'name' => 'r/test',
        'enabled' => true, 'poll_interval_seconds' => 120, 'config' => [],
    ]);

    $sharp = Author::create(['platform' => 'reddit', 'username' => 'sharp', 'stats' => []]);
    $noisy = Author::create(['platform' => 'reddit', 'username' => 'noisy', 'stats' => []]);
    $quiet = Author::create(['platform' => 'reddit', 'username' => 'quiet', 'stats' => []]);

    $seedEvent = function (string $day, bool $hit) use ($run, $ticker): void {
        BacktestEvent::create([
            'backtest_run_id' => $run->id, 'ticker_id' => $ticker->id, 'symbol' => 'TRAK',
            'day' => $day, 'fired' => true, 'composite' => 0.7, 'zscore' => 3.0,
            'mentions' => 5, 'unique_authors' => 4, 'hit' => $hit, 'classification' => 'prediction',
            'entry_date' => $day, 'entry' => 1.0, 'return_1d' => 0.0, 'return_3d' => 0.0,
            'return_5d' => $hit ? 0.4 : -0.1, 'best_close_5d' => $hit ? 0.4 : 0.0,
        ]);
    };

    $seedMention = function (Author $author, string $day, int $times = 1) use ($source, $ticker): void {
        for ($i = 0; $i < $times; $i++) {
            $post = RawPost::create([
                'source_id' => $source->id, 'external_id' => 't3_'.uniqid('', true), 'kind' => 'post',
                'author_id' => $author->id, 'title' => 'x', 'body' => 'y', 'score' => 1,
                'num_comments' => 0, 'posted_at' => $day.' 12:00:00', 'ingested_at' => now(), 'meta' => [],
            ]);
            PostTickerMention::create([
                'raw_post_id' => $post->id, 'ticker_id' => $ticker->id,
                'method' => 'cashtag', 'confidence' => 1.0, 'posted_at' => $day.' 12:00:00',
            ]);
        }
    };

    // 4 candidate days: 3 hits, 1 miss.
    $seedEvent('2026-03-02', true);
    $seedEvent('2026-03-09', true);
    $seedEvent('2026-03-16', true);
    $seedEvent('2026-03-23', false);

    // Sharp posts into the 3 winners (double-posting one day counts once).
    $seedMention($sharp, '2026-03-02', times: 2);
    $seedMention($sharp, '2026-03-09');
    $seedMention($sharp, '2026-03-16');

    // Noisy posts into all 4 days.
    foreach (['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23'] as $day) {
        $seedMention($noisy, $day);
    }

    // Quiet has only 1 graded mention — below the minimum, left unscored.
    $seedMention($quiet, '2026-03-02');

    (new ScoreAuthorTrackRecords)->handle();

    // Sharp: (3+1)/(3+2) = 0.8 over n=3.
    expect($sharp->refresh()->track_record_score)->toEqualWithDelta(0.8, 0.001)
        ->and($sharp->track_record_n)->toBe(3);

    // Noisy: (3+1)/(4+2) ≈ 0.667 over n=4.
    expect($noisy->refresh()->track_record_score)->toEqualWithDelta(0.6667, 0.001)
        ->and($noisy->track_record_n)->toBe(4);

    expect($quiet->refresh()->track_record_score)->toBeNull();
});
