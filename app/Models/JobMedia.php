<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobMedia extends Model
{
    // Aapke error ke mutabiq table name 'glz_job_media' hai
    protected $table = 'job_media';

    protected $fillable = [
        'gjob_id',
        'created_by', // Error yahan tha, ye missing tha
        'type',
        'work_stage', // Ye naya column
        'file_path',
    ];

    protected $appends = ['file_url'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(GJob::class, 'gjob_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // URL accessor
    public function getFileUrlAttribute()
    {
        return $this->file_path ? asset('storage/' . $this->file_path) : null;
    }
}