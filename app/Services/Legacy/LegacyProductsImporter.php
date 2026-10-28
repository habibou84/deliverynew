<?php

namespace App\Services\Legacy;

use App\Models\Company;
use App\Models\Product;
use App\Services\Stock\StockKeeper;
use Illuminate\Support\Str;
use Throwable;

/**
 * Produits de l'ancienne application (table « products » et ses variantes) : un produit par variante (« Robe —
 * Taille : M »), prix, et stock actuel compté comme un inventaire (« Reprise de l'ancienne application »). Relancer
 * met à jour le nom, le prix et le stock, sans doublon.
 */
class LegacyProductsImporter
{
    public function __construct(
        private readonly LegacyOrdersImporter $orders,
        private readonly StockKeeper $stock,
    ) {}

    /**
     * @param  callable(array): array  $fetch  lecture d'une page de produits dans l'ancienne application
     * @return array{products: int, skipped: int}
     */
    public function import(Company $company, callable $fetch): array
    {
        $this->orders->for($company);
        $counts = ['products' => 0, 'skipped' => 0];
        $afterId = 0;

        do {
            $page = $fetch(['after_id' => $afterId, 'limit' => 200]);
            foreach ($page['data'] ?? [] as $row) {
                $merchant = $this->orders->merchantFor($row['client'] ?? null, $row['client_id'] ?? null);
                if ($merchant === null) {
                    $counts['skipped']++;

                    continue;
                }

                $variants = $row['variants'] ?? [];
                $lines = $variants === []
                    ? [['legacy_id' => (string) $row['id'], 'name' => (string) $row['name'], 'stock' => (int) ($row['stock'] ?? 0)]]
                    : array_map(fn (array $v) => [
                        'legacy_id' => $row['id'].'-'.$v['id'],
                        'name' => trim($row['name'].' — '.trim(($v['attribute'] ?? '').' : '.($v['value'] ?? ''), ' :')),
                        'stock' => (int) ($v['stock'] ?? 0),
                    ], $variants);

                foreach ($lines as $line) {
                    try {
                        $product = Product::forCompany($company->id)->withTrashed()->where('legacy_id', $line['legacy_id'])->first() ?? new Product;
                        $product->forceFill([
                            'company_id' => $company->id,
                            'legacy_id' => $line['legacy_id'],
                            'merchant_id' => $merchant->id,
                            'deleted_at' => null,
                            'name' => Str::limit($line['name'], 250, ''),
                            'description' => Str::limit(trim((string) ($row['description'] ?? '')), 1000, '') ?: null,
                            'price' => max(0, (int) ($row['price'] ?? 0)),
                            'is_active' => true,
                        ])->save();
                        $this->stock->count(null, $product, $this->stock->location($merchant), max(0, $line['stock']), 'Reprise de l\'ancienne application');
                        $counts['products']++;
                    } catch (Throwable $e) {
                        report($e);
                        $counts['skipped']++;
                    }
                }
            }
            $afterId = $page['next_after_id'] ?? null;
        } while ($afterId !== null);

        return $counts;
    }
}
