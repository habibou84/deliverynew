<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Throwable;

/**
 * Copie les fichiers d'un disque vers un autre (du serveur vers Cloudflare R2), avant de changer PRIVATE_DISK. Les
 * chemins restent les mêmes. Seules les pièces jointes des courses retiennent leur disque (order_attachments.disk) :
 * elles passent sur la destination une fois tous les fichiers copiés sans erreur. Relançable : un fichier déjà
 * présent avec la même taille est passé. Voir docs/FICHIERS-R2.md.
 *
 * Même commande dans Jibiat Marchand (habibou84/ecommerce) : une correction se reporte dans les deux dépôts.
 */
class CopyFiles extends Command
{
    protected $signature = 'files:copy
        {from : disque d\'origine (ex. local)}
        {to : disque de destination (ex. r2-private)}
        {--except=* : dossier à ne pas copier (plusieurs possibles)}
        {--dry-run : compter sans rien copier}';

    protected $description = 'Copie les fichiers d\'un disque vers un autre (ex. vers Cloudflare R2)';

    public function handle(): int
    {
        [$from, $to] = [(string) $this->argument('from'), (string) $this->argument('to')];
        if ($from === $to || ! config("filesystems.disks.{$from}") || ! config("filesystems.disks.{$to}")) {
            $this->error('Deux disques différents de config/filesystems.php sont attendus.');

            return self::FAILURE;
        }
        /** @var FilesystemAdapter $source */
        $source = Storage::disk($from);
        /** @var FilesystemAdapter $target */
        $target = Storage::disk($to);

        if (! $this->option('dry-run') && ! $this->probe($target)) {
            $this->error("Écriture impossible sur « {$to} » : vérifiez les réglages R2 du .env (clés, adresse, nom de l'espace).");

            return self::FAILURE;
        }

        $except = array_map(fn (string $dir) => trim($dir, '/').'/', (array) $this->option('except'));
        $counts = ['copiés' => 0, 'déjà présents' => 0, 'en erreur' => 0];
        $bytes = 0;

        foreach ($source->getDriver()->listContents('', true) as $item) {
            $path = $item->path();
            if (! $item instanceof FileAttributes || str_starts_with(basename($path), '.')
                || collect($except)->contains(fn (string $dir) => str_starts_with($path.'/', $dir))) {
                continue;
            }

            try {
                $size = $item->fileSize() ?? $source->size($path);
                if ($target->exists($path) && $target->size($path) === $size) {
                    $counts['déjà présents']++;

                    continue;
                }
                if (! $this->option('dry-run')) {
                    $stream = $source->readStream($path);
                    $written = $stream !== null && $target->writeStream($path, $stream);
                    is_resource($stream) && fclose($stream);
                    if (! $written || $target->size($path) !== $size) {
                        throw new \RuntimeException('copie incomplète');
                    }
                }
                $counts['copiés']++;
                $bytes += $size;
            } catch (Throwable $e) {
                $counts['en erreur']++;
                $this->warn("{$path} : {$e->getMessage()}");
            }
        }

        $this->info(sprintf('%s → %s%s : %d copié(s) (%s Mo), %d déjà présent(s), %d en erreur.', $from, $to,
            $this->option('dry-run') ? ' (essai, rien copié)' : '', $counts['copiés'], number_format($bytes / 1048576, 1, ',', ' '),
            $counts['déjà présents'], $counts['en erreur']));

        if ($counts['en erreur'] > 0) {
            return self::FAILURE;
        }
        if (! $this->option('dry-run')) {
            $moved = DB::table('order_attachments')->where('disk', $from)->update(['disk' => $to]);
            $moved > 0 && $this->info("{$moved} pièce(s) jointe(s) de course rattachée(s) à « {$to} ».");
        }

        return self::SUCCESS;
    }

    /** Écrit, relit et efface un petit fichier : les réglages de la destination sont bons */
    private function probe(FilesystemAdapter $disk): bool
    {
        $path = '.essai-'.bin2hex(random_bytes(6));
        try {
            return $disk->put($path, 'ok') && $disk->get($path) === 'ok';
        } catch (Throwable) {
            return false;
        } finally {
            rescue(fn () => $disk->delete($path), report: false);
        }
    }
}
