<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel; // Agar public channel chahye to Channel use krskte hain
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class SmsReceivedEvent implements ShouldBroadcast
{
    use SerializesModels;

    public $phone;
    public $message;

    public function __construct($phone, $message)
    {
        $this->phone = $phone;
        $this->message = $message;
    }

    // React side ko real-time update dene ke liye channel name
    public function broadcastOn()
    {
        // For simplicity testing me aap isko Public channel (Channel) bhi kr skte ho bina auth token ke
        return new PrivateChannel('customer-sms.' . $this->phone);
    }

    public function broadcastAs()
    {
        return 'SmsReceived';
    }
}