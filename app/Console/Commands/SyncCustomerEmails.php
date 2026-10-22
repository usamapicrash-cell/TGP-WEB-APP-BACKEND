<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webklex\IMAP\Facades\Client;
use App\Models\Email;
use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncCustomerEmails extends Command
{
    protected $signature = 'sync:emails';
    protected $description = 'Sync inbox and sent emails efficiently and fast with detailed logs';

    public function handle()
    {
        Log::info('--- SyncCustomerEmails command started ---');
        set_time_limit(30);

        try {
            $client = Client::account('default');
            
            Log::info('Attempting IMAP connection...');
            @$client->connect();

            if (!$client->isConnected()) {
                Log::warning('IMAP Client failed to connect!');
                return;
            }
            Log::info('IMAP Client connected successfully.');

            // 1. INBOX Sync
            Log::info('Syncing INBOX folder...');
            $this->syncFolder($client, 'INBOX', 'received');

            // 2. Sent Mail Sync
            Log::info('Syncing Sent folder...');
            $sentFolder = $client->getFolder('[Gmail]/Sent Mail') ?? $client->getFolder('[Gmail]/Sent');
            if ($sentFolder) {
                $this->syncFolder($client, $sentFolder, 'sent');
            } else {
                Log::info('Sent folder not found.');
            }

            @$client->disconnect();
            Log::info('--- SyncCustomerEmails command finished successfully ---');
        } catch (\Exception $e) {
            Log::error("IMAP Fast Sync Exception: " . $e->getMessage());
        }
    }

    private function syncFolder($client, $folderName, $type)
    {
        $folder = is_string($folderName) ? $client->getFolder($folderName) : $folderName;
        
        if (!$folder) {
            Log::warning("Folder not found: " . (is_string($folderName) ? $folderName : 'Object'));
            return;
        }

        try {
            $query = $folder->messages()->since(now()->subHours(6));
            
            if ($type === 'received') {
                $query->unseen();
            }

            $messages = $query->limit(25)->get();
            Log::info("Found " . count($messages) . " messages in folder: {$type}");

            foreach ($messages as $message) {
                $messageId = $message->getMessageId();
                if (!$messageId) continue;

                if (Email::where('message_id', $messageId)->exists()) {
                    if ($type === 'received') {
                        @$message->setFlag('Seen');
                    }
                    continue;
                }

                Log::info("Processing new email with Message-ID: {$messageId} ({$type})");

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

                // Save Attachments
                if ($message->hasAttachments()) {
                    foreach ($message->getAttachments() as $attachment) {
                        if ($attachment->getSize() > 5242880) continue; 

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
            Log::error("Folder Sync Error ({$type}): " . $e->getMessage());
        }
    }
}