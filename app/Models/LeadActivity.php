<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    protected $fillable = ['lead_id', 'user_id', 'type', 'content'];

    // Kisne likha
    public function user() {
        return $this->belongsTo(User::class);
    }
}