<?php
// app/Models/CallLog.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallLog extends Model
{
    protected $fillable = [
        'conversation_uuid', 'phone_number', 'client_name', 'direction',
        'status', 'duration', 'started_at', 'answered_at', 'ended_at', 'user_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}