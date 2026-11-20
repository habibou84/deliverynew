<?php

namespace App\Http\Controllers\Api\Connect;

use App\Http\Controllers\Controller;
use App\Services\Connect\DirectoryQuotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Devis des entreprises de l'annuaire pour un lieu (contrat socle 1.12, directory-quotes), sans clé : Jibiat
 * Marchand propose, à la saisie d'une commande, les entreprises qui livrent ce lieu et leur prix.
 */
class DirectoryQuoteController extends Controller
{
    public function __invoke(Request $request, DirectoryQuotes $quotes): JsonResponse
    {
        $data = $request->validate([
            'delivery_place' => ['required', 'string', 'max:120'],
            'pickup_place' => ['nullable', 'string', 'max:120'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'fragile' => ['nullable', 'boolean'],
            'slugs' => ['nullable', 'array', 'max:50'],
            'slugs.*' => ['string', 'max:100'],
        ]);

        return response()->json(['data' => $quotes->for($data)]);
    }
}
