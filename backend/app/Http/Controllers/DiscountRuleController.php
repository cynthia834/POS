<?php

namespace App\Http\Controllers;

use App\Models\DiscountRule;
use Illuminate\Http\Request;

class DiscountRuleController extends Controller
{
    /**
     * Display a listing of discount rules.
     */
    public function index()
    {
        return response()->json(DiscountRule::orderBy('id', 'desc')->get());
    }

    /**
     * Store a newly created discount rule in the database.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:bogo,member,seasonal,markdown,happy_hour',
            'conditions' => 'required|array',
            'is_active' => 'nullable|boolean',
        ]);

        $rule = DiscountRule::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'conditions' => $validated['conditions'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($rule, 201);
    }

    /**
     * Toggle the active state of a discount rule.
     */
    public function toggle($id)
    {
        $rule = DiscountRule::find($id);
        if (!$rule) {
            return response()->json(['error' => 'Promotion rule not found'], 404);
        }

        $rule->is_active = !$rule->is_active;
        $rule->save();

        return response()->json($rule);
    }

    /**
     * Remove a discount rule from the database.
     */
    public function destroy($id)
    {
        $rule = DiscountRule::find($id);
        if (!$rule) {
            return response()->json(['error' => 'Promotion rule not found'], 404);
        }

        $rule->delete();
        return response()->json(['success' => true]);
    }
}
