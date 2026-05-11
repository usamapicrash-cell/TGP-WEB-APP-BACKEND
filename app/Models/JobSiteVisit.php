<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobSiteVisit extends Model
{
    protected $fillable = [
        'gjob_id',
        'visit_date',
        'notes',
    ];

    protected $casts = [
        'visit_date' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(GJob::class, 'gjob_id');
    }
}
