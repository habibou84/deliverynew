<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessRuleException;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invitation d'un membre de l'équipe ou d'un livreur : un lien personnel, à usage unique, valable sept jours,
 * que l'administrateur envoie lui-même par WhatsApp (depuis son téléphone, sans SMS). La personne ouvre le lien,
 * arrive connectée dans son espace et choisit un mot de passe si elle le souhaite.
 */
class Invitations
{
    public const TTL_DAYS = 7;

    /**
     * @return array{token: string, url: string, text: string, whatsapp_link: string, expires_at: Carbon}
     */
    public function create(User $user, User $by): array
    {
        $token = Str::random(48);

        $verification = DB::transaction(function () use ($user, $by, $token) {
            // Une seule invitation valable à la fois
            PhoneVerification::where('user_id', $user->id)->where('purpose', PhoneVerification::INVITE)->pending()->update(['expires_at' => now()]);

            return PhoneVerification::create([
                'company_id' => $user->company_id,
                'purpose' => PhoneVerification::INVITE,
                'phone' => $user->phone,
                'token_hash' => PhoneVerification::hashToken($token),
                'whatsapp_code' => (string) random_int(100000, 999999),
                'user_id' => $user->id,
                'payload' => ['invited_by' => $by->id],
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);
        });

        $url = $user->company ? $user->company->url('/invitation/'.$token) : url('/invitation/'.$token);
        $text = $this->text($user, $url);

        return [
            'token' => $token,
            'url' => $url,
            'text' => $text,
            'whatsapp_link' => 'https://wa.me/'.ltrim($user->phone, '+').'?text='.rawurlencode($text),
            'expires_at' => $verification->expires_at,
        ];
    }

    public function find(string $token): ?PhoneVerification
    {
        if (strlen($token) > 100) {
            return null;
        }

        return PhoneVerification::where('token_hash', PhoneVerification::hashToken($token))
            ->where('purpose', PhoneVerification::INVITE)->pending()->with('user.company')->first();
    }

    /**
     * Le lien est ouvert : le compte est actif, la personne est connectée. Le lien ne sert qu'une fois.
     */
    public function accept(PhoneVerification $invitation, ?string $password): User
    {
        return DB::transaction(function () use ($invitation, $password) {
            $invitation = PhoneVerification::lockForUpdate()->findOrFail($invitation->id);
            if ($invitation->completed_at !== null || $invitation->isExpired()) {
                throw new BusinessRuleException("Cette invitation n'est plus valable. Demandez-en une nouvelle à votre administrateur.");
            }
            $user = $invitation->user?->fresh();
            if ($user === null || ! $user->isActive()) {
                throw new BusinessRuleException('Ce compte n\'est pas disponible. Contactez votre administrateur.');
            }

            $invitation->forceFill(['verified_at' => now(), 'verified_via' => 'invitation', 'completed_at' => now()])->save();
            $user->forceFill([
                'password' => $password !== null && $password !== '' ? $password : $user->password,
                'phone_verified_at' => $user->phone_verified_at ?? now(),
            ])->save();

            return $user;
        });
    }

    private function text(User $user, string $url): string
    {
        $company = $user->company?->name ?? 'Jibiat';
        $first = Str::of($user->name)->before(' ')->toString();

        return "Bonjour {$first}, voici votre accès à {$company} : {$url}\nCe lien est personnel et valable ".self::TTL_DAYS.' jours. Ouvrez-le depuis le téléphone du '.PhoneNumber::display($user->phone).'.';
    }
}
