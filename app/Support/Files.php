<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Fichiers de l'application (photos de preuve, signatures, pièces jointes, documents des véhicules, logos, photos
 * des produits, applications Android) : jamais servis directement, toujours par l'application.
 *
 * Disque choisi par PRIVATE_DISK (« local » par défaut ; « r2-private » pour Cloudflare R2, voir
 * docs/FICHIERS-R2.md). Sur un stockage objet, plusieurs serveurs web voient les mêmes fichiers. Les copies
 * calculées (versions réduites des images, icônes) restent sur le disque « local » de chaque serveur.
 */
class Files
{
    public static function privateDisk(): string
    {
        return (string) config('filesystems.private_disk', 'local');
    }

    public static function private(): FilesystemAdapter
    {
        return Storage::disk(self::privateDisk());
    }
}
