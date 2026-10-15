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
    public function getConversations()
    {
        $user = auth()->user();
        $userId = $user->id;
        
        $roleName = is_object($user->role) ? $user->role->name : $user->role;

        \App\Models\UserNotification::where('user_id', $userId)
            ->where('type', 'chat_message')
            ->delete();
        
        $query = GJob::with(['glaziers', 'lead:id,client_name,lead_number', 'glazier:id,name']);

        if ($roleName !== 'admin') {  
            $query->whereHas('glaziers', function($q) use ($userId) {
                $q->where('users.id', $userId);
            })->orWhere('glazier_id', $userId);
        }  

        $conversations = $query->get();

        $formatted = $conversations->map(function($job) use ($roleName) {
            $lastMessage = $job->chats()->latest()->first();
            
            // Job ke saare assigned glaziers fetch karein
            $glaziersList = $job->glaziers->isNotEmpty() ? $job->glaziers : ($job->glazier ? collect([$job->glazier]) : collect());
            
            return [
                'id' => $job->id,
                'status' => $job->status,
                'glaziers' => $glaziersList->map(fn($g) => ['id' => $g->id, 'name' => $g->name]),
                'lead' => [
                    'id' => $job->lead->id ?? null,
                    'customer_name' => $job->lead->client_name ?? 'N/A',
                    'lead_number' => $job->lead->lead_number ?? 'N/A',
                ],
                'last_msg' => $lastMessage ? $lastMessage->message : 'No messages yet. Start chat!',
                'last_msg_at' => $lastMessage ? $lastMessage->created_at : $job->created_at,
                'unread_count' => 0 
            ];
        })
        ->sortByDesc('last_msg_at')
        ->values();

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    public function index(Request $request, GJob $job)
    {
        $glazierId = $request->query('glazier_id');

        $query = $job->chats()->with('sender')->orderBy('created_at', 'asc');

        // Agar glazier_id pass kiya gaya hai toh specific filter karein (optional based on schema)
        if ($glazierId) {
            // Aap yahan chats ko glazier ke mutabiq filter kar sakte hain agar alag table ho
        }

        $chats = $query->get();

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'chats' => $chats,
            'current_user_id' => auth()->id()
        ]);
    }

    public function store(Request $request, GJob $job)
    {
        $request->validate([
            'message' => 'nullable|string|max:2000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'glazier_id' => 'nullable|exists:users,id'
        ]);

        $user = auth()->user();
        $attachmentPath = null;

        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('job-media', 'public');

            $job->media()->create([
                'created_by' => auth()->id(),
                'type' => $request->type ?? 'image',
                'file_path' => $attachmentPath,
            ]);
        }

        $chat = $job->chats()->create([
            'sender_id' => auth()->id(),
            'message' => $request->message,
            'attachment' => $attachmentPath,
        ]);

        JobActivity::create([
            'gjob_id' => $job->id,
            'user_id' => auth()->id(),
            'action' => 'Sent chat message' . ($attachmentPath ? ' with attachment' : '')
        ]);

        $roleName = is_object($user->role) ? $user->role->name : $user->role;
        
        // Agar admin bhej raha hai, toh request mein jo glazier_id hai usay notify karein
        $receiverId = ($roleName === 'admin') 
            ? ($request->glazier_id ?? $job->glazier_id) 
            : ($job->lead->created_by ?? null);

        if ($receiverId) {
            \App\Models\UserNotification::create([
                'title' => 'New Message - ' . $job->job_number,
                'msg' => "{$user->name} sent a new message: " . \Str::limit($chat->message, 50),
                'type' => 'chat_message',
                'user_id' => $receiverId,
                'from_user_id' => auth()->id(),
                'read_at' => null,
            ]);
        }

        return response()->json([
            'message' => 'Message sent successfully',
            'chat' => $chat->load('sender')
        ], 201);
    }
}