<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Email;
use App\Models\UserNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendPaymentLinkMail;

class HelcimController extends Controller
{
    /**
     * Helper function to generate custom invoice number based on Lead/Job order number with suffix (A, B, C...).
     */
    private function generateInvoiceNumber(Lead $lead): string
    {
        // Lead se order_no ya lead_number (numeric part) lein
        $baseNumber = $lead->order_no 
            ?? (string) str_replace('LD-', '', $lead->lead_number ?? '')
            ?? (string) $lead->id;

        // Is Lead par pehle se bani hui invoices ka count check karein
        $existingCount = Invoice::where('lead_id', $lead->id)->count();

        // Pehli invoice par koi suffix nahi hoga, 2nd par 'A' (index 0), 3rd par 'B' (index 1)...
        if ($existingCount === 0) {
            $suffix = '';
        } else {
            $suffix = range('A', 'Z')[$existingCount - 1] ?? ('_' . $existingCount);
        }

        return "{$baseNumber}{$suffix}";
    }

    public function generateLink(Request $request, Lead $lead)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:255'
        ]);

        try {
            // Dynamic Invoice Number Generation (INV Hataya gaya)
            $invoiceNum = $this->generateInvoiceNumber($lead);

            // Helcim API Call to get Checkout Token
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
                    'my_invoice_number' => $invoiceNum,
                ],
            ]);

            $result = $response->json();
            Log::info('Helcim Initialize Response', ['result' => $result]);

            if ($response->successful() && isset($result['checkoutToken'])) {
                $checkoutToken = $result['checkoutToken'];
                $finalUrl = "https://secure.helcim.app/helcim-pay/" . $checkoutToken;

                // Database me Invoice Store karein
                $invoice = Invoice::create([
                    'lead_id' => $lead->id,
                    'invoice_number' => $invoiceNum,
                    'helcim_invoice_number' => $result['invoiceNumber'] ?? null,
                    'helcim_checkout_token' => $checkoutToken,
                    'total_amount' => $request->amount,
                    'paid_amount' => 0,
                    'status' => 'DUE',
                    'due_date' => now()->addDays(7),
                    'notes' => $request->description,
                    'checkout_url' => $finalUrl,
                ]);

                if ($lead->email) {
                    $mailable = new SendPaymentLinkMail($invoice);
                    Mail::to($lead->email)->send($mailable);

                    $htmlBody = view('emails.payment_link', [
                        'invoice' => $invoice,
                        'clientName' => $lead->client_name,
                        'amount' => $invoice->total_amount,
                        'url' => $invoice->checkout_url
                    ])->render();

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
                        'user_id'     => auth()->id() ?? 1,
                        'action'      => 'Payment Link Generated',
                        'description' => "Helcim payment link ({$invoiceNum}) generated for amount: $" . $request->amount,
                    ]);
                }
                Log::info("Helcim Link Success", ['invoice' => $invoiceNum, 'url' => $finalUrl]);

                return response()->json([
                    'success' => true,
                    'checkout_url' => $finalUrl,
                    'checkout_token' => $checkoutToken,
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
        $request->validate([
            'lead_id'        => 'required|exists:leads,id', 
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'payment_date'   => 'required|date',
            'receipt'        => 'nullable|image|mimes:jpg,jpeg,png|max:2048'
        ]);

        return DB::transaction(function () use ($request) {
            $lead = Lead::findOrFail($request->lead_id);

            // Dynamic Invoice Number Generation (INV Hataya gaya)
            $invoiceNum = $this->generateInvoiceNumber($lead);

            $invoice = Invoice::create([
                'lead_id'        => $lead->id,
                'invoice_number' => $invoiceNum,
                'total_amount'   => $request->amount,
                'paid_amount'    => $request->amount,
                'status'         => 'PAID',
                'due_date'       => $request->payment_date,
                'notes'          => $request->internal_notes ?? 'Auto-generated invoice for manual payment',
            ]);

            $receiptPath = null;
            if ($request->hasFile('receipt')) {
                $receiptPath = $request->file('receipt')->store('receipts', 'public');
            }

            $invoice->payments()->create([
                'lead_id'        => $lead->id,
                'amount'         => $request->amount,
                'payment_method' => $request->payment_method,
                'transaction_id' => $request->transaction_id,
                'payment_date'   => $request->payment_date,
                'receipt_path'   => $receiptPath,
                'internal_notes' => $request->internal_notes,
            ]);

            if ($lead->gjob) {
                $lead->gjob->activities()->create([
                    'user_id'     => auth()->id() ?? 1,
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

            if ($invoice->lead && $invoice->lead->gjob) {
                $invoice->lead->gjob->activities()->create([
                    'user_id'     => auth()->id() ?? 1,
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
            // 1. Nested quote aur items load karein
            $invoice = Invoice::with(['lead.quote.items', 'payments'])->findOrFail($id);
            $lead = $invoice->lead;

            // 2. Lead ki Quote se items extract karein (safe fallback list agar quote/items null hon)
            $items = optional($lead->quote)->items ?? collect();

            if (!view()->exists('pdfs.invoice')) {
                return response()->json(['error' => 'View not found'], 404);
            }

            // 3. 'items' variable ko view data mein pass karein
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.invoice', compact('invoice', 'lead', 'items'));
            return $pdf->stream("invoice_{$invoice->invoice_number}.pdf");

        } catch (\Exception $e) {
            Log::error("PDF Error: " . $e->getMessage());
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
            $customInvNum = $transaction['customData']['my_invoice_number'] ?? null;

            // Invoice Lookup
            $invoice = Invoice::query()
                ->when($customInvNum, function ($q) use ($customInvNum) {
                    $q->where('invoice_number', $customInvNum);
                })
                ->when($terminalOrderId, function ($q) use ($terminalOrderId) {
                    $q->orWhere('invoice_number', $terminalOrderId);
                })
                ->when($checkoutToken, function ($q) use ($checkoutToken) {
                    $q->orWhere('helcim_checkout_token', $checkoutToken)
                      ->orWhere('checkout_url', 'LIKE', "%{$checkoutToken}%");
                })
                ->when($helcimInvNum, function ($q) use ($helcimInvNum) {
                    $q->orWhere('helcim_invoice_number', $helcimInvNum);
                })
                ->first();

            // Fallback matching
            if (!$invoice && isset($transaction['amount'])) {
                $invoice = Invoice::where('status', 'DUE')
                    ->where('total_amount', $transaction['amount'])
                    ->latest('id')
                    ->first();
            }

            if (!$invoice) {
                Log::warning('Invoice not found for transaction', [
                    'transactionId' => $transactionId,
                    'invoiceNumber' => $helcimInvNum
                ]);
                return response()->json(['status' => 'invoice_not_found'], 200);
            }

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

            try {
                $clientName = $invoice->lead ? $invoice->lead->client_name : 'Customer';
                $title = "Payment Received - Invoice #{$invoice->invoice_number}";
                $msg = "Payment of \${$amount} received for Invoice #{$invoice->invoice_number} ({$clientName}) via Helcim.";

                $adminUsers = User::where('role', 'admin')->get();

                if ($adminUsers->isEmpty()) {
                    $adminUsers = User::where('id', 3)->get();
                }

                foreach ($adminUsers as $admin) {
                    UserNotification::create([
                        'user_id' => $admin->id,
                        'title'   => $title,
                        'msg'     => $msg,
                        'type'    => 'payment_received',
                        'read_at' => null
                    ]);
                }
            } catch (\Exception $notifEx) {
                Log::warning('Payment Notification creation failed', ['error' => $notifEx->getMessage()]);
            }

            try {
                if ($invoice->lead && $invoice->lead->gjob) {
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