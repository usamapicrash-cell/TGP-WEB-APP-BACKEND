<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password; // <--- Ye add karein

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
            $history = LoginHistory::where('user_id', $user->id)
                ->whereNull('logout_at')
                ->latest()
                ->first();

            if ($history) {
                $history->update(['logout_at' => now()]);
            }

            $user->currentAccessToken()->delete();
            
            return response()->json(['message' => 'Logged out successfully']);
        }

        return response()->json(['message' => 'User not found'], 401);
    }

    // Forgot Password API
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'We can\'t find a user with that e-mail address.'], 404);
        }

        try {
            $status = Password::broker()->sendResetLink(
                $request->only('email')
            );

            if ($status == Password::RESET_LINK_SENT) {
                return response()->json(['message' => 'Password reset link sent successfully.']);
            }

            return response()->json(['message' => __($status)], 400);

        } catch (\Exception $e) {
            \Log::error('Reset Password Email Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send reset email. ' . $e->getMessage()], 500);
        }
    }
}