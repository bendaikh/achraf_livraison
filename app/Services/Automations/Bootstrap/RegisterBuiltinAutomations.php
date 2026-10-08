<?php

namespace App\Services\Automations\Bootstrap;

use App\Services\Automations\Actions\AddClientTagAction;
use App\Services\Automations\Actions\AddNoteAction;
use App\Services\Automations\Actions\AssignAgentAction;
use App\Services\Automations\Actions\RemoveClientTagAction;
use App\Services\Automations\Actions\CarrierCreateParcelAction;
use App\Services\Automations\Actions\CarrierRefreshTrackingAction;
use App\Services\Automations\Actions\ChangeStatusAction;
use App\Services\Automations\Actions\NotifyAction;
use App\Services\Automations\Actions\ShopifyAddOrderNoteAction;
use App\Services\Automations\Actions\ShopifyCreateFulfillmentAction;
use App\Services\Automations\Actions\ShopifySyncOrderAction;
use App\Services\Automations\Actions\ShopifyUpdateTrackingAction;
use App\Services\Automations\Actions\WebhookExternalAction;
use App\Services\Automations\Actions\WhatsAppSendMessageAction;
use App\Services\Automations\AutomationRegistry;

/**
 * Registers built-in triggers, condition fields and actions at application boot.
 * Future integrations should call the same registry methods from their own service providers.
 */
class RegisterBuiltinAutomations
{
    public function __invoke(AutomationRegistry $registry): void
    {
        $this->triggers($registry);
        $this->conditionFields($registry);
        $this->actions($registry);
    }

    protected function triggers(AutomationRegistry $registry): void
    {
        $items = [
            ['order.created', 'Nouvelle commande', 'commandes'],
            ['order.updated', 'Commande modifiée', 'commandes'],
            ['order.confirmed', 'Commande confirmée', 'confirmation'],
            ['order.no_answer', 'Pas de réponse', 'confirmation'],
            ['order.postponed', 'Commande reportée', 'confirmation'],
            ['order.cancelled', 'Commande annulée', 'confirmation'],
            ['order.status_changed', 'Changement de statut livraison', 'livraison'],
            ['order.confirmation_changed', 'Changement de confirmation', 'confirmation'],
            ['order.payment_changed', 'Paiement modifié', 'commandes'],
            ['order.assigned_agent', 'Affectée à un agent', 'confirmation'],
            ['order.assigned_driver', 'Affectée à un livreur', 'livraison'],
            ['order.assigned_carrier', 'Affectée à un transporteur', 'transporteurs'],
            ['parcel.created', 'Colis créé', 'transporteurs'],
            ['tracking.created', 'Tracking créé', 'transporteurs'],
            ['carrier.status_changed', 'Statut transporteur modifié', 'transporteurs'],
            ['order.out_for_delivery', 'En livraison', 'livraison'],
            ['order.delivered', 'Commande livrée', 'livraison'],
            ['order.delivery_failed', 'Échec de livraison', 'livraison'],
            ['return.created', 'Retour créé', 'sav'],
            ['return.picked_up', 'Retour récupéré', 'sav'],
            ['return.received_depot', 'Retour réceptionné dépôt', 'sav'],
            ['exchange.created', 'Échange créé', 'sav'],
            ['client.created', 'Client créé', 'clients'],
            ['client.updated', 'Client modifié', 'clients'],
            ['client.tag_added', 'Tag client ajouté', 'clients'],
            ['client.tag_removed', 'Tag client supprimé', 'clients'],
            ['whatsapp.message_received', 'Message WhatsApp reçu', 'whatsapp'],
            ['schedule.datetime', 'Date / heure atteinte', 'planification'],
            ['manual', 'Déclenchement manuel', 'manuel'],
            ['schedule.cron', 'Planifié (cron)', 'planification'],
            ['webhook.external', 'Webhook / API externe', 'api'],
            ['shopify.order_updated', 'Commande Shopify mise à jour', 'shopify'],
            ['shopify.order_edited', 'Commande Shopify modifiée', 'shopify'],
            ['shopify.fulfillment_created', 'Fulfillment Shopify créé', 'shopify'],
            ['shopify.tracking_received', 'Suivi Shopify reçu', 'shopify'],
            ['shopify.product_updated', 'Produit Shopify mis à jour', 'shopify'],
            ['shopify.sync_failed', 'Échec de synchro Shopify', 'shopify'],
        ];

        foreach ($items as [$key, $label, $category]) {
            $registry->registerTrigger($key, [
                'label' => $label,
                'category' => $category,
                'config_schema' => match ($key) {
                    'schedule.datetime' => [
                        ['key' => 'at', 'label' => 'Date / heure', 'type' => 'datetime'],
                    ],
                    'schedule.cron' => [
                        ['key' => 'frequency', 'label' => 'Fréquence', 'type' => 'select', 'options' => [
                            ['value' => 'daily', 'label' => 'Quotidien'],
                            ['value' => 'weekly', 'label' => 'Jours de la semaine'],
                            ['value' => 'once', 'label' => 'Une fois'],
                        ]],
                        ['key' => 'time', 'label' => 'Heure', 'type' => 'time'],
                        ['key' => 'days', 'label' => 'Jours (0=dim…6=sam)', 'type' => 'string'],
                    ],
                    'webhook.external' => [
                        ['key' => 'secret', 'label' => 'Secret (optionnel)', 'type' => 'string'],
                    ],
                    default => [],
                },
            ]);
        }
    }

