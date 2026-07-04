<?php

use App\Events\SignalFired;
use App\Listeners\EvaluateAlertRules;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Signal;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->ticker = Ticker::create(['symbol' => 'PUMP', 'name' => 'Pump Corp', 'is_active' => true]);

    $this->signal = Signal::create([
        'ticker_id' => $this->ticker->id,
        'fired_at' => now(),
        'composite_score' => 0.82,
        'breakdown' => ['inputs' => ['zscore_mentions' => 3.4]],
        'state' => 'new',
    ]);
});

it('records alert events for matching rules', function () {
    $matching = AlertRule::create([
        'user_id' => $this->user->id, 'name' => 'High score', 'kind' => 'composite_threshold',
        'params' => ['min_score' => 0.8], 'channel' => 'in_app', 'enabled' => true,
    ]);

    AlertRule::create([
        'user_id' => $this->user->id, 'name' => 'Too strict', 'kind' => 'composite_threshold',
        'params' => ['min_score' => 0.9], 'channel' => 'in_app', 'enabled' => true,
    ]);

    AlertRule::create([
        'user_id' => $this->user->id, 'name' => 'Disabled', 'kind' => 'composite_threshold',
        'params' => ['min_score' => 0.1], 'channel' => 'in_app', 'enabled' => false,
    ]);

    (new EvaluateAlertRules)->handle(new SignalFired($this->signal));

    expect(AlertEvent::count())->toBe(1)
        ->and(AlertEvent::first()->alert_rule_id)->toBe($matching->id)
        ->and(AlertEvent::first()->payload['symbol'])->toBe('PUMP')
        ->and($matching->refresh()->last_triggered_at)->not->toBeNull();
});

it('matches ticker and mention-spike rules', function () {
    AlertRule::create([
        'user_id' => $this->user->id, 'name' => 'Watch PUMP', 'kind' => 'ticker_signal',
        'ticker_id' => $this->ticker->id, 'params' => [], 'channel' => 'in_app', 'enabled' => true,
    ]);

    AlertRule::create([
        'user_id' => $this->user->id, 'name' => 'Spikes', 'kind' => 'mention_spike',
        'params' => ['min_z' => 3.0], 'channel' => 'in_app', 'enabled' => true,
    ]);

    AlertRule::create([
        'user_id' => $this->user->id, 'name' => 'Bigger spikes only', 'kind' => 'mention_spike',
        'params' => ['min_z' => 5.0], 'channel' => 'in_app', 'enabled' => true,
    ]);

    (new EvaluateAlertRules)->handle(new SignalFired($this->signal));

    expect(AlertEvent::count())->toBe(2);
});
