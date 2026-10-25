<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Rules\PhoneNumber as PhoneRule;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console : le compte du super administrateur connecté (nom, téléphone, e-mail du code de connexion) et ses
 * appareils retenus. Le mot de passe se change par PUT auth/password.
 */
class ConsoleAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->describe($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone')) ?? $request->input('phone')]);
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new PhoneRule, User::uniqueIn(null, 'phone')->ignore($user)],
            'email' => ['nullable', 'email', 'max:190', User::uniqueIn(null, 'email')->ignore($user)],
            'current_password' => ['nullable', 'string'],
        ], [], ['name' => 'nom', 'phone' => 'téléphone', 'email' => 'e-mail', 'current_password' => 'mot de passe actuel']);

        // Téléphone ou e-mail : ce sont les identifiants et l'adresse du code de connexion, le mot de passe est redemandé
        $credentials = $data['phone'] !== $user->phone || ($data['email'] ?? null) !== $user->email;
        if ($credentials) {
            $request->validate(['current_password' => ['required', 'current_password:sanctum']],
                ['current_password.current_password' => 'Mot de passe incorrect.'], ['current_password' => 'mot de passe actuel']);
        }

        $user->forceFill(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null])->save();

        return response()->json(['data' => $this->describe($user->fresh())]);
    }

    /** Oublie un appareil retenu (le code sera redemandé) ; sans identifiant : tous */
    public function forgetDevices(Request $request, ?int $device = null): JsonResponse
    {
        TrustedDevice::query()->where('user_id', $request->user()->id)->when($device, fn ($q, $id) => $q->whereKey($id))->delete();

        return response()->json(['data' => $this->describe($request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(User $user): array
    {
        return [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'devices' => TrustedDevice::query()->where('user_id', $user->id)->where('expires_at', '>', now())->latest('id')->get()
                ->map(fn (TrustedDevice $d) => [
                    'id' => $d->id, 'label' => $d->label, 'ip' => $d->ip,
                    'created_at' => $d->created_at?->toIso8601String(), 'last_used_at' => $d->last_used_at?->toIso8601String(),
                    'expires_at' => $d->expires_at->toIso8601String(),
                ])->values(),
        ];
    }
}
