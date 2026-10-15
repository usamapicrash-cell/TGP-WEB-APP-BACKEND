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

        $jobs = $query->get();
        $formatted = collect();

        foreach ($jobs as $job) {
            // Job ke saare assigned glaziers fetch karein
            $glaziersList = $job->glaziers->isNotEmpty() ? $job->glaziers : ($job->glazier ? collect([$job->glazier]) : collect());

            if ($glaziersList->isEmpty()) {
                $lastMessage = $job->chats()->latest()->first();
                $formatted->push([
                    'id' => $job->id,
                    'glazier_id' => null,
                    'display_name' => 'Unassigned',
                    'lead' => [
                        'id' => $job->lead->id ?? null,
                        'customer_name' => $job->lead->client_name ?? 'N/A',
                        'lead_number' => $job->lead->lead_number ?? 'N/A',
                    ],
                    'last_msg' => $lastMessage ? $lastMessage->message : 'No messages yet. Start chat!',
                    'last_msg_at' => $lastMessage ? $lastMessage->created_at : $job->created_at,
                ]);
            } else {
                // Har glazier ke liye sidebar mein alag entry banayein taake pata chale kis kis ke sath chat hai
                foreach ($glaziersList as $glz) {
                    $lastMessage = $job->chats()->latest()->first();
                    
                    $formatted->push([
                        'id' => $job->id,
                        'glazier_id' => $glz->id,
                        'display_name' => $glz->name,
                        'lead' => [
                            'id' => $job->lead->id ?? null,
                            'customer_name' => ($job->lead->client_name ?? 'Customer') . " [{$glz->name}]",
                            'lead_number' => $job->lead->lead_number ?? 'N/A',
                        ],
                        'last_msg' => $lastMessage ? $lastMessage->message : 'No messages yet. Start chat!',
                        'last_msg_at' => $lastMessage ? $lastMessage->created_at : $job->created_at,
                    ]);
                }
            }
        }

        $sorted = $formatted->sortByDesc('last_msg_at')->values();

        return response()->json([
            'success' => true,
            'data' => $sorted
        ]);
    }

    public function index(Request $request, GJob $job)
    {
        // Job ki saari chats fetch hon gi kyun ke table mein glazier_id nahi hai
        $chats = $job->chats()->with('sender')->orderBy('created_at', 'asc')->get();

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
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240'
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
        $receiverId = ($roleName === 'admin') ? ($job->glazier_id ?? null) : ($job->lead->created_by ?? null);

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