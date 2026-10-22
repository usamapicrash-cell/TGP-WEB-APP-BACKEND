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
    protected $description = 'Sync inbox and sent emails efficiently and fast';

    public function handle()
    {
        // Script ko timeout se bachane ke liye limit set ki hai
        set_time_limit(30);

        try {
            $client = Client::account('default');
            
            // Fast connection attempt with suppress warnings
            @$client->connect();

            if (!$client->isConnected()) {
                return;
            }

            // 1. INBOX Sync (Only Unseen & last 6 hours for speed)
            $this->syncFolder($client, 'INBOX', 'received');

            // 2. Sent Mail Sync
            $sentFolder = $client->getFolder('[Gmail]/Sent Mail') ?? $client->getFolder('[Gmail]/Sent');
            if ($sentFolder) {
                $this->syncFolder($client, $sentFolder, 'sent');
            }

            @$client->disconnect();
        } catch (\Exception $e) {
            \Log::error("IMAP Fast Sync Error: " . $e->getMessage());
        }
    }

    private function syncFolder($client, $folderName, $type)
    {
        $folder = is_string($folderName) ? $client->getFolder($folderName) : $folderName;
        
        if (!$folder) return;

        try {
            // Time window ko 12 hours se reduce karke 6 hours kar diya hai taake query choti ho
            $query = $folder->messages()->since(now()->subHours(6));
            
            if ($type === 'received') {
                $query->unseen();
            }

            // Limit ko 5 kar diya hai taake background job ekdum foran execute hojaye
            $messages = $query->limit(5)->get();

            foreach ($messages as $message) {
                $messageId = $message->getMessageId();
                if (!$messageId) continue;

                // Check if email already processed (Database check is super fast)
                if (Email::where('message_id', $messageId)->exists()) {
                    if ($type === 'received') {
                        @$message->setFlag('Seen'); // Agar pehle se hai toh seen karke skip karo
                    }
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
                    'created_at' => isset($message->getDate()[0]) ? Carbon::parse($message->getDate()[0]) : now(),
                ]);

                // Save Attachments (Skip heavy files instantly)
                if ($message->hasAttachments()) {
                    foreach ($message->getAttachments() as $attachment) {
                        if ($attachment->getSize() > 5242880) continue; // Limit reduced to 5MB for speed

                        $filename = $attachment->getName();
                        if (!$filename) continue;

                        $fileType = $attachment->getMimeType() ?: 'application/octet-stream'; 
                        
                        $path = 'attachments/' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
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
                    @$message->setFlag('Seen');
                }
            }
        } catch (\Exception $e) {
            \Log::error("Folder Sync Error ({$type}): " . $e->getMessage());
        }
    }
}