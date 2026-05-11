<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'login_at',
        'logout_at',
        'ip_address',
    ];

    /**
     * Relationship back to the user
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}