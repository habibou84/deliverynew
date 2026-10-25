<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Accounts\ConsoleLoginAlerts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Lien « Ce n'était pas moi » de l'alerte de connexion à la console (lien signé, valable 7 jours) : la personne
 * choisit un nouveau mot de passe ; toutes les sessions sont fermées.
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
        $validator = validator($request->all(), ['password' => ['required', 'string', 'confirmed', Password::min(12)]], [], ['password' => 'mot de passe']);
        if ($validator->fails()) {
            return view('platform.not-me', ['user' => $user, 'done' => false, 'error' => $validator->errors()->first()]);
        }
        $alerts->secure($user, (string) $request->input('password'), $request->ip());

        return view('platform.not-me', ['user' => $user, 'done' => true]);
    }
}
