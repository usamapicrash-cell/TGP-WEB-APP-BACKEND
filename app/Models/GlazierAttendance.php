<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlazierAttendance extends Model
{
    use HasFactory;

    protected $table = 'glazier_attendances';

    protected $fillable = [
        'job_id',
        'user_id',
        'action',
        'lat',
        'lng',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    /**
     * Get the job (glz_gjobs)
     */
    public function job()
    {
        return $this->belongsTo(GJob::class, 'job_id');
    }

    /**
     * Get the user (glazier)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}