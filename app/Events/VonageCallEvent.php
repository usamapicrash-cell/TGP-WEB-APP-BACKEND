<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VonageCallEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $conversationUuid;
    public $status;
    public $direction; // 🔥 naya field - optional, ab bhi backward compatible hai

    public function __construct($conversationUuid, $status, $direction = null)
    {
        $this->conversationUuid = $conversationUuid;
        $this->status = $status;
        $this->direction = $direction;
    }

    public function broadcastOn()
    {
        // React is public channel par listen karega
        return new Channel('vonage-calls');
    }

    public function broadcastAs()
    {
        // Event ka naam jo React mein use hoga
        return 'CallStatusUpdated';
    }

    public function broadcastWith()
    {
        // 🔥 Explicit payload — pehle sirf default public properties broadcast
        // hoti thin, ab direction bhi frontend ko milega (agar chahiye ho)
        return [
            'conversationUuid' => $this->conversationUuid,
            'status'           => $this->status,
            'direction'        => $this->direction,
        ];
    }
}