<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAttachment extends Model
{
    protected $fillable = ['email_id', 'file_name', 'file_path', 'file_type', 'file_size'];
}