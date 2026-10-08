<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Événements temps réel de l'entreprise (nouvelles courses, incidents...) : personnel uniquement
Broadcast::channel('company.{companyId}', function ($user, $companyId) {
    return (int) $user->company_id === (int) $companyId
        && $user->hasAnyRole(\App\Enums\Role::values(\App\Enums\Role::staff()));
});
