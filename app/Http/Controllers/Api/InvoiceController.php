<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    /**
     * Lead ki saari invoices aur unke payments fetch karein
     */
    public function index(Lead $lead)
    {
        $invoices = Invoice::with(['payments' => function($query) {
                $query->orderBy('payment_date', 'desc');
            }])
            ->where('lead_id', $lead->id)
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($invoices);
    }

    /**
     * Manual Payment record karne ka method
     */
    public function recordManualPayment(Request $request)
    {
        $request->validate([
            'invoice_id'     => 'required|exists:invoices,id',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:Bank Transfer,Cash,Cheque,Other',
            'payment_date'   => 'required|date',
            'transaction_id' => 'nullable|string|max:100', // Bank reference number
            'receipt'        => 'nullable|image|mimes:jpeg,png,jpg,pdf|max:2048', // 2MB limit
            'internal_notes' => 'nullable|string'
        ]);

        return DB::transaction(function () use ($request) {
            $invoice = Invoice::findOrFail($request->invoice_id);
            $lead = $invoice->lead;

            // 1. Handle Receipt Upload (Bank Slip)
            $path = null;
            if ($request->hasFile('receipt')) {
                // public storage mein 'payments/slips' folder mein save hoga
                $path = $request->file('receipt')->store('payments/slips', 'public');
            }

            // 2. Create Payment Record
            $payment = Payment::create([
                'invoice_id'     => $invoice->id,
                'lead_id'        => $invoice->lead_id,
                'amount'         => $request->amount,
                'payment_method' => $request->payment_method,
                'transaction_id' => $request->transaction_id,
                'receipt_path'   => $path,
                'payment_date'   => $request->payment_date,
                'internal_notes' => $request->internal_notes,
            ]);

            // 3. Update Invoice Paid Amount and Status
            $invoice->increment('paid_amount', $request->amount);
            
            // Status update logic
            $totalPaid = $invoice->paid_amount;
            $totalAmount = $invoice->total_amount;

            if ($totalPaid >= $totalAmount) {
                $invoice->update(['status' => 'PAID']);
            } elseif ($totalPaid > 0) {
                $invoice->update(['status' => 'PARTIAL']);
            }

            // 4. Activity Log (Lead/GJob History)
            if ($lead && $lead->gjob) {
                $lead->gjob->activities()->create([
                    'user_id'     => Auth::id(),
                    'action'      => 'Payment Recorded',
                    'description' => "Manual payment of $" . number_format($request->amount, 2) . " received via {$request->payment_method} for Invoice #{$invoice->invoice_number}.",
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully!',
                'payment' => $payment,
                'invoice_status' => $invoice->status
            ]);
        });
    }
}