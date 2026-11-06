<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Live;
use App\Models\LiveItem;
use App\Models\User;
use App\Models\Zone;
use App\Rules\PhoneNumber;
use App\Services\Live\LiveStudio;
use App\Support\Branding;
use App\Support\PhoneNumber as Phone;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Lien du live (/l/{jeton}) : sans WhatsApp relié, les clientes voient les produits du live et commandent
 * elles-mêmes (nom, téléphone, quartier). La commande arrive chez l'agence comme une commande WhatsApp.
 */
class PublicLiveController extends Controller
{
    public function __construct(private readonly LiveStudio $studio) {}

    public function show(string $token): JsonResponse
    {
        $live = $this->live($token);
        $live->load(['items.product', 'merchant.company']);
        $company = $live->merchant->company;

        return response()->json(['data' => [
            'name' => $live->name,
            'shop' => $live->merchant->business_name,
            'status' => $live->status,
            'delivery_company' => ['name' => $company->name, 'logo_url' => Branding::logoUrl($company)],
            'items' => $live->items->map(fn (LiveItem $i) => [
                'code' => $i->code,
                'name' => $i->name,
                'variant' => $i->variant,
                'price' => $i->price,
                'remaining' => $i->remaining(),
                'photo_url' => $i->product?->photoUrl(),
            ])->values(),
            'zones' => $this->zones($live)->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->fullName()])->values(),
        ]]);
    }

    public function order(Request $request, string $token): JsonResponse
    {
        $live = $this->live($token);
        // Champ invisible rempli par les robots
        abort_if(filled($request->input('website')), 422, 'Commande refusée.');
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', new PhoneNumber],
            'zone_id' => ['required', 'integer', Rule::exists('zones', 'id')->where(fn ($q) => $q->where('company_id', $live->company_id)->where('is_active', true)->where('is_shipping', false))],
            'address' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'zone_id' => 'quartier', 'quantity' => 'quantité']);

        if ($live->status !== Live::LIVE) {
            throw new BusinessRuleException($live->status === Live::ENDED ? 'Ce live est terminé.' : 'Le live n\'a pas encore commencé.');
        }
        $user = User::withoutGlobalScopes()->where('merchant_id', $live->merchant_id)->orderBy('id')->first()
            ?? throw new BusinessRuleException('Cette boutique ne prend pas de commandes pour le moment.');

        $order = $this->studio->addOrder($live, $user, [
            'code' => $data['code'],
            'quantity' => $data['quantity'],
            'recipient_name' => $data['name'],
            'recipient_phone' => $data['phone'],
            'delivery_zone_id' => $data['zone_id'],
            'delivery_address' => $data['address'] ?? null,
            'source' => 'link',
        ]);

        return response()->json(['data' => ['status' => $order->status, 'quantity' => $order->quantity]], 201);
    }

    private function live(string $token): Live
    {
        $live = app(Tenancy::class)->restrict(Live::withoutGlobalScopes()->where('public_token', mb_strtolower($token)))->first();
        abort_if($live === null || $live->status === Live::DRAFT && $live->items()->count() === 0, 404, 'Live introuvable.');

        return $live;
    }

    /** @return Collection<int, Zone> */
    private function zones(Live $live)
    {
        return Zone::forCompany($live->company_id)->active()->where('is_shipping', false)->with('parent')->get()
            ->sortBy(fn (Zone $z) => $z->fullName(), SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
