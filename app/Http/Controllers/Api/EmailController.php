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

        SyncEmailsJob::dispatchSync();

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
        // Frontend se 'lead_orderno' validation
        $request->validate([
            'lead_orderno' => 'required'
        ]);

        $orderNo = $request->lead_orderno;

        // 1. Lead fetch karein taake iski email mil sake
        $lead = Lead::where('order_no', $orderNo)->first();

        if (!$lead) {
            return response()->json([
                'message' => 'Lead not found for order number: ' . $orderNo
            ], 404);
        }

        // 2. Email sync job dispatch karein
        SyncEmailsJob::dispatchSync();

        // 3. Query: Order No match karein LEKIN customer ki email exclude karein
        $emails = Email::with('attachments')
            ->where(function ($q) use ($orderNo) {
                $q->where('subject', 'LIKE', "%{$orderNo}%")
                  ->orWhere('html_body', 'LIKE', "%{$orderNo}%")
                  ->orWhere('text_body', 'LIKE', "%{$orderNo}%");
            })
            ->when($lead->email, function ($q) use ($lead) {
                // Customer ki email na sender mein ho aur na hi receiver mein
                $q->where('sender', '!=', $lead->email)
                  ->where('receiver', '!=', $lead->email);
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

        $attachmentsForMail = [];
        $uploadedAttachments = [];

        // 1. Files ko temporary store karein
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('email_attachments', 'public');

                $uploadedAttachments[] = [
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ];

                $attachmentsForMail[] = storage_path('app/public/' . $path);
            }
        }

        try {
            $sentMessageId = null;

            // 2. Email Send karein aur Message-ID capture karein
            Mail::send([], [], function ($message) use ($request, $sender, $attachmentsForMail, $htmlBody, &$sentMessageId) {
                $message->to($request->to)
                    ->from($sender, 'The Glass People')
                    ->subject($request->subject)
                    ->html($htmlBody);

                foreach ($attachmentsForMail as $filePath) {
                    $message->attach($filePath);
                }

                // Message-ID generate / extract karein
                $symfonyMessage = $message->getSymfonyMessage();
                $sentMessageId = $symfonyMessage->getMessageId(); 
                // Result format e.g., "<random_hash@domain.com>"
            });

            // Clean message ID format (agar brackets '<>' ke sath aaye to clean kar lein)
            if ($sentMessageId) {
                $sentMessageId = trim($sentMessageId, '<>');
            }

            // 3. Database mein record create karein MESSAGE_ID ke sath
            $emailRecord = Email::create([
                'sender'     => $sender,
                'receiver'   => $request->to,
                'subject'    => $request->subject,
                'html_body'  => $htmlBody,
                'type'       => 'sent',
                'is_read'    => true,
                'message_id' => $sentMessageId // <-- Yahan Message-ID save ho rahi hai
            ]);

            // 4. Attachments ko DB mein link karein
            foreach ($uploadedAttachments as $att) {
                EmailAttachment::create([
                    'email_id'  => $emailRecord->id,
                    'file_name' => $att['file_name'],
                    'file_path' => $att['file_path'],
                    'file_type' => $att['file_type'],
                    'file_size' => $att['file_size'],
                ]);
            }

            \Log::info('Email sent successfully to: ' . $request->to . ' with Message-ID: ' . $sentMessageId);

            // 5. Sync Jobs Dispatch Karein
            SyncEmailsJob::dispatchSync();

            return response()->json(['status' => 'success', 'message' => 'Email sent successfully']);

        } catch (\Exception $e) {
            \Log::error('Email Sending Failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}