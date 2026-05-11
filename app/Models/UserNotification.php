<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    // Table name agar aapne migrations mein 'user_notifications' rakha hai
    protected $table = 'user_notifications';

    protected $fillable = [
        'title',
        'msg',
        'type',
        'user_id',
        'from_user_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /**
     * Notification kis user ke liye hai.
     */
    public function recipient()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Notification kisne bheji (0 means System).
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }
}