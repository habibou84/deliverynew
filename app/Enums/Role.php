<?php

namespace App\Enums;

use App\Models\User;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Dispatcher = 'dispatcher';
    case Cashier = 'cashier';
    case HubAgent = 'hub_agent';
    case Courier = 'courier';
    case MerchantOwner = 'merchant_owner';
    case MerchantStaff = 'merchant_staff';
    // Équipe de la console (plateforme) : le super administrateur est le « Propriétaire »
    case PlatformSupport = 'platform_support';
    case PlatformAccountant = 'platform_accountant';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Propriétaire de la plateforme',
            self::PlatformSupport => 'Support de la plateforme',
            self::PlatformAccountant => 'Comptable de la plateforme',
            self::Admin => 'Administrateur',
            self::Dispatcher => 'Dispatcher',
            self::Cashier => 'Caissier',
            self::HubAgent => 'Agent de dépôt',
            self::Courier => 'Livreur',
            self::MerchantOwner => 'E-commerçant',
            self::MerchantStaff => 'Employé e-commerçant',
        };
    }

    /**
     * Permissions accordées à chaque rôle. Le super administrateur passe
     * toutes les vérifications via Gate::before et n'en a pas besoin.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Console : droits donnés par les routes (role:…), aucun droit dans les entreprises
            self::SuperAdmin, self::PlatformSupport, self::PlatformAccountant => [],
            self::Admin => Permission::companyScoped(),
            self::Dispatcher => [
                Permission::UsersView,
                Permission::MerchantsView,
                Permission::OrdersView,
                Permission::OrdersCreate,
                Permission::OrdersDispatch,
            ],
            self::Cashier => [
                Permission::MerchantsView,
                Permission::OrdersView,
                Permission::FinanceView,
                Permission::FinanceManage,
            ],
            self::HubAgent => [
                Permission::MerchantsView,
                Permission::OrdersView,
                Permission::OrdersUpdateStatus,
                Permission::StockManage,
            ],
            self::Courier => [
                Permission::OrdersUpdateStatus,
            ],
            self::MerchantOwner => [
                Permission::OrdersView,
                Permission::OrdersCreate,
                Permission::FinanceView,
                Permission::StockManage,
                Permission::IntegrationsManage,
            ],
            self::MerchantStaff => [
                Permission::OrdersView,
                Permission::OrdersCreate,
            ],
        };
    }

    /**
     * Rôles de l'équipe de la console (comptes sans entreprise).
     *
     * @return list<self>
     */
    public static function platform(): array
    {
        return [self::SuperAdmin, self::PlatformSupport, self::PlatformAccountant];
    }

    /**
     * Rôles du personnel de l'entreprise de livraison (back-office).
     *
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::Admin, self::Dispatcher, self::Cashier, self::HubAgent];
    }

    /**
     * Rôles qu'un utilisateur peut attribuer via la gestion des utilisateurs.
     * Les rôles marchands seront attribués à la création des marchands (phase 1).
     *
     * @return list<self>
     */
    public static function assignableBy(User $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->hasRole(self::Admin->value)) {
            return [...self::staff(), self::Courier];
        }

        return [];
    }

    /**
     * @param  list<self>  $roles
     * @return list<string>
     */
    public static function values(array $roles): array
    {
        return array_map(fn (self $role) => $role->value, $roles);
    }
}
