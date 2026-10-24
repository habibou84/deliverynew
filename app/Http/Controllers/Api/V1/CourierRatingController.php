<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierRating;
use App\Services\Couriers\CourierRatings;
use App\Services\Reports\Statistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notes des livreurs pour les responsables : classement, fiche d'un livreur,
 * évaluation du mois, avis graves à traiter ou à annuler.
 */
class CourierRatingController extends Controller
{
    public function __construct(private readonly CourierRatings $ratings) {}

    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $rows = $this->ratings->ranking($company);
        $since = now()->startOfMonth();

        $monthRatings = CourierRating::query()->counted()->where('created_at', '>=', $since);
        $deliveredThisMonth = $company->orders()->where('delivered_at', '>=', $since)->whereNotNull('delivery_courier_id')->where('is_shipping', false)->count();
        $recipientThisMonth = (clone $monthRatings)->where('source', CourierRating::SOURCE_RECIPIENT)->count();

        $toHandle = CourierRating::query()->toHandle()->with(['courier.user:id,name', 'order:id,tracking_code,merchant_id', 'order.merchant:id,business_name'])
            ->latest('id')->limit(50)->get()
            ->map(fn (CourierRating $r) => [
                ...$this->ratings->presentRating($r),
                'courier_id' => $r->courier_id,
                'courier_name' => $r->courier?->user?->name,
                'merchant_name' => $r->order?->merchant?->business_name,
            ]);

        $scored = $rows->whereNotNull('score');

        return response()->json(['data' => [
            'couriers' => $rows,
            'to_handle' => $toHandle,
            'summary' => [
                'average' => $scored->isEmpty() ? null : round($scored->avg('score'), 1),
                'ratings_this_month' => (clone $monthRatings)->count(),
                'response_rate' => $deliveredThisMonth > 0 ? (int) round(100 * $recipientThisMonth / $deliveredThisMonth) : null,
                'to_handle' => CourierRating::query()->toHandle()->count(),
            ],
            'settings' => [
                'ask_recipient_rating' => $company->ask_recipient_rating,
                'rating_alert_threshold' => (float) $company->rating_alert_threshold,
                'weights' => CourierRatings::WEIGHTS,
                'window_days' => CourierRatings::WINDOW_DAYS,
            ],
        ]]);
    }

    public function show(Request $request, Courier $courier, Statistics $statistics): JsonResponse
    {
        $courier->load('user:id,name,phone,status');
        $company = $request->user()->company;
        $to = CarbonImmutable::now();
        $row = collect($statistics->for($company)->couriers($to->subDays(CourierRatings::WINDOW_DAYS), $to, true)['couriers'])
            ->firstWhere('id', $courier->id);

        return response()->json(['data' => [
            'courier' => ['id' => $courier->id, 'name' => $courier->user?->name, 'phone' => $courier->user?->phone],
            ...$this->ratings->detail($courier),
            'figures' => $row ? [
                'delivered' => $row['delivered'],
                'failed' => $row['failed'],
                'success_rate' => $row['success_rate'],
                'delivery_minutes' => $row['delivery_minutes'],
                'cash_gap' => $row['cash_gap'],
            ] : null,
            'threshold' => (float) $company->rating_alert_threshold,
        ]]);
    }

    public function review(Request $request, Courier $courier): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'criteria' => ['required', 'array'],
            'criteria.*' => ['integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'comment_visible' => ['boolean'],
        ]);

        $review = $this->ratings->review($courier, $request->user(), $data['period'].'-01', $data['criteria'], $data['comment'] ?? null, (bool) ($data['comment_visible'] ?? false));

        return response()->json(['data' => ['id' => $review->id, 'score' => $review->score, 'period' => $review->period->toDateString()]], 201);
    }

    public function cancel(Request $request, CourierRating $rating): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->ratings->presentRating($this->ratings->cancel($rating, $request->user(), $data['reason'] ?? null))]);
    }

    public function handle(Request $request, CourierRating $rating): JsonResponse
    {
        return response()->json(['data' => $this->ratings->presentRating($this->ratings->markHandled($rating, $request->user()))]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ask_recipient_rating' => ['sometimes', 'boolean'],
            'rating_alert_threshold' => ['sometimes', 'numeric', 'between:1,5'],
        ]);

        $company = $request->user()->company;
        $company->update($data);

        return response()->json(['data' => [
            'ask_recipient_rating' => $company->ask_recipient_rating,
            'rating_alert_threshold' => (float) $company->rating_alert_threshold,
        ]]);
    }
}
