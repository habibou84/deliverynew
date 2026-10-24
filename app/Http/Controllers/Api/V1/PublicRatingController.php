<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CourierRating;
use App\Models\Order;
use App\Services\Couriers\CourierRatings;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Page publique d'avis : le destinataire note le livreur depuis le lien reçu après la livraison.
 * N'expose que le prénom du livreur, la boutique et l'heure de livraison.
 */
class PublicRatingController extends Controller
{
    public function __construct(private readonly CourierRatings $ratings) {}

    public function show(string $token): JsonResponse
    {
        $order = $this->ratings->orderForToken($token);
        abort_if($order === null, 404);

        return response()->json(['data' => $this->present($order)]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $order = $this->ratings->orderForToken($token);
        abort_if($order === null, 404);

        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:8'],
            'tags.*' => ['string', Rule::in(array_keys(CourierRating::RECIPIENT_TAGS))],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $this->ratings->record($order, CourierRating::SOURCE_RECIPIENT, $data['score'], $data['tags'] ?? [], $data['comment'] ?? null, 'web', phone: $order->recipient_phone);

        return response()->json(['data' => $this->present($order->refresh())], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        $rated = CourierRating::withoutGlobalScopes()->where('order_id', $order->id)->where('source', CourierRating::SOURCE_RECIPIENT)->first();

        return [
            'company' => Branding::data($order->company),
            'tracking_code' => $order->tracking_code,
            'merchant_name' => $order->merchant?->business_name,
            'courier_first_name' => strtok((string) $order->deliveryCourier?->user?->name, ' ') ?: null,
            'delivered_at' => $order->delivered_at,
            'expired' => $order->delivered_at?->lt(now()->subDays(CourierRatings::RATING_DAYS)) ?? true,
            'rated' => $rated !== null,
            'score' => $rated?->score,
            'tags' => collect(CourierRating::RECIPIENT_TAGS)->map(fn ($t, $code) => ['code' => $code, ...$t])->values(),
        ];
    }
}
