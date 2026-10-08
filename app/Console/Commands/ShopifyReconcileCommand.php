<?php

namespace App\Console\Commands;

use App\Jobs\ShopifyReconcileJob;
use App\Models\ShopifyShop;
use App\Services\Shopify\ShopifyReconcileService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ShopifyReconcileCommand extends Command
{
    protected $signature = 'shopify:reconcile {--shop=} {--since=} {--dry-run}';

    protected $description = 'Rapproche les commandes Shopify (filet de sécurité). --dry-run n’écrit rien.';

    public function handle(ShopifyReconcileService $service): int
    {
        $shops = ShopifyShop::query()->where('is_active', true)->whereNull('uninstalled_at');
        if ($this->option('shop')) {
            $needle = (string) $this->option('shop');
            $shops->where(function ($q) use ($needle) {
                $q->where('shop_domain', $needle)->orWhere('id', $needle);
            });
        }

        $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        $dry = (bool) $this->option('dry-run');
        $rows = $shops->get()->filter(fn (ShopifyShop $s) => $s->isInstalled());
        if ($rows->isEmpty()) {
            $this->warn('Aucune boutique Shopify active.');

            return self::SUCCESS;
        }

        foreach ($rows as $shop) {
            if ($dry || $this->option('since')) {
                $result = $service->reconcile($shop, $since, $dry, false);
            } else {
                ShopifyReconcileJob::dispatch($shop->id, false, null, false)->onQueue('shopify');
                $result = ['orders' => 'en file', 'skipped' => 0, 'dry_run' => false];
            }
            $this->line(sprintf(
                '%s : %s commande(s)%s',
                $shop->shop_domain,
                $result['orders'],
                $dry ? ' (dry-run, aucune écriture)' : ''
            ));
        }

        return self::SUCCESS;
    }
}
