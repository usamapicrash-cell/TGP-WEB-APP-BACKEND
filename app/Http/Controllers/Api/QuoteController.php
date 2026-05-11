<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Invoice;
use App\Models\Email;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf; // If using laravel-dompdf
use Illuminate\Support\Facades\Mail;
use App\Mail\SendQuoteMail;

class QuoteController extends Controller
{
    /**
     * Store or Update a Quote and log activity
     */

    public function generatePdf(Quote $quote)
    {
        // 1. Load the relationship to ensure items appear in the PDF
        $quote->load(['items', 'lead']);

        // 2. Prepare data for the view
        $data = [
            'quote' => $quote,
            'items' => $quote->items,
            'lead'  => $quote->lead,
        ];

        // 3. Load the HTML view and convert to PDF
        // View should be located at resources/views/pdfs/quote.blade.php
        $pdf = Pdf::loadView('pdfs.quote', $data);

        // 4. Return as a stream (this allows the JS Blob to read it)
        return $pdf->stream("{$quote->quote_number}.pdf");
    }

    public function storeOrUpdate(Request $request, Lead $lead)
    {
        try {
            $request->validate([
                'items' => 'required|array',
                'total_amount' => 'required|numeric',
            ]);

            return DB::transaction(function () use ($request, $lead) {
                // 1. Mark ALL existing quotes for this lead as 'rejected'
                $lead->quotes()->update(['status' => 'rejected']);

                // 2. Count existing quotes to make a unique versioned quote number
                $version = $lead->quotes()->count() + 1;
                $quoteNumber = 'QT-' . ($lead->id + 1000) . '-V' . $version;

                // 3. Create the NEW active quote
                $quote = Quote::create([
                    'lead_id'      => $lead->id,
                    'quote_number' => $quoteNumber,
                    'subtotal'     => $request->subtotal,
                    'labour_total' => $request->labour_total ?? 0,
                    'total_amount' => $request->total_amount,
                    'status'       => $request->status // This is the new active one
                ]);

                // 4. Create items for the new quote
                foreach ($request->items as $item) {
                    $quote->items()->create([
                        'description' => $item['description'],
                        'qty'         => $item['qty'],
                        'unit_price'  => $item['unit_price'],
                        'total'       => $item['qty'] * $item['unit_price'],
                    ]);
                }

                try {
                if ($lead->email) {
                    $mailable = new SendQuoteMail($quote);
                    
                    // 1. Send Actual Email
                    Mail::to($lead->email)->send($mailable);

                    // 2. Render HTML for DB Log
                    $htmlBody = view('emails.quote_customer', ['quote' => $quote->load(['items', 'lead'])])->render();

                    // 3. Save Email Record
                    $emailLog = Email::create([
                        'sender'    => env('SENDER_EMAIL', 'sales@theglasspeople.com'),
                        'receiver'  => $lead->email,
                        'subject'   => "New Quote Received: #{$quoteNumber}",
                        'html_body' => $htmlBody,
                        'type'      => 'sent',
                        'is_read'   => true
                    ]);

                    // 4. Save PDF Attachment in DB
                    $pdf = Pdf::loadView('pdfs.quote', [
                        'quote' => $quote,
                        'items' => $quote->items,
                        'lead'  => $quote->lead
                    ]);

                    $fileName = "Quote_{$quoteNumber}.pdf";
                    $filePath = "email_attachments/" . uniqid() . "_" . $fileName;
                    
                    // Storage mein PDF save karein
                    \Storage::disk('public')->put($filePath, $pdf->output());

                    // Attachment table mein entry
                    $emailLog->attachments()->create([
                        'file_name' => $fileName,
                        'file_path' => $filePath,
                        'file_type' => 'application/pdf',
                        'file_size' => \Storage::disk('public')->size($filePath),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Email Logging failed for Quote {$quoteNumber}: " . $e->getMessage());
            }
            // --- EMAIL LOGGING END ---

                   $quote->lead->update([
                        'status' => 'quote',
                        'value'  => $request->total_amount // Lead table ka column update ho gaya
                    ]);

                 if ($lead->gjob) {
                        $lead->gjob->activities()->create([
                            'user_id'     => Auth::id(),
                            'action'      => 'Quote Updated',
                            'description' => "Quote #{$quote->quote_number} processed. Total: $" . number_format($request->total_amount, 2),
                        ]);
                    }

                return response()->json($quote->load('items'));
            });
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
   

    public function index(Lead $lead)
    {
        // Fetch all quotes for this lead, newest first
        $quotes = Quote::with('items')
            ->where('lead_id', $lead->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($quotes);
    }

    public function updateStatus(Request $request, Quote $quote)
    {
        $request->validate(['status' => 'required|in:draft,sent,approved,rejected']);
        
        // Purana status save kar letay hain description ke liye
        $oldStatus = strtoupper($quote->status);
        $newStatus = strtoupper($request->status);
        
        $quote->update(['status' => $request->status]);

        // Optional: Agar approved ho toh Lead ka status bhi change kar sakte hain
        if ($request->status === 'approved') {
            $quote->lead->update([
                'status' => 'won',
                'value'  => $quote->total_amount // Lead table ka column update ho gaya
            ]);
        }

        // --- ACTIVITY LOGGING START ---
        // Hum check kar rahay hain ke Lead se linked 'gjob' exist karta hai ya nahi
        if ($quote->lead && $quote->lead->gjob) {
            $quote->lead->gjob->activities()->create([
                'user_id'     => Auth::id(),
                'action'      => 'Quote Status Updated',
                'description' => "Quote #{$quote->quote_number} status changed from {$oldStatus} to {$newStatus}.",
            ]);
        }
        // --- ACTIVITY LOGGING END ---

        return response()->json([
            'message' => 'Status updated successfully',
            'status'  => $request->status
        ]);
    }

   
}