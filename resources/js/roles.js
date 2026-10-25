// Espaces de l'application et rôles autorisés (miroir de App\Enums\Role)
export const SPACES = {
  console: ['super_admin', 'platform_support', 'platform_accountant'],
  admin: ['super_admin', 'admin', 'dispatcher', 'cashier', 'hub_agent'],
  livreur: ['courier'],
  marchand: ['merchant_owner', 'merchant_staff'],
}

export function homeFor(role) {
  if (SPACES.console.includes(role)) return '/console'
  if (SPACES.admin.includes(role)) return '/admin'
  if (SPACES.livreur.includes(role)) return '/livreur'
  if (SPACES.marchand.includes(role)) return '/marchand'
  return '/'
}

// Rôles de la console : Propriétaire (super_admin), Support, Comptable
export const CONSOLE_ROLES = {
  owner: ['super_admin'],
  support: ['super_admin', 'platform_support'],
  accounting: ['super_admin', 'platform_accountant'],
  team: ['super_admin', 'platform_support', 'platform_accountant'],
}

export function consoleCan(role, group) {
  return CONSOLE_ROLES[group]?.includes(role) ?? false
}
