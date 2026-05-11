<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'lead_id',
        'amount',
        'payment_method',
        'transaction_id',
        'receipt_path',
        'payment_date',
        'internal_notes'
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    /**
     * Payment kis invoice ki hai
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Payment kis lead se aayi hai
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
    
    /**
     * Helper: Receipt ka full URL mil jaye
     */
    public function getReceiptUrlAttribute()
    {
        return $this->receipt_path ? asset('storage/' . $this->receipt_path) : null;
    }
}