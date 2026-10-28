<?php

namespace App\Services\Accounts;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Notifications\AccountDeletionRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suppression du compte d'un e-commerçant ou d'un livreur, demandée depuis son profil (exigée par Google Play) :
 * le compte est coupé tout de suite, les administrateurs de l'entreprise sont prévenus, et ses données personnelles
 * (nom, téléphone, e-mail, mot de passe) sont effacées après le délai, sauf annulation par l'entreprise. Les courses
 * et la comptabilité restent, sous un nom anonyme.
 */
class AccountDeletions
{
    public const ROLES = [Role::MerchantOwner, Role::MerchantStaff, Role::Courier];

    public const ERASED_NAME = 'Compte supprimé';

    public static function allowed(User $user): bool
    {
        return $user->company_id !== null && $user->hasAnyRole(array_map(fn (Role $r) => $r->value, self::ROLES));
    }

    public function request(User $user): AccountDeletionRequest
    {
        $request = DB::transaction(function () use ($user) {
            $request = AccountDeletionRequest::forCompany($user->company_id)->pending()->where('user_id', $user->id)->first()
                ?? AccountDeletionRequest::create([
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->getRoleNames()->first(),
                    'erase_after' => now()->addDays(AccountDeletionRequest::DELAY_DAYS),
                ]);
            $user->forceFill(['status' => UserStatus::Suspended])->save();
            // Connexion coupée sur tous les appareils
            $user->tokens()->delete();

            return $request;
        });

        $admins = User::query()->where('company_id', $user->company_id)->where('status', UserStatus::Active->value)->role(Role::Admin->value)->get();
        try {
            Notification::send($admins, new AccountDeletionRequested($request));
        } catch (Throwable $e) {
            report($e);
        }

        return $request;
    }

    /** L'entreprise annule avant l'effacement : le compte est rouvert */
    public function cancel(AccountDeletionRequest $request, User $by): void
    {
        abort_if($request->cancelled_at !== null || $request->erased_at !== null, 422, 'Cette demande est déjà annulée ou traitée.');
        DB::transaction(function () use ($request, $by) {
            $request->forceFill(['cancelled_at' => now(), 'cancelled_by' => $by->id])->save();
            $request->user?->forceFill(['status' => UserStatus::Active])->save();
        });
    }

    /**
     * Tâche quotidienne : efface les comptes dont le délai est passé.
     */
    public function erase(): int
    {
        $count = 0;
        AccountDeletionRequest::withoutGlobalScopes()->pending()->where('erase_after', '<=', now())->with('user')
            ->chunkById(100, function ($requests) use (&$count) {
                foreach ($requests as $request) {
                    DB::transaction(function () use ($request) {
                        $user = $request->user;
                        if ($user !== null) {
                            $user->tokens()->delete();
                            $user->forceFill([
                                'name' => self::ERASED_NAME,
                                'phone' => null,
                                'phone_verified_at' => null,
                                'email' => null,
                                'email_verified_at' => null,
                                'password' => Hash::make(Str::random(40)),
                                'remember_token' => null,
                                'status' => UserStatus::Suspended,
                            ])->save();
                            if (! $user->trashed()) {
                                $user->delete();
                            }
                        }
                        $request->forceFill(['name' => self::ERASED_NAME, 'erased_at' => now()])->save();
                    });
                    $count++;
                }
            });

        return $count;
    }
}
