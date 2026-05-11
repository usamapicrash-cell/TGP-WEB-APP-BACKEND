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
    protected $description = 'Sync unseen customer emails from the last 2 days';

    public function handle()
    {
        $client = Client::account('default');
        
        try {
            $client->connect();
            $this->info("Connected to Gmail IMAP.");

            $folder = $client->getFolder('INBOX');
            
            // Filter: Sirf Unseen hon aur pichle 2 din ke andar aayi hon
            $messages = $folder->messages()
                ->unseen()
                ->since(now()->subDays(2)) // Yeh line pichle 2 din ka filter lagayegi
                ->get();

            if ($messages->count() === 0) {
                $this->info("No new messages found from the last 2 days.");
                return;
            }

            foreach($messages as $message){
                $exists = Email::where('message_id', $message->getMessageId())->exists();
                
                if (!$exists) {
                    $newEmail = Email::create([
                        'message_id' => $message->getMessageId(),
                        'sender'     => $message->getFrom()[0]->mail,
                        'receiver'   => 'sales@theglasspeople.com',
                        'subject'    => $message->getSubject(),
                        'html_body'  => $message->getHTMLBody() ?: $message->getTextBody(),
                        'is_read'    => false,
                        'created_at' => Carbon::parse($message->getDate()[0]),
                    ]);

                    if($message->hasAttachments()){
                        foreach($message->getAttachments() as $attachment){
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
                    
                    $message->setFlag('Seen');
                    $this->info("Synced: " . $newEmail->subject);
                }
            }

        } catch (\Exception $e) {
            $this->error("Gmail Connection Error: " . $e->getMessage());
            \Log::error("IMAP Error: " . $e->getMessage());
        }
    }
}