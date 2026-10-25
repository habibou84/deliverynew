<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

/**
 * Le super administrateur passe toutes les vérifications (Gate::before).
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user)
            || ($actor->belongsToSameCompanyAs($user) && $actor->can(Permission::UsersView->value));
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->belongsToSameCompanyAs($user)
            && $actor->can(Permission::UsersManage->value)
            && ! $user->isSuperAdmin();
    }

    /**
     * Identifiants de connexion (téléphone, e-mail, mot de passe, lien d'invitation) : pas ceux d'un autre
     * administrateur, sinon un administrateur pourrait prendre le compte d'un autre.
     */
    public function changeCredentials(User $actor, User $user): bool
    {
        return $this->update($actor, $user)
            && ($actor->is($user) || ! $user->hasRole(Role::Admin->value));
    }

    public function delete(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $this->update($actor, $user);
    }

    /**
     * Un utilisateur ne peut pas modifier son propre rôle ni son propre statut
     * (évite qu'un admin se bloque lui-même ou s'attribue un rôle).
     */
    public function changeRoleOrStatus(User $actor, User $user): bool
    {
        return ! $actor->is($user)
            && $this->update($actor, $user)
            && in_array($user->primaryRole(), [...Role::assignableBy($actor), null], true);
    }
}
