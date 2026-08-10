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
    public $direction;    // optional, backward compatible
    public $clientName;   // 🔥 naya - resolved Lead name, agar mila to
    public $phoneNumber;  // 🔥 naya - raw number, fallback display ke liye

    public function __construct($conversationUuid, $status, $direction = null, $clientName = null, $phoneNumber = null)
    {
        $this->conversationUuid = $conversationUuid;
        $this->status = $status;
        $this->direction = $direction;
        $this->clientName = $clientName;
        $this->phoneNumber = $phoneNumber;
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
        return [
            'conversationUuid' => $this->conversationUuid,
            'status'           => $this->status,
            'direction'        => $this->direction,
            'clientName'       => $this->clientName,   // 🔥 naya
            'phoneNumber'      => $this->phoneNumber,  // 🔥 naya
        ];
    }
}