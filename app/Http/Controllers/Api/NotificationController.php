<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // 1. Get all notifications for logged-in user
    public function index()
    {
        $notifications = UserNotification::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($n) {
                return [
                    'id'    => $n->id,
                    'title' => $n->title,
                    'msg'   => $n->msg,
                    'type'  => $n->type,
                    'read'  => !is_null($n->read_at),
                    'time'  => $n->created_at->diffForHumans(),
                    'raw_time' => $n->created_at
                ];
            });

        return response()->json($notifications);
    }

    // 2. Mark specific notification as read
    public function markAsRead($id)
    {
        $notification = UserNotification::where('user_id', auth()->id())->findOrFail($id);
        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Notification marked as read']);
    }

    // 3. Mark all as read
    public function markAllRead()
    {
        UserNotification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'All notifications marked as read']);
    }
}