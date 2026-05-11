<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index()
    {
        return Supplier::with('items')->latest()->get();
    }

    public function show(Supplier $supplier)
    {
        return response()->json(
            $supplier->load('items')
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:suppliers,name',
            'email' => 'nullable|email|unique:suppliers,email',
            'phone' => 'nullable|string|unique:suppliers,phone',
            'address' => 'nullable|string'
        ]);

        $supplier = Supplier::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'created_by' => auth()->id()
        ]);

        return response()->json([
            'message' => 'Supplier created successfully',
            'supplier' => $supplier
        ], 201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('suppliers', 'name')->ignore($supplier->id),
            ],
            'email' => [
                'nullable',
                'email',
                Rule::unique('suppliers', 'email')->ignore($supplier->id),
            ],
            'phone' => [
                'nullable',
                'string',
                Rule::unique('suppliers', 'phone')->ignore($supplier->id),
            ],
            'address' => 'nullable|string',
        ]);

        $supplier->update($request->only([
            'name','email','phone','address'
        ]));

        return response()->json([
            'message' => 'Supplier updated',
            'supplier' => $supplier
        ]);
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted'
        ]);
    }
}
