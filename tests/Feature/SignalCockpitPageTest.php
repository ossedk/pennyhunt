<?php

use App\Models\Signal;
use App\Models\SignalTrade;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->ticker = Ticker::create(['symbol' => 'CKPT', 'name' => 'Cockpit Corp', 'is_active' => true]);

    $this->signal = Signal::create([
        'ticker_id' => $this->ticker->id,
        'fired_at' => now()->subDays(2),
        'composite_score' => 0.8,
        'confidence' => 0.21,
        'model_version' => 'gbm-test.1',
        'breakdown' => [
            'components' => ['acceleration' => 0.9],
            'market_gate' => ['passes' => true, 'close' => 2.5, 'volume_z' => 6.0, 'pre_return_3d' => 0.2, 'dollar_volume' => 5.0e7, 'bar_date' => now()->subDays(2)->toDateString(), 'reason' => null],
            'intel' => ['vix' => 18.0, 'short_ratio' => 0.4],
            'llm' => ['llm_conviction' => 0.6],
            'inputs' => ['mention_count' => 12, 'zscore_mentions' => 7.0, 'weighted_sentiment' => 0.1, 'unique_authors' => 8],
        ],
        'state' => 'new',
    ]);
});

it('renders the blotter index with positions, history and scoreboard', function () {
    SignalTrade::create([
        'signal_id' => $this->signal->id,
        'ticker_id' => $this->ticker->id,
        'status' => 'open',
        'tier' => 'trade',
        'confidence_at_entry' => 0.21,
        'entry_date' => now()->subDay()->toDateString(),
        'entry_price' => 2.6,
        'stop_price' => 2.34,
    ]);

    $this->get(route('signals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('signals')
            ->has('positions', 1)
            ->has('scoreboard')
            ->where('positions.0.symbol', 'CKPT')
            ->where('positions.0.status', 'open'));
});

it('renders the signal cockpit with trade plan and evidence', function () {
    $this->get(route('signals.show', $this->signal))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('signals/show')
            ->where('signal.symbol', 'CKPT')
            ->where('signal.confidence', 0.21)
            ->has('intelToday')
            ->has('mentionCurve')
            ->has('posts'));
});
