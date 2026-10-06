<?php

namespace Database\Seeders;

use App\Models\AutomationTemplate;
use Illuminate\Database\Seeder;

class AutomationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'slug' => 'nouvelle-commande-whatsapp',
                'name' => 'Nouvelle commande → WhatsApp',
                'description' => 'Envoie un message WhatsApp à la création d’une commande.',
                'category' => 'whatsapp',
                'trigger_type' => 'order.created',
                'integrations' => ['whatsapp'],
                'definition' => [
                    'entry' => 'send_wa',
                    'steps' => [
                        'send_wa' => [
                            'type' => 'action',
                            'action' => 'whatsapp.send_message',
                            'config' => [
                                'to' => '{{order.phone}}',
                                'body' => 'Bonjour {{order.customer_name}}, nous avons bien reçu votre commande {{order.name}}.',
                                'language' => 'fr',
                            ],
                            'next' => null,
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'confirmation-whatsapp',
                'name' => 'Confirmation → WhatsApp',
                'description' => 'Message WhatsApp quand la commande est confirmée.',
                'category' => 'whatsapp',
                'trigger_type' => 'order.confirmed',
                'integrations' => ['whatsapp'],
                'definition' => [
                    'entry' => 'send_wa',
                    'steps' => [
                        'send_wa' => [
                            'type' => 'action',
                            'action' => 'whatsapp.send_message',
                            'config' => [
                                'to' => '{{order.phone}}',
                                'template' => 'order_confirmed',
                                'language' => 'fr',
                            ],
                            'next' => null,
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'pas-de-reponse-rappel',
                'name' => 'Pas de réponse → rappel',
                'description' => 'Attend 2 h puis rappelle le client s’il n’a toujours pas répondu.',
                'category' => 'confirmation',
                'trigger_type' => 'order.no_answer',
                'integrations' => ['whatsapp'],
                'definition' => [
                    'entry' => 'wait_2h',
                    'steps' => [
                        'wait_2h' => [
                            'type' => 'wait',
                            'amount' => 2,
                            'unit' => 'hours',
                            'recheck_conditions' => true,
                            'conditions' => [
                                'logic' => 'and',
                                'rules' => [
                                    ['field' => 'confirmation_status', 'op' => 'eq', 'value' => 'no_answer'],
                                ],
                            ],
                            'next' => 'send_rappel',
                        ],
                        'send_rappel' => [
                            'type' => 'action',
                            'action' => 'whatsapp.send_message',
                            'config' => [
                                'to' => '{{order.phone}}',
                                'body' => 'Bonjour, nous n’avons pas pu vous joindre pour la commande {{order.name}}. Merci de nous rappeler.',
                            ],
                            'next' => null,
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'tracking-shopify-whatsapp',
                'name' => 'Tracking → Shopify + WhatsApp',
                'description' => 'Après création d’un tracking, sync Shopify puis message client.',
                'category' => 'transporteurs',
                'trigger_type' => 'tracking.created',
                'integrations' => ['shopify', 'whatsapp'],
                'definition' => [
                    'entry' => 'shopify_sync',
                    'steps' => [
                        'shopify_sync' => [
                            'type' => 'action',
                            'action' => 'shopify.sync_order',
                            'config' => [
                                'push_tracking' => true,
                                'tracking_number' => '{{vars.trackingNumber}}',
                            ],
                            'next' => 'send_wa',
                        ],
                        'send_wa' => [
                            'type' => 'action',
                            'action' => 'whatsapp.send_message',
                            'config' => [
                                'to' => '{{order.phone}}',
                                'body' => 'Votre colis {{order.name}} est en route. Tracking : {{vars.trackingNumber}}',
                            ],
                            'next' => null,
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'livree-message',
                'name' => 'Commande livrée → message',
                'description' => 'Message de remerciement à la livraison.',
                'category' => 'livraison',
                'trigger_type' => 'order.delivered',
                'integrations' => ['whatsapp'],
                'definition' => [
                    'entry' => 'send_wa',
                    'steps' => [
                        'send_wa' => [
                            'type' => 'action',
                            'action' => 'whatsapp.send_message',
                            'config' => [
                                'to' => '{{order.phone}}',
                                'body' => 'Merci {{order.customer_name}} ! Votre commande {{order.name}} a été livrée.',
                            ],
                            'next' => null,
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'retour-message',
                'name' => 'Retour → message',
                'description' => 'Informe le client qu’un retour a été créé.',
                'category' => 'sav',
                'trigger_type' => 'return.created',
                'integrations' => ['whatsapp'],
                'definition' => [
                    'entry' => 'send_wa',
                    'steps' => [
                        'send_wa' => [
                            'type' => 'action',
                            'action' => 'whatsapp.send_message',
                            'config' => [
                                'to' => '{{order.phone}}',
                                'body' => 'Un retour a été enregistré pour votre commande {{order.name}}.',
                            ],
                            'next' => null,
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'ville-note-statut',
                'name' => 'Exemple · Ville → note + statut',
                'description' => 'Démo moteur : nouvelle commande, si ville = Casablanca → attendre → note interne.',
                'category' => 'demo',
                'trigger_type' => 'order.created',
                'integrations' => ['internal'],
                'definition' => [
                    'entry' => 'if_city',
                    'steps' => [
                        'if_city' => [
                            'type' => 'condition',
                            'logic' => 'and',
                            'rules' => [
                                ['field' => 'city', 'op' => 'eq', 'value' => 'Casablanca'],
                            ],
                            'then' => 'wait_stub',
                            'else' => null,
                        ],
                        'wait_stub' => [
                            'type' => 'wait',
                            'amount' => 0,
                            'unit' => 'minutes',
                            'recheck_conditions' => true,
                            'conditions' => [
                                'logic' => 'and',
                                'rules' => [
                                    ['field' => 'city', 'op' => 'eq', 'value' => 'Casablanca'],
                                ],
                            ],
                            'next' => 'add_note',
                        ],
                        'add_note' => [
                            'type' => 'action',
                            'action' => 'internal.add_note',
                            'config' => [
                                'note' => 'Automatisation : commande Casablanca {{order.name}}',
                                'field' => 'internal_note',
                            ],
                            'next' => null,
                        ],
                    ],
                ],
            ],
        ];

        foreach ($templates as $i => $tpl) {
            AutomationTemplate::query()->updateOrCreate(
                ['company_id' => null, 'slug' => $tpl['slug']],
                [
                    'name' => $tpl['name'],
                    'description' => $tpl['description'],
                    'category' => $tpl['category'],
                    'trigger_type' => $tpl['trigger_type'],
                    'trigger_config' => null,
                    'definition' => $tpl['definition'],
                    'integrations' => $tpl['integrations'],
                    'is_active' => true,
                    'position' => $i + 1,
                ]
            );
        }
    }
}
