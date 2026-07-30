<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webklex\IMAP\Facades\Client;
use App\Models\Email;
use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class SyncCustomerEmails extends Command
{
    protected $signature = 'sync:emails';
    protected $description = 'Sync inbox and sent emails efficiently';

    public function handle()
    {
        set_time_limit(60);

        $client = Client::account('default');
        
        try {
            $client->connect();

            // 1. INBOX Sync (Only Unseen)
            $this->syncFolder($client, 'INBOX', 'received');

            // 2. Sent Mail Sync (Only recent batch)
            $sentFolder = $client->getFolder('[Gmail]/Sent Mail') ?? $client->getFolder('[Gmail]/Sent');
            if ($sentFolder) {
                $this->syncFolder($client, $sentFolder, 'sent');
            }

            $client->disconnect();
        } catch (\Exception $e) {
            \Log::error("IMAP Error: " . $e->getMessage());
        }
    }

    private function syncFolder($client, $folderName, $type)
    {
        $folder = is_string($folderName) ? $client->getFolder($folderName) : $folderName;
        
        if (!$folder) return;

        // Query optimization: Filter tight limit
        $query = $folder->messages()->since(now()->subHours(12));
        
        if ($type === 'received') {
            $query->unseen();
        }

        // Limit reduced to 10 for snappy execution
        $messages = $query->limit(10)->get();

        foreach ($messages as $message) {
            $messageId = $message->getMessageId();
            if (!$messageId) continue;

            // Check if email already processed
            if (Email::where('message_id', $messageId)->exists()) {
                continue;
            }

            $fromEmail = isset($message->getFrom()[0]) ? $message->getFrom()[0]->mail : null;
            $toEmail = isset($message->getTo()[0]) ? $message->getTo()[0]->mail : null;

            $newEmail = Email::create([
                'message_id' => $messageId,
                'sender'     => $fromEmail,
                'receiver'   => $toEmail,
                'subject'    => $message->getSubject() ?? '(No Subject)',
                'html_body'  => $message->getHTMLBody() ?: $message->getTextBody(),
                'type'       => $type,
                'is_read'    => ($type === 'sent'),
                'created_at' => Carbon::parse($message->getDate()[0]),
            ]);

            // Save Attachments
            if ($message->hasAttachments()) {
                foreach ($message->getAttachments() as $attachment) {
                    if ($attachment->getSize() > 10485760) continue; // Skip files > 10MB

                    $filename = $attachment->getName();
                    $fileType = $attachment->getMimeType() ?: 'application/octet-stream'; 
                    
                    $path = 'attachments/' . time() . '_' . $filename;
                    Storage::disk('public')->put($path, $attachment->getContent());

                    EmailAttachment::create([
                        'email_id'  => $newEmail->id,
                        'file_path' => $path,
                        'file_name' => $filename,
                        'file_type' => $fileType,
                    ]);
                }
            }

            if ($type === 'received') {
                $message->setFlag('Seen');
            }
        }
    }
}