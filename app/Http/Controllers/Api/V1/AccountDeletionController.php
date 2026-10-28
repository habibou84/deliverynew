<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Services\Accounts\AccountDeletions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suppression du compte : demandée par l'e-commerçant ou le livreur depuis son profil ; l'entreprise voit les
 * demandes en cours et peut les annuler avant l'effacement.
 */
class AccountDeletionController extends Controller
{
    public function __construct(private readonly AccountDeletions $deletions) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(AccountDeletions::allowed($user), 403, 'La suppression depuis le profil est réservée aux comptes e-commerçant et livreur.');
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirmez la suppression de votre compte.']);

        $deletion = $this->deletions->request($user);

        return response()->json(['data' => [
            'erase_after' => $deletion->erase_after->toDateString(),
            'message' => 'Votre compte est fermé. Vos données personnelles seront effacées le '.$deletion->erase_after->format('d/m/Y').'.',
        ]], 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => AccountDeletionRequest::query()->pending()->orderBy('erase_after')->get()
            ->map(fn (AccountDeletionRequest $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'role' => $r->role,
                'requested_at' => $r->created_at?->toIso8601String(),
                'erase_after' => $r->erase_after->toIso8601String(),
            ])->values()]);
    }

    public function cancel(Request $request, AccountDeletionRequest $deletion): JsonResponse
    {
        $this->deletions->cancel($deletion, $request->user());

        return response()->json(['data' => ['cancelled' => true]]);
    }
}
