<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function index()
    {
        return Item::with('supplier')->latest()->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'sku' => [
                'nullable',
                'string',
                Rule::unique('items', 'sku')
            ],
            'price' => 'required|numeric|min:0',
            'cost' => 'required|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id'
        ]);

        $item = Item::create([
            'name' => $request->name,
            'sku' => $request->sku,
            'price' => $request->price,
            'cost' => $request->cost,
            'supplier_id' => $request->supplier_id,
            'created_by' => auth()->id()
        ]);

        return response()->json([
            'message' => 'Item created successfully',
            'item' => $item
        ], 201);
    }

    public function update(Request $request, Item $item)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'sku' => [
                'nullable',
                'string',
                Rule::unique('items', 'sku')->ignore($item->id)
            ],
            'price' => 'required|numeric|min:0',
            'cost' => 'required|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id'
        ]);

        $item->update($request->only([
            'name','sku','price','cost','supplier_id'
        ]));

        return response()->json([
            'message' => 'Item updated',
            'item' => $item
        ]);
    }

    public function show(Item $item)
    {
        return response()->json(
            $item->load('supplier')
        );
    }

    public function destroy(Item $item)
    {
        $item->delete();

        return response()->json([
            'message' => 'Item deleted'
        ]);
    }
}
