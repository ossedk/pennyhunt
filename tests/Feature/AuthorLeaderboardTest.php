<?php

use App\Models\Author;
use App\Models\AuthorCall;
use App\Models\AuthorLeaderboard;
use App\Models\RawPost;
use App\Models\Source;
use App\Models\Ticker;
use App\Models\User;
use App\Services\Metrics\AuthorCallGrader;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Creates an author with a recent post (the board only ranks active authors). */
function voiceAuthor(string $username, string $platform = 'reddit', ?CarbonInterface $lastPostAt = null): Author
{
    $author = Author::create(['platform' => $platform, 'username' => $username, 'stats' => [], 'karma' => 5000]);

    $source = Source::firstOrCreate(
        ['key' => $platform.':test'],
        ['type' => $platform, 'name' => $platform.' test', 'enabled' => true, 'poll_interval_seconds' => 120, 'config' => []],
    );

    RawPost::create([
        'source_id' => $source->id,
        'external_id' => 'act_'.$username,
        'kind' => 'post',
        'author_id' => $author->id,
        'body' => 'still around',
        'score' => 10,
        'num_comments' => 0,
        'posted_at' => $lastPostAt ?? now()->subDay(),
        'ingested_at' => now(),
        'meta' => [],
    ]);

    return $author;
}

function gradedCall(Author $author, Ticker $ticker, string $outcome, float $peak, ?float $day5 = null): AuthorCall
{
    return AuthorCall::create([
        'author_id' => $author->id,
        'ticker_id' => $ticker->id,
        'called_at' => now()->subDays(rand(10, 60)),
        'entry_date' => now()->subDays(9)->toDateString(),
        'entry_price' => 1.00,
        'peak_return' => $peak,
        'day5_return' => $day5 ?? $peak / 2,
        'outcome' => $outcome,
        'graded_at' => now(),
    ]);
}

it('ranks by wilson lower bound so a proven author beats a lucky small sample', function () {
    config(['pennyhunt.voices.min_calls' => 3]);

    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);

    // Lucky: 3-for-3 (raw hitrate 100%, but tiny sample).
    $lucky = voiceAuthor('lucky_larry');
    foreach (range(1, 3) as $i) {
        gradedCall($lucky, $ticker, 'win', 0.45);
    }

    // Proven: 15-for-20 (raw hitrate 75%, large sample).
    $proven = voiceAuthor('proven_paula');
    foreach (range(1, 15) as $i) {
        gradedCall($proven, $ticker, 'win', 0.50);
    }
    foreach (range(1, 5) as $i) {
        gradedCall($proven, $ticker, 'flat', 0.05);
    }

    app(AuthorCallGrader::class)->snapshot(now()->startOfWeek());

    $board = AuthorLeaderboard::query()->orderBy('rank')->with('author')->get();

    expect($board)->toHaveCount(2)
        ->and($board[0]->author->username)->toBe('proven_paula')
        ->and($board[0]->hit_rate)->toEqual(0.75)
        ->and($board[1]->author->username)->toBe('lucky_larry')
        ->and($board[1]->hit_rate)->toEqual(1.0)
        ->and($board[0]->wilson_lb)->toBeGreaterThan($board[1]->wilson_lb);
});

it('excludes authors below the minimum call count and fills detail fields', function () {
    config(['pennyhunt.voices.min_calls' => 5]);

    $abcd = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);
    $wxyz = Ticker::create(['symbol' => 'WXYZ', 'name' => 'Wxyz Inc', 'is_active' => true]);

    $small = voiceAuthor('too_small');
    gradedCall($small, $abcd, 'win', 0.80);

    $ranked = voiceAuthor('ranked_rita');
    foreach (range(1, 3) as $i) {
        gradedCall($ranked, $abcd, 'win', 0.40);
    }
    gradedCall($ranked, $wxyz, 'win', 0.90); // best call
    gradedCall($ranked, $wxyz, 'loss', -0.05, -0.30);

    app(AuthorCallGrader::class)->snapshot(now()->startOfWeek());

    $board = AuthorLeaderboard::query()->get();

    expect($board)->toHaveCount(1);

    $row = $board->first();

    expect($row->author_id)->toBe($ranked->id)
        ->and($row->calls)->toBe(5)
        ->and($row->wins)->toBe(4)
        ->and($row->losses)->toBe(1)
        ->and($row->best_call['symbol'])->toBe('WXYZ')
        ->and($row->best_call['peak_return'])->toEqual(0.9)
        ->and(collect($row->top_tickers)->pluck('symbol')->all())->toBe(['ABCD', 'WXYZ'])
        ->and($row->recent_calls)->toHaveCount(5);
});

it('rebuilding the same week replaces the snapshot instead of duplicating', function () {
    config(['pennyhunt.voices.min_calls' => 1]);

    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);
    $author = voiceAuthor('repeat_rebuild');
    gradedCall($author, $ticker, 'win', 0.35);

    $grader = app(AuthorCallGrader::class);
    $grader->snapshot(now()->startOfWeek());
    $grader->snapshot(now()->startOfWeek());

    expect(AuthorLeaderboard::count())->toBe(1);
});

it('excludes dormant authors and ranks platforms independently', function () {
    config(['pennyhunt.voices.min_calls' => 1, 'pennyhunt.voices.min_calls_twitter' => 1, 'pennyhunt.voices.active_days' => 21]);

    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);

    $activeReddit = voiceAuthor('active_red');
    gradedCall($activeReddit, $ticker, 'win', 0.5);

    // Great record, but hasn't posted in 60 days — must not rank.
    $dormant = voiceAuthor('gone_guy', 'reddit', now()->subDays(60));
    gradedCall($dormant, $ticker, 'win', 0.9);

    $activeTwitter = voiceAuthor('active_bird', 'twitter');
    gradedCall($activeTwitter, $ticker, 'win', 0.4);

    app(AuthorCallGrader::class)->snapshot(now()->startOfWeek());

    $board = AuthorLeaderboard::query()->with('author')->get();

    expect($board->pluck('author.username')->sort()->values()->all())->toBe(['active_bird', 'active_red'])
        // Each platform board ranks from 1 independently.
        ->and($board->firstWhere('platform', 'reddit')->rank)->toBe(1)
        ->and($board->firstWhere('platform', 'twitter')->rank)->toBe(1);
});

it('renders the voices page with the latest snapshot', function () {
    config(['pennyhunt.voices.min_calls' => 1]);

    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);
    $author = voiceAuthor('page_pete');
    gradedCall($author, $ticker, 'win', 0.55);

    app(AuthorCallGrader::class)->snapshot(now()->startOfWeek());

    $this->actingAs(User::factory()->create())
        ->get('/voices')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('voices')
            ->has('boards.reddit', 1)
            ->where('boards.reddit.0.author.username', 'page_pete')
            ->where('boards.reddit.0.wins', 1)
            ->has('boards.twitter', 0));
});
