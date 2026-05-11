<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\GJob;
use App\Models\JobChat;
use App\Models\JobActivity;
use Illuminate\Support\Facades\Log;


class JobChatController extends Controller
{
    // Fetch all chat messages for a job

    public function getConversations()
{
    $user = auth()->user();
    $userId = $user->id;
    
    // Role check
    $roleName = is_object($user->role) ? $user->role->name : $user->role;

    \App\Models\UserNotification::where('user_id', $userId)
        ->where('type', 'chat_message')
        ->delete();
    
    // Base Query: Glazier aur Lead ke sath data fetch karein
    $query = GJob::with(['lead:id,client_name,lead_number', 'glazier:id,name']);

    if ($roleName !== 'admin') { 
        $query->where('glazier_id', $user->id);
    } 

    $conversations = $query->get();

    $formatted = $conversations->map(function($job) use ($roleName) {
        // Last message fetch karein
        $lastMessage = $job->chats()->latest()->first();
        
        $display_name = ($roleName === 'admin') 
                        ? ($job->glazier->name ?? 'Unassigned') 
                        : 'Admin';
        
        return [
            'id' => $job->id,
            'status' => $job->status,
            'display_name' => $display_name,
            'lead' => [
                'id' => $job->lead->id ?? null,
                'customer_name' => $job->lead->client_name ?? 'N/A',
                'lead_number' => $job->lead->lead_number ?? 'N/A',
            ],
            'last_msg' => $lastMessage ? $lastMessage->message : 'No messages yet. Start chat!',
            // Sort karne ke liye hum timestamp save kar lete hain
            'last_msg_at' => $lastMessage ? $lastMessage->created_at : $job->created_at,
            'unread_count' => 0 
        ];
    })
    // Yahan sorting logic add ki hai: Sabse naye message wali job upper ayegi
    ->sortByDesc('last_msg_at')
    ->values(); // values() indexes ko reset karne ke liye

    return response()->json([
        'success' => true,
        'data' => $formatted
    ]);
}

    public function index(GJob $job)
    {
        // Latest messages niche honay chahiye, isliye createdAt ASC use karein 
        // ya frontend pe reverse karein. Yahan reverse order bhejte hain:
        $chats = $job->chats()
                     ->with('sender')
                     ->orderBy('created_at', 'asc') // Messages order mein aayenge
                     ->get();

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'chats' => $chats,
            'current_user_id' => auth()->id() // Is se frontend ko pata chalega "Me" kon hai
        ]);
    }

    // Send a new chat message
    public function store(Request $request, GJob $job)
    {
        $request->validate([
            'message' => 'nullable|string|max:2000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240'
        ]);
        $user = auth()->user();
        $attachmentPath = null;

        // Handle attachment first
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('job-media', 'public');

            $media = $job->media()->create([
                'created_by' => auth()->id(),
                'type' => $request->type ?? 'image',
                'file_path' => $attachmentPath,
            ]);
        }
        // Create chat message
        $chat = $job->chats()->create([
            'sender_id' => auth()->id(),
            'message' => $request->message,
            'attachment' => $attachmentPath,
        ]);

        // Log activity
        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action' => 'Sent chat message' . ($attachmentPath ? ' with attachment' : '')
        ]);


        $roleName = is_object($user->role) ? $user->role->name : $user->role;
        $receiverId = ($roleName === 'admin') 
        ? ($job->glazier_id ?? null) 
        : ($job->lead->created_by ?? null);

        if ($receiverId) {
            \App\Models\UserNotification::create([
                'title'        => 'New Message - ' . $job->job_number,
                'msg'          => "{$user->name} sent a new message: " . \Str::limit($chat->message, 50),
                'type'         => 'chat_message',
                'user_id'      => $receiverId,   // Jise notification milegi
                'from_user_id' => auth()->id(),     // Jisne message bheja
                'read_at'      => null,
            ]);
        }

        // Return chat with full attachment URL
        return response()->json([
            'message' => 'Message sent successfully',
            'chat' => $chat->load('sender')
        ], 201);
    }
}
