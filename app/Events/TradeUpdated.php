<?php

namespace App\Events;

use App\Models\SignalTrade;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Paper-trade lifecycle broadcast: created / opened / closed / cancelled / quote. */
class TradeUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public SignalTrade $trade, public string $transition) {}

    public function broadcastOn(): Channel
    {
        return new Channel('pennyhunt.trades');
    }

    public function broadcastAs(): string
    {
        return 'trade.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->trade->id,
            'signal_id' => $this->trade->signal_id,
            'symbol' => $this->trade->ticker?->symbol,
            'status' => $this->trade->status,
            'transition' => $this->transition,
            'net_return' => $this->trade->net_return,
            'unrealized_return' => $this->trade->unrealized_return,
        ];
    }
}
