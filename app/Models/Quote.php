<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    
    protected $fillable = [
        'lead_id',
        'quote_number',
        'subtotal',
        'labour_total',
        'total_amount',
        'status',
        'internal_notes',
    ];

    public function items() {
        return $this->hasMany(QuoteItem::class);
    }

    public function lead() {
        return $this->belongsTo(Lead::class);
    }
}
