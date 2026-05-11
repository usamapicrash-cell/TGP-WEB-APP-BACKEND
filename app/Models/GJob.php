<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GJob extends Model
{
    protected $table = 'gjobs';
    protected $fillable = [
        'lead_id','job_number','glazier_id','title','description',
        'status','work_status','progress','checklist_data','start_date','end_date'
    ];

    protected $casts = [
        'checklist_data' => 'array',
    ];

    public function attendances(): HasMany 
    { 
        return $this->hasMany(GlazierAttendance::class, 'job_id'); 
    }

    public function lead()     { return $this->belongsTo(Lead::class, 'lead_id'); }
    public function glazier()  { return $this->belongsTo(User::class, 'glazier_id'); }

    public function siteVisits() { return $this->hasMany(JobSiteVisit::class, 'gjob_id'); }
    public function media()      { return $this->hasMany(JobMedia::class, 'gjob_id'); }
    public function activities() { return $this->hasMany(JobActivity::class, 'gjob_id'); }
    public function chats()      { return $this->hasMany(JobChat::class, 'gjob_id'); }
}
