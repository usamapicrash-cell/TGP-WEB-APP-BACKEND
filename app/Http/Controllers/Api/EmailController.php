<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EmailController extends Controller
{
    /**
     * Frontend se customer_email ke mutabiq history uthana (Super Fast Cached Data)
     */
    public function index(Request $request)
    {
        $request->validate([
            'customer_email' => 'required|email'
        ]);

        // NOTE: Yahan se SyncEmailsJob hata diya hai taake API load na le aur foran response aye.
        // Syncing background mein Laravel Queue ya Scheduler ke zariye honi chahiye.

        $emails = Email::with('attachments')
            ->where('receiver', $request->customer_email)
            ->orWhere('sender', $request->customer_email)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($emails);
    }
    
    public function emails_supplier(Request $request)
    {
        $request->validate([
            'lead_orderno' => 'required'
        ]);

        $orderNo = $request->lead_orderno;

        $lead = Lead::where('order_no', $orderNo)->first();

        if (!$lead) {
            return response()->json([
                'message' => 'Lead not found for order number: ' . $orderNo
            ], 404);
        }

        $emails = Email::with('attachments')
            ->where(function ($q) use ($orderNo) {
                $q->where('subject', 'LIKE', "%{$orderNo}%")
                  ->orWhere('html_body', 'LIKE', "%{$orderNo}%")
                  ->orWhere('text_body', 'LIKE', "%{$orderNo}%");
            })
            ->when($lead->email, function ($q) use ($lead) {
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
            'body'    => 'required|string',
            'files.*' => 'nullable|file|max:10240',
        ]);

        $sender = env('SENDER_EMAIL', 'sales@theglasspeople.com');
        $htmlBody = "<html><body><p>" . nl2br($request->body) . "</p></body></html>";

        $attachmentsForMail = [];
        $uploadedAttachments = [];

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
            /** @var \Illuminate\Mail\SentMessage $sentMessage */
            $sentMessage = Mail::send([], [], function ($message) use ($request, $sender, $attachmentsForMail, $htmlBody) {
                $message->to($request->to)
                    ->from($sender, 'The Glass People')
                    ->subject($request->subject)
                    ->html($htmlBody);

                foreach ($attachmentsForMail as $filePath) {
                    $message->attach($filePath);
                }
            });

            $sentMessageId = null;
            if ($sentMessage && method_exists($sentMessage, 'getMessageId')) {
                $sentMessageId = trim($sentMessage->getMessageId(), '<>');
            }

            // Optional: Agar aap sent email ko foran DB mein enter karna chahte hain taake sync ka wait na karna pare:
            // $emailRecord = Email::create([
            //     'sender'     => $sender,
            //     'receiver'   => $request->to,
            //     'subject'    => $request->subject,
            //     'html_body'  => $htmlBody,
            //     'type'       => 'sent',
            //     'is_read'    => true,
            //     'message_id' => $sentMessageId
            // ]);

            // foreach ($uploadedAttachments as $att) {
            //     EmailAttachment::create([
            //         'email_id'  => $emailRecord->id,
            //         'file_name' => $att['file_name'],
            //         'file_path' => $att['file_path'],
            //         'file_type' => $att['file_type'],
            //         'file_size' => $att['file_size'],
            //     ]);
            // }

            Log::info('Email sent successfully with Message-ID: ' . $sentMessageId);

            return response()->json(['status' => 'success', 'message' => 'Email sent successfully']);

        } catch (\Exception $e) {
            Log::error('Email Sending Failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}