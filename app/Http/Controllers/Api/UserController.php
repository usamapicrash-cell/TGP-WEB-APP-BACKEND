<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Company;
use Illuminate\Support\Facades\Hash;
use App\Notifications\GlazierWelcomeNotification; // <--- Import Karein
use App\Notifications\ResetPasswordNotification; // Agar aap notification use kar rahe hain

class UserController extends Controller
{

    public function index(Request $request)
    {
        $query = User::with('role');

        if ($request->filled('role')) {
            $roleName = $request->role;
            
            $query->whereHas('role', function($q) use ($roleName) {
                $q->where('name', $roleName);
            });
        }

        return response()->json($query->get());
    }

    // Super Admin creates Executive + Company
    public function createExecutive(Request $request)
    {
        $request->validate([
            'name'=>'required|string',
            'email'=>'required|email|unique:users,email',
            'phone'=>'nullable|string',
            'password'=>'required|min:6',
            'company_id'=>'required|integer',
        ]);

        $executiveRole = Role::where('name','executive')->first();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'role_id' => $executiveRole->id,
            'company_id' => $request->company_id,
        ]);

        return response()->json($user, 201);
    }

    // Executive creates Admin
    public function createAdmin(Request $request)
    {
        $request->validate([
            'name'=>'required|string',
            'email'=>'required|email|unique:users,email',
            'phone'=>'nullable|string',
            'password'=>'required|min:6',
        ]);

        $adminRole = Role::where('name','admin')->first();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'role_id' => $adminRole->id,
            'company_id' => auth()->user()->company_id,
        ]);

        return response()->json($user, 201);
    }

    // Admin creates Glazier
    public function createGlazier(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string',
            'password' => 'required|min:6',
        ]);

        $glazierRole = Role::where('name', 'glazier')->first();

        $plainPassword = $request->password; // Email mein bhejney ke liye

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($plainPassword),
            'role_id' => $glazierRole->id,
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
        ]);

        // Glazier ko Welcome Email + Login Details bhejein
        try {
            $user->notify(new GlazierWelcomeNotification($plainPassword));
        } catch (\Exception $e) {
            // Email failure handle karne ke liye (Optional logging)
            \Log::error('Failed to send glazier welcome email: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Glazier created successfully and login details emailed.',
            'user' => $user
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $id,
            'phone' => 'nullable|string', // <--- Validate phone
            'password' => 'nullable|min:6',
        ]);

        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone, // <--- Update phone
        ];

        if ($request->filled('password')) {
            $data['password'] = bcrypt($request->password);
        }

        $user->update($data);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user
        ], 200);
    }

    // <--- NEW: Reset Password Method for Admin Action
    public function resetPassword($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Reset password back to default temporary password 'password123'
        $user->update([
            'password' => bcrypt('password123')
        ]);

        return response()->json([
            'message' => 'Password reset successfully to temporary password: password123'
        ], 200);
    }

    
    public function resetPass($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // 1. Password reset token generate karein
        $token = Password::broker()->createToken($user);

        // 2. Frontend ka reset password page URL (jahan token aur email query param mein jayein)
        $resetUrl = config('app.frontend_url', 'https://your-portal-domain.com') . "/reset-password?token={$token}&email=" . urlencode($user->email);

        try {
            // 3. Aapka custom email template bhejhein
            Mail::send('emails.glazier_welcome', ['user' => $user, 'resetUrl' => $resetUrl], function ($message) use ($user) {
                $message->to($user->email);
                $message->subject('Reset Your Password - The Glass People');
            });

            return response()->json([
                'message' => "Password reset instructions sent to {$user->name}"
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Password reset email failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send reset email'], 500);
        }
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (auth()->id() == $user->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully'], 200);
    }

    public function updateProfile(Request $request) {
        $user = $request->user();
        $user->update($request->only('name', 'phone'));
        return response()->json(['message' => 'Profile updated successfully']);
    }

    public function updatePassword(Request $request) {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8',
        ]);

        $user = $request->user();
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is wrong'], 403);
        }

        $user->update(['password' => Hash::make($request->new_password)]);
        return response()->json(['message' => 'Password changed successfully']);
    }
}