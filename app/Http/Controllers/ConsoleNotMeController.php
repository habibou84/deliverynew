<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Accounts\ConsoleLoginAlerts;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lien « Ce n'était pas moi » de l'alerte de connexion à la console (lien signé, valable 7 jours) : le compte est
 * verrouillé (sessions fermées, mot de passe effacé). On ne choisit pas de mot de passe ici.
 */
class ConsoleNotMeController extends Controller
{
    public function show(User $user): View
    {
        abort_unless($user->isPlatformStaff(), 404);

        return view('platform.not-me', ['user' => $user, 'done' => false]);
    }

    public function secure(Request $request, User $user, ConsoleLoginAlerts $alerts): View
    {
        abort_unless($user->isPlatformStaff(), 404);
        $alerts->secure($user, $request->ip());

        return view('platform.not-me', ['user' => $user, 'done' => true]);
    }
}
