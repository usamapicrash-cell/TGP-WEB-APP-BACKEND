<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    // Login API
    public function login(Request $request)
    {
        Log::error('Something went wrong in test method:');
        $request->validate([
            'email'=>'required|email',
            'password'=>'required|string'
        ]);

        if (!Auth::attempt($request->only('email','password'))) {
            return response()->json([
                'message'=>'Invalid credentials'
            ], 401);
        }

        $user = Auth::user();

        // create token
        $token = $user->createToken('api')->plainTextToken;

        // Create Login History Record
        LoginHistory::create([
            'user_id' => $user->id,
            'login_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $user->load('role', 'company')
        ]);
    }

    // Logout API
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            // Find and update history
            $history = LoginHistory::where('user_id', $user->id)
                ->whereNull('logout_at')
                ->latest()
                ->first();

            if ($history) {
                $history->update(['logout_at' => now()]);
            }

            // Revoke the token
            $user->currentAccessToken()->delete();
            
            return response()->json(['message' => 'Logged out successfully']);
        }

        return response()->json(['message' => 'User not found'], 401);
    }
}
