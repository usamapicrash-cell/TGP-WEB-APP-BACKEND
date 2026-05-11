<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'lead_id',
        'invoice_number',
        'total_amount',
        'paid_amount',
        'status',
        'due_date',
        'notes',
        'checkout_url'
    ];

    // Numbers ko decimal/float mein cast karna zaroori hai
    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount'  => 'decimal:2',
        'due_date'     => 'date',
    ];

    /**
     * Ek Invoice ke kai payments ho sakte hain (Installments)
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Invoice kis lead ki hai
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Helper: Balance calculate karne ke liye
     */
    public function getBalanceAttribute()
    {
        return $this->total_amount - $this->paid_amount;
    }
}