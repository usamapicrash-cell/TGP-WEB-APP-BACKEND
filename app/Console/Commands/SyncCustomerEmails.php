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
    protected $description = 'Sync inbox and sent emails from Gmail from the last 2 days';

    public function handle()
    {
        $client = Client::account('default');
        
        try {
            $client->connect();
            $this->info("Connected to Gmail IMAP.");

            // 1. INBOX (Received Emails) Sync Karein
            $this->syncFolder($client, 'INBOX', 'received');

            // 2. Sent Mail (Sent Emails via Gmail Website/App) Sync Karein
            // Note: Normal Gmail mein folder name '[Gmail]/Sent Mail' hota hai
            $sentFolder = $client->getFolder('[Gmail]/Sent Mail') ?? $client->getFolder('[Gmail]/Sent');
            
            if ($sentFolder) {
                $this->syncFolder($client, $sentFolder, 'sent');
            } else {
                $this->warn("Sent folder not found.");
            }

        } catch (\Exception $e) {
            $this->error("Gmail Connection Error: " . $e->getMessage());
            \Log::error("IMAP Error: " . $e->getMessage());
        }
    }

    private function syncFolder($client, $folderName, $type)
    {
        $folder = is_string($folderName) ? $client->getFolder($folderName) : $folderName;
        
        if (!$folder) {
            return;
        }

        $this->info("Scanning folder: " . $folder->name);

        // Sent emails pe `unseen()` filter kaam nahi karega, isliye sirf last 2 days filter rakha hai
        $query = $folder->messages()->since(now()->subDays(2));
        
        if ($type === 'received') {
            $query->unseen();
        }

        $messages = $query->get();

        if ($messages->count() === 0) {
            $this->info("No new messages found in {$folder->name}.");
            return;
        }

        foreach ($messages as $message) {
            $messageId = $message->getMessageId();
            
            if (!$messageId) {
                continue;
            }

            $exists = Email::where('message_id', $messageId)->exists();
            
            if (!$exists) {
                $fromEmail = isset($message->getFrom()[0]) ? $message->getFrom()[0]->mail : null;
                $toEmail = isset($message->getTo()[0]) ? $message->getTo()[0]->mail : null;

                $newEmail = Email::create([
                    'message_id' => $messageId,
                    'sender'     => $fromEmail,
                    'receiver'   => $toEmail,
                    'subject'    => $message->getSubject() ?? '(No Subject)',
                    'html_body'  => $message->getHTMLBody() ?: $message->getTextBody(),
                    'type'       => $type, // 'sent' or 'received'
                    'is_read'    => ($type === 'sent') ? true : false,
                    'created_at' => Carbon::parse($message->getDate()[0]),
                ]);

                // Attachments sync logic
                if ($message->hasAttachments()) {
                    foreach ($message->getAttachments() as $attachment) {
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
                
                $this->info("Synced ({$type}): " . $newEmail->subject);
            }
        }
    }
}