<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name','email','phone','address','created_by'
    ];

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class,'created_by');
    }
}