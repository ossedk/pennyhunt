<?php

namespace App\Events;

use App\Models\Signal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class SignalFired implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public Signal $signal) {}

    public function broadcastOn(): Channel
    {
        return new Channel('pennyhunt.signals');
    }

    public function broadcastAs(): string
    {
        return 'signal.fired';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->signal->id,
            'ticker' => $this->signal->ticker->symbol,
            'score' => $this->signal->composite_score,
            'fired_at' => $this->signal->fired_at->toIso8601String(),
        ];
    }
}
