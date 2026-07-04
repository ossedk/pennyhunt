<?php

namespace App\Listeners;

use App\Events\SignalFired;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Evaluates every enabled alert rule against a freshly fired signal and
 * records matching in-app alert events (mail channel sends a plain
 * notification mail when configured).
 *
 * Rule kinds:
 *  - composite_threshold: signal score >= params.min_score
 *  - ticker_signal: any signal for the rule's ticker
 *  - mention_spike: mention z-score (from the signal breakdown) >= params.min_z
 */
class EvaluateAlertRules implements ShouldQueue
{
    public string $queue = 'metrics';

    public function handle(SignalFired $event): void
    {
        $signal = $event->signal->loadMissing('ticker');

        AlertRule::query()
            ->where('enabled', true)
            ->with('user')
            ->get()
            ->filter(fn (AlertRule $rule): bool => $this->matches($rule, $signal->composite_score, $signal->ticker_id, $signal->breakdown))
            ->each(function (AlertRule $rule) use ($signal): void {
                AlertEvent::create([
                    'alert_rule_id' => $rule->id,
                    'signal_id' => $signal->id,
                    'payload' => [
                        'symbol' => $signal->ticker->symbol,
                        'score' => $signal->composite_score,
                        'fired_at' => $signal->fired_at->toIso8601String(),
                        'rule' => $rule->name,
                    ],
                ]);

                $rule->forceFill(['last_triggered_at' => now()])->save();

                if ($rule->channel === 'mail' && $rule->user?->email) {
                    Mail::raw(
                        "Pennyhunt alert '{$rule->name}': {$signal->ticker->symbol} fired at score "
                        .round($signal->composite_score * 100)."/100 ({$signal->fired_at->toDateTimeString()} UTC).",
                        fn ($message) => $message
                            ->to($rule->user->email)
                            ->subject("Pennyhunt alert: {$signal->ticker->symbol}"),
                    );
                }
            });
    }

    /**
     * @param  array<string, mixed>|null  $breakdown
     */
    protected function matches(AlertRule $rule, float $score, int $tickerId, ?array $breakdown): bool
    {
        return match ($rule->kind) {
            'composite_threshold' => $score >= (float) ($rule->params['min_score'] ?? 0.7),
            'ticker_signal' => $rule->ticker_id === $tickerId,
            'mention_spike' => ($breakdown['inputs']['zscore_mentions'] ?? 0) >= (float) ($rule->params['min_z'] ?? 2.0),
            default => false,
        };
    }
}