    protected function conditionFields(AutomationRegistry $registry): void
    {
        $fields = [
            ['status', 'Statut commande', 'string', 'commandes'],
            ['confirmation_status', 'Confirmation', 'string', 'confirmation'],
            ['delivery_status', 'Statut livraison', 'string', 'livraison'],
            ['financial_status', 'Paiement', 'string', 'commandes'],
            ['city', 'Ville', 'string', 'adresse'],
            ['address', 'Adresse', 'string', 'adresse'],
            ['amount', 'Montant', 'number', 'commandes'],
            ['payment_method', 'Mode de paiement', 'string', 'commandes'],
            ['source', 'Source', 'string', 'commandes'],
            ['carrier', 'Transporteur', 'string', 'transporteurs'],
            ['driver_id', 'Livreur', 'number', 'livraison'],
            ['assigned_user_id', 'Agent', 'number', 'confirmation'],
            ['customer_name', 'Client', 'string', 'clients'],
            ['phone', 'Téléphone', 'string', 'clients'],
            ['tracking_present', 'Tracking présent', 'boolean', 'transporteurs'],
        ];

        foreach ($fields as [$key, $label, $type, $group]) {
            $ops = match ($type) {
                'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty'],
                'boolean' => ['eq', 'neq'],
                default => ['eq', 'neq', 'contains', 'not_contains', 'empty', 'not_empty', 'in', 'not_in'],
            };
            $registry->registerConditionField($key, [
                'label' => $label,
                'type' => $type,
                'group' => $group,
                'operators' => $ops,
            ]);
        }

        // Extra registry fields (product / tags / vehicle…) — values resolved when subject supports them.
        foreach ([
            ['product', 'Produit', 'string'],
            ['sku', 'SKU', 'string'],
            ['quantity', 'Quantité', 'number'],
            ['tag', 'Tag client', 'string'],
            ['group', 'Groupe client', 'string'],
            ['segment', 'Segment', 'string'],
            ['vehicle_brand', 'Marque véhicule', 'string'],
            ['vehicle_model', 'Modèle véhicule', 'string'],
            ['vehicle_year', 'Année véhicule', 'number'],
            ['orders_count', 'Nb commandes client', 'number'],
            ['shop', 'Boutique', 'string'],
        ] as [$key, $label, $type]) {
            $registry->registerConditionField($key, [
                'label' => $label,
                'type' => $type,
                'group' => 'extensible',
                'operators' => $type === 'number'
                    ? ['eq', 'neq', 'gt', 'gte', 'lt', 'lte']
                    : ['eq', 'neq', 'contains', 'empty', 'not_empty'],
            ]);
        }
    }

    protected function actions(AutomationRegistry $registry): void
    {
        $registry->registerAction(new AddNoteAction);
        $registry->registerAction(new ChangeStatusAction);
        $registry->registerAction(new AssignAgentAction);
        $registry->registerAction(new NotifyAction);
        $registry->registerAction(new WebhookExternalAction);
        $registry->registerAction(new WhatsAppSendMessageAction);
        $registry->registerAction(new ShopifySyncOrderAction);
        $registry->registerAction(new ShopifyCreateFulfillmentAction);
        $registry->registerAction(new ShopifyUpdateTrackingAction);
        $registry->registerAction(new ShopifyAddOrderNoteAction);
        $registry->registerAction(new AddClientTagAction);
        $registry->registerAction(new RemoveClientTagAction);

        foreach ([['speedaf', 'Speedaf'], ['sift', 'Sift.ma'], ['ozon', 'Ozon Express']] as [$key, $label]) {
            $registry->registerAction(new CarrierCreateParcelAction($key, $label));
            $registry->registerAction(new CarrierRefreshTrackingAction($key, $label));
        }
    }
}
