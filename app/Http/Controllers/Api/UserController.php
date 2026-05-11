<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Company;

class UserController extends Controller
{

    public function index(Request $request)
    {
        // Start query with the role relationship loaded
        $query = User::with('role');

        // If ?role=something is in the URL
        if ($request->filled('role')) {
            $roleName = $request->role;
            
            // Use whereHas to filter by the name column in the ROLES table
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
            'password'=>'required|min:6',
            'company_id'=>'required|integer',
        ]);

        $executiveRole = Role::where('name','executive')->first();


        // Create Executive user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
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
            'password'=>'required|min:6',
        ]);

        $adminRole = Role::where('name','admin')->first();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role_id' => $adminRole->id,
            'company_id' => auth()->user()->company_id, // same company as executive
        ]);

        return response()->json($user, 201);
    }

    // Admin creates Glazier
    public function createGlazier(Request $request)
    {
        $request->validate([
            'name'=>'required|string',
            'email'=>'required|email|unique:users,email',
            'password'=>'required|min:6',
        ]);

        $glazierRole = Role::where('name','glazier')->first();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role_id' => $glazierRole->id,
            'company_id' => auth()->user()->company_id, // same company as admin
            'created_by' => auth()->id(), // <--- Ye store karega kisne banaya
        ]);

        return response()->json($user, 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Validation: Email unique check karein lekin current user ID ko ignore karke
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => 'nullable|min:6', // Password optional hai update ke waqt
        ]);

        // Data prepare karein
        $data = [
            'name' => $request->name,
            'email' => $request->email,
        ];

        // Agar user ne naya password diya hai tabhi update karein
        if ($request->filled('password')) {
            $data['password'] = bcrypt($request->password);
        }

        $user->update($data);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user
        ], 200);
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Agar aap chahte hain ke admin apna account khud delete na kar sake:
        if (auth()->id() == $user->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully'], 200);
    }

    public function updateProfile(Request $request) {
        $user = $request->user();
        $user->update($request->only('name'));
        return response()->json(['message' => 'Name updated successfully']);
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
