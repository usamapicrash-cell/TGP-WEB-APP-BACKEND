<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by',
        'client_name',
        'care_of', // <-- Added
        'email',
        'type',
        'source',
        'status',
        'value',
        'date',
        'company',
        'address',
        'job_address',
        'phone',
        'lead_number',
        'order_no',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leadType()
    {
        return $this->belongsTo(LeadType::class, 'type');
    }

    public function quotes() {
        return $this->hasMany(Quote::class);
    }

    public function activeQuote() {
        return $this->hasOne(Quote::class)->latestOfMany();
    }

    public function gjob()
    {
        // A Lead has one GJob
        return $this->hasOne(GJob::class, 'lead_id');
    }

    public function payments()   { return $this->hasMany(Payment::class, 'lead_id'); }

    // Sirf Approved Quote fetch karne ke liye helper relation
    public function approvedQuote()
    {
        return $this->hasOne(Quote::class)->where('status', 'approved'); // ya 'approved' (apne DB status ke mutabiq)
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    // Direct Lead se PO ke zariye unique Suppliers nikalne ke liye
    public function suppliers(): HasManyThrough
    {
        return $this->hasManyThrough(
            Supplier::class,
            PurchaseOrder::class,
            'lead_id',     // PurchaseOrder table me Foreign key
            'id',          // Supplier table me Primary key
            'id',          // Lead table me Primary key
            'supplier_id'  // PurchaseOrder table me Foreign key
        )->distinct();
    }

    protected static function boot()
    {
        parent::boot();

        // ✅ BEFORE INSERT (important for order_no)
        static::creating(function ($lead) {

            $lastLead = self::whereNotNull('order_no')
                ->orderBy('id', 'desc')
                ->first();

            if ($lastLead && is_numeric($lastLead->order_no)) {
                $nextNumber = (int)$lastLead->order_no + 1;
            } else {
                $nextNumber = 11581;
            }

            $lead->order_no = $nextNumber;
        });

        // ✅ AFTER INSERT
        static::created(function ($lead) {

            $lead->lead_number = 'LD-' . $lead->order_no;
            $lead->saveQuietly();

            // GJob create
            $lead->gjob()->create([
                'title'       => 'Job for ' . $lead->client_name,
                'description' => 'Initial job created from lead.',
                'status'      => 'lead',
                'progress'    => 0,
                'start_date'  => $lead->date ?? now(),
            ]);
        });
    }

}
