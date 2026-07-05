<?php

use App\Models\Signal;
use App\Models\Ticker;
use App\Models\TickerMetric;
use App\Models\User;
use App\Services\Nlp\SignalBriefWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function briefSignal(): Signal
{
    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);

    return Signal::create([
        'ticker_id' => $ticker->id,
        'fired_at' => now()->subHours(3),
        'composite_score' => 0.72,
        'confidence' => 0.13,
        'state' => 'new',
        'breakdown' => ['components' => ['acceleration' => 0.8], 'intel' => ['vix' => 18.2]],
    ]);
}

it('writes and stores a validated signal brief', function () {
    config(['pennyhunt.llm.openai_api_key' => 'sk-test', 'pennyhunt.llm.openai_model' => 'gpt-5-mini']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'summary' => 'Fired on accelerating buzz with volume confirmation.',
                'watch_for' => ['Volume holding above 3x average', 'Sector peers moving'],
                'invalidation' => 'Mention collapse below baseline.',
                'risk' => 'Active shelf means rallies can be sold into.',
            ])]]],
        ]),
    ]);

    $signal = briefSignal();
    $brief = app(SignalBriefWriter::class)->write($signal);

    expect($brief['summary'])->toContain('accelerating buzz')
        ->and($signal->refresh()->llm_brief['watch_for'])->toHaveCount(2)
        ->and($signal->llm_brief_at)->not->toBeNull();
});

it('serves hourly swarm data with posts and window', function () {
    $signal = briefSignal();

    TickerMetric::create([
        'ticker_id' => $signal->ticker_id,
        'interval' => '1h',
        'bucket_start' => now()->subHours(4)->startOfHour(),
        'mention_count' => 12,
        'unique_authors' => 8,
        'weighted_sentiment' => 0.4,
        'zscore_mentions' => 3.2,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson("/signals/{$signal->id}/swarm")
        ->assertOk()
        ->assertJsonPath('symbol', 'ABCD')
        ->assertJsonPath('threshold_z', fn ($v) => (float) $v === 3.0)
        ->assertJsonPath('hours.0.mentions', 12)
        ->assertJsonStructure(['window' => ['start', 'end'], 'live', 'hours', 'posts']);
});
