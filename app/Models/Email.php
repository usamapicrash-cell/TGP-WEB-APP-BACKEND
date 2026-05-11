<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    protected $fillable = ['sender', 'receiver', 'subject', 'html_body', 'text_body', 'type', 'is_read', 'message_id'];

    public function attachments()
    {
        return $this->hasMany(EmailAttachment::class);
    }
}