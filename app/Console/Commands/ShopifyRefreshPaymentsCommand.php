<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ShopifyShop;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyOAuth;
use App\Services\Shopify\ShopifyOrderNormalizer;
use Illuminate\Console\Command;

class ShopifyRefreshPaymentsCommand extends Command
{
    protected $signature = 'shopify:refresh-payments {--shop=} {--dry-run}';

    protected $description = 'Récupère les montants restants dus Shopify pour les commandes ouvertes. Ne s’exécute pas tout seul.';

    public function handle(ShopifyOrderNormalizer $normalizer): int
    {
        $shops = ShopifyShop::query()->where('is_active', true)->whereNull('uninstalled_at');
        if ($this->option('shop')) {
            $needle = (string) $this->option('shop');
            $shops->where(fn ($q) => $q->where('shop_domain', $needle)->orWhere('id', $needle));
        }
        $dry = (bool) $this->option('dry-run');
        $updated = 0;

        foreach ($shops->get()->filter(fn (ShopifyShop $s) => $s->isInstalled()) as $shop) {
            $client = new ShopifyClient($shop->shop_domain, $shop->access_token, app(ShopifyOAuth::class)->apiVersion());
            $after = null;
            do {
                $data = $client->graphql(ShopifyOrderNormalizer::ORDERS_QUERY, [
                    'first' => 25,
                    'after' => $after,
                    'query' => 'financial_status:paid,partially_paid,pending,authorized status:open',
                ]);
                $page = $data['orders'] ?? [];
                foreach ($page['nodes'] ?? [] as $node) {
                    $rest = $normalizer->toRest($node);
                    $updated++;
                    if ($dry) {
                        $this->line($rest['name'].' outstanding='.$rest['total_outstanding']);
                        continue;
                    }
                    Order::query()
                        ->where('shopify_shop_id', $shop->id)
                        ->where('shopify_order_id', $rest['id'])
                        ->get()
                        ->each(function (Order $order) use ($rest) {
                            $order->forceFill([
                                'total_outstanding' => $rest['total_outstanding'],
                                'amount_paid' => max(0, (float) $order->total_price - (float) ($rest['total_outstanding'] ?? 0)),
                                'financial_status' => $rest['financial_status'] ?? $order->financial_status,
                                'payment_gateway_names' => $rest['payment_gateway_names'] ?? $order->payment_gateway_names,
                            ])->save();
                        });
                }
                $after = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
            } while ($after);
        }

        $this->info(($dry ? 'Dry-run : ' : '').$updated.' commande(s).');

        return self::SUCCESS;
    }
}
