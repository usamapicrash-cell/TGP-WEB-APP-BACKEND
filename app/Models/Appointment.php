<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = ['lead_id', 'title', 'type', 'date', 'time', 'end_time', 'status', 'description', 'icon'];
    
    public function lead() {
        return $this->belongsTo(Lead::class);
    }
}