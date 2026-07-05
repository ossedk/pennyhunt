<?php

use App\Models\Ticker;
use App\Models\TickerNews;
use App\Services\Nlp\NewsCatalystClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('classifies pending headlines in batches and stores the verdicts', function () {
    config(['pennyhunt.llm.openai_api_key' => 'sk-test', 'pennyhunt.llm.openai_model' => 'gpt-5-mini']);

    $ticker = Ticker::create(['symbol' => 'ABCD', 'name' => 'Abcd Corp', 'is_active' => true]);

    $fda = TickerNews::create([
        'ticker_id' => $ticker->id, 'external_id' => 'n1',
        'title' => 'ABCD receives FDA clearance for lead device',
        'article_url' => 'https://x.test/1', 'published_at' => now(),
    ]);
    $offering = TickerNews::create([
        'ticker_id' => $ticker->id, 'external_id' => 'n2',
        'title' => 'ABCD announces $25M registered direct offering',
        'article_url' => 'https://x.test/2', 'published_at' => now()->subHour(),
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['1' => 'fda', '2' => 'offering'])]]],
        ]),
    ]);

    $done = app(NewsCatalystClassifier::class)->classifyPending();

    expect($done)->toBe(2)
        ->and($fda->refresh()->catalyst_type)->toBe('fda')
        ->and($offering->refresh()->catalyst_type)->toBe('offering');
});

it('falls back to none on unknown types and does nothing without a key', function () {
    config(['pennyhunt.llm.openai_api_key' => null]);

    expect(app(NewsCatalystClassifier::class)->classifyPending())->toBe(0);

    config(['pennyhunt.llm.openai_api_key' => 'sk-test', 'pennyhunt.llm.openai_model' => 'gpt-5-mini']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['1' => 'made_up_type'])]]],
        ]),
    ]);

    $verdicts = app(NewsCatalystClassifier::class)->classifyBatch(['Some headline']);

    expect($verdicts[0])->toBe('none');
});
