<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $expenses = Expense::query()
            ->where('business_id', $business->id)
            ->with(['category', 'creator'])
            ->latest('incurred_on')
            ->paginate(25);

        return response()->json([
            'data' => $expenses->items(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'total' => $expenses->total(),
            ],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $categories = ExpenseCategory::query()
            ->where('business_id', $business->id)
            ->withCount('expenses')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'expense_category_id' => ['nullable', 'exists:expense_categories,id'],
            'description' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'in:cash,mpesa,card,bank,credit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'incurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $reference = 'EXP-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));

        $expense = Expense::create([
            'business_id' => $business->id,
            'expense_category_id' => $validated['expense_category_id'] ?? null,
            'created_by' => $request->user()->id,
            'reference_number' => $reference,
            'description' => $validated['description'],
            'payment_method' => $validated['payment_method'],
            'amount' => $validated['amount'],
            'incurred_on' => $validated['incurred_on'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $expense->load('category')], 201);
    }
}

