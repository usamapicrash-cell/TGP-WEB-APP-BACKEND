<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobActivity extends Model
{
    protected $fillable = [
        'gjob_id',
        'user_id',
        'action',
        'description',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(GJob::class, 'gjob_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
