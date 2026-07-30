<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Email;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendPaymentLinkMail;

class HelcimController extends Controller
{
    public function generateLink(Request $request, Lead $lead)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:255'
        ]);

        try {
            // 1. Incremental Invoice Number Logic (e.g., INV00001)
            $lastInvoice = Invoice::where('invoice_number', 'LIKE', 'INV%')
                ->latest('id')
                ->first();

            if ($lastInvoice) {
                $number = (int) substr($lastInvoice->invoice_number, 3);
                $newNumber = $number + 1;
            } else {
                $newNumber = 1;
            }

            $invoiceNum = 'INV' . str_pad($newNumber, 5, '0', STR_PAD_LEFT);

            // 2. Helcim API Call to get Checkout Token
            $response = Http::withHeaders([
                'api-token' => env('HELCIM_KEY'),
                'Accept' => 'application/json',
                'idempotency-key' => Str::uuid()->toString(),
            ])->post('https://api.helcim.com/v2/helcim-pay/initialize', [
                'test' => (int) env('HELCIM_TEST_MODE', 0),
                'paymentType' => 'purchase',
                'terminalOrderId' => $invoiceNum,
                'amount' => (float) $request->amount,
                'currency' => 'USD',
                'description' => "Invoice:{$invoiceNum}",
                'customData' => [
                    'my_invoice_id' => $invoiceNum, 
                ],
            ]);

            $result = $response->json();
            Log::warning('result found', [
                'result' => $result
            ]);
            if ($response->successful() && isset($result['checkoutToken'])) {
                $finalUrl = "https://secure.helcim.app/helcim-pay/" . $result['checkoutToken'];

                // 3. Database mein Invoice Create karein (With checkout_url for resending)
                $invoice = Invoice::create([
                    'lead_id' => $lead->id,
                    'invoice_number' => $invoiceNum,
                    'helcim_invoice_number' => $result['invoiceNumber'] ?? null,
                    'helcim_checkout_token' => $result['checkoutToken'], // <-- Add this line
                    'total_amount' => $request->amount,
                    'paid_amount' => 0,
                    'status' => 'DUE',
                    'due_date' => now()->addDays(7),
                    'notes' => $request->description,
                    'checkout_url' => $finalUrl, // Store link for resending
                ]);

                if ($lead->email) {
                    $mailable = new SendPaymentLinkMail($invoice);
                    
                    // 1. Send Actual Email
                    Mail::to($lead->email)->send($mailable);

                    // 2. Render HTML for Database Logging
                    $htmlBody = view('emails.payment_link', [
                        'invoice' => $invoice,
                        'clientName' => $lead->client_name,
                        'amount' => $invoice->total_amount,
                        'url' => $invoice->checkout_url
                    ])->render();

                    // 3. Save to Emails Table
                    Email::create([
                        'sender'    => env('SENDER_EMAIL', 'sales@theglasspeople.com'),
                        'receiver'  => $lead->email,
                        'subject'   => "Payment Request for Invoice #{$invoiceNum}",
                        'html_body' => $htmlBody,
                        'type'      => 'sent',
                        'is_read'   => true
                    ]);
                }

                if ($lead->gjob) {
                    $lead->gjob->activities()->create([
                        'user_id'     => auth()->id(),
                        'action'      => 'Payment Link Generated',
                        'description' => "Helcim payment link ({$invoiceNum}) generated for amount: $" . $request->amount,
                    ]);
                }
                Log::info("Helcim Link Success", ['invoice' => $invoiceNum, 'url' => $finalUrl]);

                return response()->json([
                    'success' => true,
                    'checkout_url' => $finalUrl,
                    'checkout_token' => $result['checkoutToken'],
                    'invoice' => $invoice
                ]);
            }

            Log::error("Helcim API Failed", ['status' => $response->status(), 'body' => $response->body()]);
            return response()->json(['message' => 'Helcim Error', 'details' => $result], 400);

        } catch (\Exception $e) {
            Log::error("Critical Error", ['msg' => $e->getMessage()]);
            return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function recordManual(Request $request)
    {
        // Ab invoice_id required nahi hai, balki lead_id chahiye
        $request->validate([
            'lead_id'        => 'required|exists:leads,id', 
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'payment_date'   => 'required|date',
            'receipt'        => 'nullable|image|mimes:jpg,jpeg,png|max:2048'
        ]);

        return DB::transaction(function () use ($request) {
            $lead = Lead::findOrFail($request->lead_id);

            // 1. Incremental Invoice Number Logic (Same as Helcim)
            $lastInvoice = Invoice::where('invoice_number', 'LIKE', 'INV%')
                ->latest('id')
                ->first();

            $newNumber = $lastInvoice ? ((int) substr($lastInvoice->invoice_number, 3)) + 1 : 1;
            $invoiceNum = 'INV' . str_pad($newNumber, 5, '0', STR_PAD_LEFT);

            // 2. Auto Create Invoice
            $invoice = Invoice::create([
                'lead_id'        => $lead->id,
                'invoice_number' => $invoiceNum,
                'total_amount'   => $request->amount, // Manual payment mein hum assume kar rahe hain jitni payment utni invoice
                'paid_amount'    => $request->amount,
                'status'         => 'PAID', // Kyunke payment sath hi ho rahi hai
                'due_date'       => $request->payment_date,
                'notes'          => $request->internal_notes ?? 'Auto-generated invoice for manual payment',
            ]);

            // 3. Image Upload Logic
            $receiptPath = null;
            if ($request->hasFile('receipt')) {
                $receiptPath = $request->file('receipt')->store('receipts', 'public');
            }

            // 4. Attach Payment to this new Invoice
            $invoice->payments()->create([
                'lead_id'        => $lead->id,
                'amount'         => $request->amount,
                'payment_method' => $request->payment_method,
                'transaction_id' => $request->transaction_id,
                'payment_date'   => $request->payment_date,
                'receipt_path'   => $receiptPath,
                'internal_notes' => $request->internal_notes,
            ]);

            // 5. Activity Log
            if ($lead->gjob) {
                $lead->gjob->activities()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'Manual Payment & Invoice Created',
                    'description' => "Manual payment of $" . $request->amount . " received. Auto-generated invoice: {$invoiceNum}.",
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice generated and payment recorded successfully',
                'invoice' => $invoice
            ]);
        });
    }

    public function resendHelcimLink($id)
    {
        try {
            $invoice = Invoice::with('lead')->findOrFail($id);

            if (!$invoice->checkout_url) {
                return response()->json(['message' => 'No Helcim link found for this invoice.'], 404);
            }

           if ($invoice->lead && $invoice->lead->email) {
                $lead = $invoice->lead;
                $mailable = new SendPaymentLinkMail($invoice);
                
                Mail::to($lead->email)->send($mailable);

                // Log the resent email
                $htmlBody = view('emails.payment_link', [
                    'invoice' => $invoice,
                    'clientName' => $lead->client_name,
                    'amount' => $invoice->total_amount,
                    'url' => $invoice->checkout_url
                ])->render();

                 Email::create([
                    'sender'    => env('SENDER_EMAIL', 'sales@theglasspeople.com'),
                    'receiver'  => $lead->email,
                    'subject'   => "RESEND: Payment Request for Invoice #{$invoice->invoice_number}",
                    'html_body' => $htmlBody,
                    'type'      => 'sent',
                    'is_read'   => true
                ]);
            }

            // Yahan aap Email ya SMS logic daal sakte hain
            // Filhal hum activity log record kar rahe hain aur success bhej rahe hain
            if ($invoice->lead && $invoice->lead->gjob) {
                $invoice->lead->gjob->activities()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'Payment Link Resent',
                    'description' => "Payment link for {$invoice->invoice_number} was resent/accessed again.",
                ]);
            }

            return response()->json([
                'success' => true, 
                'checkout_url' => $invoice->checkout_url,
                'message' => 'Link retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

   
    public function downloadPDF($id)
    {
        try {
            $invoice = Invoice::with(['lead', 'payments'])->findOrFail($id);
            
            // Lead ko alag variable mein nikaal lein
            $lead = $invoice->lead;

            if (!view()->exists('pdfs.invoice')) {
                return response()->json(['error' => 'View not found'], 404);
            }

            // Dono variables compact mein pass karein
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.invoice', compact('invoice', 'lead'));
            
            return $pdf->stream("invoice_{$invoice->invoice_number}.pdf");

        } catch (\Exception $e) {
            \Log::error("PDF Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

 public function handleWebhook(Request $request)
{
    $payload = $request->all();
    Log::info('Helcim Webhook Received', $payload);

    try {
        $transactionId = $payload['id'] ?? null;
        if (!$transactionId) {
            return response()->json(['status' => 'no_transaction_id'], 200);
        }

        // Fetch transaction details from Helcim API
        $response = Http::withHeaders([
            'api-token' => env('HELCIM_KEY'),
            'Accept' => 'application/json',
        ])->get("https://api.helcim.com/v2/card-transactions/{$transactionId}");

        if (!$response->successful()) {
            Log::error('Failed to fetch transaction details', ['response' => $response->body()]);
            return response()->json(['status' => 'api_failed'], 200);
        }

        $transaction = $response->json();
        Log::info('Helcim Transaction Details', $transaction);

        $status = strtolower($transaction['status'] ?? '');
        if (!in_array($status, ['approved', 'completed'])) {
            return response()->json(['status' => 'not_approved'], 200);
        }

        $helcimInvNum = $transaction['invoiceNumber'] ?? null;
        $terminalOrderId = $transaction['terminalOrderId'] ?? null;
        $checkoutToken = $transaction['checkoutToken'] ?? null;

        // Smart & Precise Invoice Matching
        $invoice = Invoice::query()
            ->when($checkoutToken, function ($q) use ($checkoutToken) {
                $q->where('helcim_checkout_token', $checkoutToken)
                  ->orWhere('checkout_url', 'LIKE', "%{$checkoutToken}%");
            })
            ->when($terminalOrderId, function ($q) use ($terminalOrderId) {
                $q->orWhere('invoice_number', $terminalOrderId);
            })
            ->when($helcimInvNum, function ($q) use ($helcimInvNum) {
                $q->orWhere('helcim_invoice_number', $helcimInvNum);
            })
            ->first();

        if (!$invoice) {
            Log::warning('Invoice not found for transaction', [
                'transactionId' => $transactionId,
                'invoiceNumber' => $helcimInvNum
            ]);
            return response()->json(['status' => 'invoice_not_found'], 200);
        }

        // Duplicate payment protection
        if ($invoice->status === 'PAID') {
            Log::info("Invoice {$invoice->invoice_number} is already paid.");
            return response()->json(['status' => 'already_paid'], 200);
        }

        $amount = $transaction['amount'] ?? $invoice->total_amount;

        DB::transaction(function () use ($invoice, $amount, $transactionId, $helcimInvNum) {
            $invoice->update([
                'status' => 'PAID',
                'paid_amount' => $amount,
                'helcim_invoice_number' => $helcimInvNum ?? $invoice->helcim_invoice_number
            ]);

            $invoice->payments()->create([
                'lead_id' => $invoice->lead_id,
                'amount' => $amount,
                'payment_method' => 'Helcim',
                'transaction_id' => $transactionId,
                'payment_date' => now(),
            ]);
        });

        // Safe Job Activity Logging (Prevents user_id null error)
        try {
            if ($invoice->lead && $invoice->lead->gjob) {
                // Agar auth user na ho toh System ID (1) use hoga
                $userId = auth()->id() ?? 1; 

                $invoice->lead->gjob->activities()->create([
                    'user_id' => $userId,
                    'action' => 'Helcim Payment Received',
                    'description' => "Invoice {$invoice->invoice_number} paid successfully via Helcim.",
                ]);
            }
        } catch (\Exception $logEx) {
            Log::warning('Job Activity log failed', ['error' => $logEx->getMessage()]);
        }

        Log::info("Webhook Success: Invoice {$invoice->invoice_number} marked as PAID.");
        return response()->json(['status' => 'success'], 200);

    } catch (\Exception $e) {
        Log::error('Webhook Exception', ['message' => $e->getMessage()]);
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}
}