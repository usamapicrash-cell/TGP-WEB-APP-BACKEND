<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadType extends Model
{
    protected $fillable = ['name', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leads()
    {
        return $this->hasMany(Lead::class, 'type');
    }
}
