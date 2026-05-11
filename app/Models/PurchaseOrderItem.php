<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'item_name',
        'qty',
        'price',
        'total'
    ];

    public function purchaseOrder(): BelongsTo
    {
        // 'purchase_order_id' foreign key use ho rahi hai
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
    
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}