<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Company;

class CompanyController extends Controller
{
    // Optional: create company manually (only super admin)
    public function store(Request $request)
    {
        $request->validate([
            'name'=>'required|string',
        ]);

        $company = Company::create([
            'name' => $request->name,
            'owner_id' => auth()->id(),
        ]);

        return response()->json($company, 201);
    }
}
