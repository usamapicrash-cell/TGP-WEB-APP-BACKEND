<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // ShouldBroadcastNow se instant deliver hoga
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VonageCallEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $conversationUuid;
    public $status;

    public function __construct($conversationUuid, $status)
    {
        $this->conversationUuid = $conversationUuid;
        $this->status = $status;
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
}