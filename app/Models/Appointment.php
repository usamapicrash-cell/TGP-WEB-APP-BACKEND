<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = ['lead_id', 'title', 'type', 'date', 'time', 'end_time', 'status', 'description', 'icon'];
    
    public function lead() {
        return $this->belongsTo(Lead::class);
    }

    public function glazier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'glazier_id');
    }
}