<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobChat extends Model
{
    protected $fillable = [
        'gjob_id',
        'sender_id',
        'message',
        'attachment',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(GJob::class, 'gjob_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
