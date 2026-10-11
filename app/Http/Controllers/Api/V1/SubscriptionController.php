<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Mon abonnement » : formule, essai et consommation du mois de l'entreprise.
 */
class SubscriptionController extends Controller
{
    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        $company = $request->user()->company;
        abort_if($company === null, 404, 'Aucune entreprise.');

        return response()->json(['data' => $subscription->summary($company)]);
    }
}
