<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number', 
        'supplier_id', 
        'lead_id',      
        'status',       
        'payment_status', 
        'paid_amount',  
        'sub_total', 
        'tax', 
        'total',
        'drawing_data', // Sketch paths save karne ke liye
        'attachments',  // Manual files paths save karne ke liye (Ye missing tha)
        'notes', 
        'created_by'
    ];

    protected $casts = [
        'drawing_data' => 'array',
        'attachments' => 'array',
    ];

    // Relations
    public function lead(): BelongsTo 
    { 
        return $this->belongsTo(Lead::class); 
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}