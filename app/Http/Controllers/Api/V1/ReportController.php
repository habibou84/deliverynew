<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\AutoAssignChecks;
use App\Services\Reports\DayReport;
use App\Services\Reports\OrderSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Point détaillé sur une période : volumes par état et montants.
     * Un marchand ne voit que ses courses ; le personnel peut filtrer par marchand.
     */
    public function summary(Request $request, OrderSummary $summary): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'merchant_id' => ['nullable', 'integer'],
            'date_basis' => ['nullable', Rule::in(['created', 'delivery'])],
        ]);

        $orders = Order::query()
            ->visibleTo($request->user())
            ->when(isset($data['merchant_id']) && $request->user()->merchant_id === null, fn ($q) => $q->where('merchant_id', $data['merchant_id']));

        return response()->json(['data' => $summary->build(
            $orders,
            $request->date('from') ?? today()->startOfMonth(),
            $request->date('to') ?? today(),
            $data['date_basis'] ?? 'created',
        )]);
    }

    /** Point du jour de la page Courses : prévues, livrées, recette, encaissé, à reverser (montants si droit caisse) */
    public function day(Request $request, DayReport $report): JsonResponse
    {
        $user = $request->user();
        abort_if($user->merchant_id !== null || $user->company === null, 403);
        $data = $request->validate(['day' => ['nullable', 'date_format:Y-m-d']]);
        $company = $user->company;
        $day = $data['day'] ?? now($company->timezone ?: config('app.timezone'))->toDateString();

        return response()->json(['data' => $report->build($company, $day, $user->can('finance.view'))]);
    }

    /** Réglages qui manquent pour que les courses soient assignées automatiquement */
    public function autoAssignChecks(Request $request, AutoAssignChecks $checks): JsonResponse
    {
        $company = $request->user()->company?->fresh();
        abort_if($company === null || $request->user()->merchant_id !== null, 403);

        return response()->json(['data' => $checks->missing($company)]);
    }
}
