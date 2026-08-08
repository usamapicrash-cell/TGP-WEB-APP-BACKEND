<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\Lead;
use App\Jobs\SyncEmailsJob; // <-- YAHAN IMPORT REQIURED HAI
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan; // Yeh import zaroor karein

class EmailController extends Controller
{
    /**
     * Frontend se customer_email ke mutabiq history uthana
     */
    public function index(Request $request)
    {
        $request->validate([
            'customer_email' => 'required|email'
        ]);

        SyncEmailsJob::dispatch();

        // Is customer ki puri history (sent/received) attachments ke sath
        $emails = Email::with('attachments')
            ->where('receiver', $request->customer_email)
            ->orWhere('sender', $request->customer_email)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($emails);
    }

    public function emails_supplier(Request $request)
    {
        // Frontend se 'lead_orderno' zaroori hai
        $request->validate([
            'lead_orderno' => 'required'
        ]);

        $orderNo = $request->lead_orderno;

        // 1. Lead dhoondain along with Purchase Orders aur unke Suppliers
        $lead = Lead::with(['purchaseOrders.supplier'])
            ->where('order_no', $orderNo) // Apne column ka exact naam rakhein (e.g., order_no ya id)
            ->first();

        if (!$lead) {
            return response()->json([
                'message' => 'Lead not found for order number: ' . $orderNo
            ], 404);
        }

        // 2. Lead ke saare Purchase Orders se Suppliers ki unique emails nikalain
        $supplierEmails = $lead->purchaseOrders
            ->pluck('supplier.email')
            ->filter()      // Null ya empty emails remove karne ke liye
            ->unique()      // Duplicate emails remove karne ke liye
            ->values()
            ->toArray();

        // Agar kisi PO ke sath supplier ki email attach nahi hai
        if (empty($supplierEmails)) {
            return response()->json([], 200);
        }

        // Email sync job dispatch karein
        SyncEmailsJob::dispatch();

        // 3. Un emails ko fetch karein jo supplierEmails me se kisi ki bhi hon AND order number match karta ho
        $emails = Email::with('attachments')
            // Check karein ke sender ya receiver supplier emails list mein ho
            ->where(function ($query) use ($supplierEmails) {
                $query->whereIn('receiver', $supplierEmails)
                      ->orWhereIn('sender', $supplierEmails);
            })
            // Check karein ke Subject ya Body mein Order Number maujood ho
            ->where(function ($query) use ($orderNo) {
                $query->where('subject', 'LIKE', "%{$orderNo}%")
                      ->orWhere('html_body', 'LIKE', "%{$orderNo}%")
                      ->orWhere('text_body', 'LIKE', "%{$orderNo}%");
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($emails);
    }

    public function markAsRead(Email $email) {
        $email->update(['is_read' => true]);
        return response()->json(['success' => true]);
    }

    /**
     * Dashboard se manually email bhejna (Attachments ke sath)
     */
    public function sendEmail(Request $request)
    {
        $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'to'      => 'required|email',
            'subject' => 'required|string',
            'body'    => 'required|string', // HTML body from Editor
            'files.*' => 'nullable|file|max:10240', // 10MB Limit per file
        ]);

        $sender = env('SENDER_EMAIL', 'sales@theglasspeople.com');
        $htmlBody = "<html><body><p>" . nl2br($request->body) . "</p></body></html>";

        // 1. Database mein record create karein
        $emailRecord = Email::create([
            'sender'    => $sender,
            'receiver'  => $request->to,
            'subject'   => $request->subject,
            'html_body' => $htmlBody,
            'type'      => 'sent',
            'is_read'   => true
        ]);

        $attachmentsForMail = [];

        // 2. Attachments handle karein (Agar hain)
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('email_attachments', 'public');
                
                EmailAttachment::create([
                    'email_id'  => $emailRecord->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);

                $attachmentsForMail[] = storage_path('app/public/' . $path);
            }
        }

        // 3. Actual Mail send karein
        try {
            Mail::send([], [], function ($message) use ($request, $sender, $attachmentsForMail, $htmlBody) {
                $message->to($request->to)
                    ->from($sender, 'The Glass People')
                    ->subject($request->subject)
                    ->html($htmlBody);

                foreach ($attachmentsForMail as $filePath) {
                    $message->attach($filePath);
                }
            });
            \Log::info('Email sent successfully to: ' . $request->to . $sender);
            SyncEmailsJob::dispatch();
            return response()->json(['status' => 'success', 'message' => 'Email sent successfully']);
        } catch (\Exception $e) {
            \Log::error('Email Sending Failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}