<?php

namespace Tests\Feature;

use App\Models\ClientBlock;
use App\Models\ClientTag;
use App\Models\ClientTagAssignment;
use App\Models\ClientVehicle;
use App\Models\ClientWhatsAppConsent;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\Campaigns\AudienceQueryBuilder;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Campaigns\CampaignStatusUpdater;
use App\Services\Campaigns\TagService;
use App\Services\WhatsApp\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppCampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected User $admin;

    protected Company $company;

    protected WhatsAppAccount $account;

    protected WhatsAppTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAdmin();
        $this->company = Company::query()->findOrFail($this->admin->resolveCompanyId());
        $this->company->forceFill([
            'timezone' => 'Africa/Casablanca',
            'features' => ['client_vehicles' => true],
            'campaign_settings' => [
                'exclude_refused_consent' => true,
                'batch_size' => 50,
                'rate_limit_per_minute' => 600,
            ],
        ])->save();

        $this->account = WhatsAppAccount::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Test WA',
            'phone_number' => '+212600000001',
            'display_phone_number' => '+212 600-000001',
            'phone_number_id' => 'pnid-test-1',
            'waba_id' => 'waba-1',
            'access_token' => 'test-token',
            'status' => WhatsAppAccount::STATUS_CONNECTED,
            'is_active' => true,
        ]);

        $this->template = WhatsAppTemplate::query()->create([
            'company_id' => $this->company->id,
            'whatsapp_account_id' => $this->account->id,
            'name' => 'promo_accessoires',
            'language' => 'fr',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'body_text' => 'Bonjour {{1}}, offre pour votre {{2}} {{3}}.',
            'variables_count' => 3,
            'components' => [],
        ]);
    }

    protected function makeClient(string $phone, string $name = 'Client', array $orderExtra = []): string
    {
        $order = $this->order(array_merge([
            'customer_name' => $name,
            'customer_phone' => $phone,
            'city' => 'Casablanca',
            'address' => 'Rue Test',
            'product_name' => 'Tapis intérieur',
            'amount' => 500,
        ], $orderExtra));

        $key = $order->fresh()->phone_key;
        $this->assertNotEmpty($key);

        return $key;
    }

    protected function makeCampaign(array $attrs = []): WhatsAppCampaign
    {
        return WhatsAppCampaign::query()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Promo Peugeot',
            'status' => WhatsAppCampaign::STATUS_DRAFT,
            'whatsapp_account_id' => $this->account->id,
            'whatsapp_template_id' => $this->template->id,
            'audience_definition' => ['logic' => 'and', 'rules' => []],
            'variable_mapping' => [
                ['slot' => 1, 'source' => 'client_name'],
                ['slot' => 2, 'source' => 'vehicle_brand'],
                ['slot' => 3, 'source' => 'vehicle_model'],
            ],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $attrs));
    }

    public function test_audience_and_or_tags_four_operators_and_vehicle(): void
    {
        $keyA = $this->makeClient('0611111111', 'Youssef');
        $keyB = $this->makeClient('0622222222', 'Sara');
        $keyC = $this->makeClient('0633333333', 'Omar');

        $tagAcc = ClientTag::query()->create(['company_id' => $this->company->id, 'name' => 'Client accessoires', 'color' => '#0d9488']);
        $tagVip = ClientTag::query()->create(['company_id' => $this->company->id, 'name' => 'VIP', 'color' => '#ca8a04']);

        /** @var TagService $tags */
        $tags = app(TagService::class);
        $tags->assign($this->company->id, $keyA, $tagAcc->id);
        $tags->assign($this->company->id, $keyA, $tagVip->id);
        $tags->assign($this->company->id, $keyB, $tagAcc->id);
        // keyC: no tags

        ClientVehicle::query()->create([
            'company_id' => $this->company->id,
            'phone_key' => $keyA,
            'brand' => 'Peugeot',
            'model' => '208',
            'year' => 2022,
            'is_primary' => true,
        ]);
        ClientVehicle::query()->create([
            'company_id' => $this->company->id,
            'phone_key' => $keyB,
            'brand' => 'Renault',
            'model' => 'Clio',
            'year' => 2019,
            'is_primary' => true,
        ]);

        /** @var AudienceQueryBuilder $audience */
        $audience = app(AudienceQueryBuilder::class);

        $hasAny = $audience->audienceQuery($this->company->id, [
            'logic' => 'and',
            'rules' => [['type' => 'tag', 'operator' => 'has_any', 'tag_ids' => [$tagAcc->id, $tagVip->id]]],
        ])->pluck('phone_key')->all();
        $this->assertEqualsCanonicalizing([$keyA, $keyB], $hasAny);

        $hasAll = $audience->audienceQuery($this->company->id, [
            'logic' => 'and',
            'rules' => [['type' => 'tag', 'operator' => 'has_all', 'tag_ids' => [$tagAcc->id, $tagVip->id]]],
        ])->pluck('phone_key')->all();
        $this->assertEqualsCanonicalizing([$keyA], $hasAll);

        $has = $audience->audienceQuery($this->company->id, [
            'logic' => 'and',
            'rules' => [['type' => 'tag', 'operator' => 'has', 'tag_ids' => [$tagVip->id]]],
        ])->pluck('phone_key')->all();
        $this->assertEqualsCanonicalizing([$keyA], $has);

        $notHas = $audience->audienceQuery($this->company->id, [
            'logic' => 'and',
            'rules' => [['type' => 'tag', 'operator' => 'not_has', 'tag_ids' => [$tagAcc->id]]],
        ])->pluck('phone_key')->all();
        $this->assertContains($keyC, $notHas);
        $this->assertNotContains($keyA, $notHas);
        $this->assertNotContains($keyB, $notHas);

        $vehicleAndTag = $audience->audienceQuery($this->company->id, [
            'logic' => 'and',
            'rules' => [
                ['type' => 'tag', 'operator' => 'has_any', 'tag_ids' => [$tagAcc->id]],
                ['type' => 'vehicle', 'brand' => 'Peugeot', 'model' => '208', 'year_min' => 2020, 'year_max' => 2025],
            ],
        ])->pluck('phone_key')->all();
        $this->assertEqualsCanonicalizing([$keyA], $vehicleAndTag);

        $orCity = $audience->audienceQuery($this->company->id, [
            'logic' => 'or',
            'rules' => [
                ['type' => 'vehicle', 'brand' => 'Peugeot', 'model' => '208'],
                ['type' => 'phone_keys', 'keys' => [$keyC], 'operator' => 'in'],
            ],
        ])->pluck('phone_key')->all();
        $this->assertEqualsCanonicalizing([$keyA, $keyC], $orCity);
    }

    public function test_exclusions_and_consent(): void
    {
        $ok = $this->makeClient('0644444444', 'Ok');
        $blocked = $this->makeClient('0655555555', 'Blocked');
        $refused = $this->makeClient('0666666666', 'Refused');

        ClientBlock::query()->create([
            'company_id' => $this->company->id,
            'phone_key' => $blocked,
            'reason' => 'spam',
            'blocked_by' => $this->admin->id,
            'blocked_at' => now(),
        ]);
        ClientWhatsAppConsent::query()->create([
            'company_id' => $this->company->id,
            'phone_key' => $refused,
            'status' => ClientWhatsAppConsent::STATUS_REFUSED,
            'refused_at' => now(),
            'source' => 'test',
        ]);

        $resolved = app(AudienceQueryBuilder::class)->resolveWithExclusions(
            $this->company->fresh(),
            ['logic' => 'and', 'rules' => [['type' => 'phone_keys', 'keys' => [$ok, $blocked, $refused], 'operator' => 'in']]],
        );

        $this->assertSame(1, $resolved['included_count']);
        $this->assertSame($ok, $resolved['included'][0]->phone_key);
        $this->assertGreaterThanOrEqual(1, $resolved['excluded']['blocked'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $resolved['excluded']['consent_refused'] ?? 0);
    }

    public function test_company_isolation(): void
    {
        $mine = $this->makeCampaign(['name' => 'Mine']);
        $other = Company::query()->create(['name' => 'Autre Co', 'slug' => 'autre-camp', 'is_active' => true]);
        $theirs = WhatsAppCampaign::query()->create([
            'company_id' => $other->id,
            'name' => 'Theirs',
            'status' => WhatsAppCampaign::STATUS_DRAFT,
            'audience_definition' => ['logic' => 'and', 'rules' => []],
        ]);

        $this->getJson('/api/whatsapp/campaigns')->assertOk()
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/whatsapp/campaigns/'.$theirs->id)->assertNotFound();
        $this->getJson('/api/whatsapp/campaigns/'.$mine->id)->assertOk();

        ClientTag::query()->create(['company_id' => $other->id, 'name' => 'Secret', 'color' => '#000']);
        $this->getJson('/api/client-tags')->assertOk()->assertJsonMissing(['name' => 'Secret']);
    }

    public function test_send_queue_idempotent_and_pause_resume(): void
    {
        $key = $this->makeClient('0677777777', 'Youssef');
        ClientVehicle::query()->create([
            'company_id' => $this->company->id,
            'phone_key' => $key,
            'brand' => 'Peugeot',
            'model' => '208',
            'year' => 2021,
            'is_primary' => true,
        ]);

        $campaign = $this->makeCampaign([
            'manual_phone_keys' => [$key],
            'audience_definition' => ['logic' => 'and', 'rules' => []],
        ]);

        $this->postJson('/api/whatsapp/campaigns/'.$campaign->id.'/send', [
            'mode' => 'now',
            'confirm' => true,
        ])->assertOk();

        $campaign->refresh();
        $this->assertContains($campaign->status, [
            WhatsAppCampaign::STATUS_RUNNING,
            WhatsAppCampaign::STATUS_COMPLETED,
        ]);

        $recipient = WhatsAppCampaignRecipient::query()
            ->where('whatsapp_campaign_id', $campaign->id)
            ->where('phone_key', $key)
            ->first();
        $this->assertNotNull($recipient);
        $this->assertSame(WhatsAppCampaignRecipient::STATUS_SENT, $recipient->status);
        $this->assertNotEmpty($recipient->wa_message_id);
        $firstWamid = $recipient->wa_message_id;

        // Idempotence: second sendRecipient must not create another message
        app(\App\Services\Campaigns\CampaignSender::class)->sendRecipient($recipient->fresh());
        $recipient->refresh();
        $this->assertSame($firstWamid, $recipient->wa_message_id);
        $this->assertSame(1, WhatsAppMessage::query()->where('wa_message_id', $firstWamid)->count());

        // Pause / resume path
        $key2 = $this->makeClient('0688888888', 'Amine');
        $campaign2 = $this->makeCampaign([
            'name' => 'Pause test',
            'manual_phone_keys' => [$key2],
        ]);
        // Snapshot without sending: create pending recipient manually then pause before batches
        WhatsAppCampaignRecipient::query()->create([
            'company_id' => $this->company->id,
            'whatsapp_campaign_id' => $campaign2->id,
            'phone_key' => $key2,
            'phone' => $key2,
            'status' => WhatsAppCampaignRecipient::STATUS_PENDING,
        ]);
        $campaign2->forceFill(['status' => WhatsAppCampaign::STATUS_RUNNING, 'started_at' => now()])->save();

        $this->postJson('/api/whatsapp/campaigns/'.$campaign2->id.'/pause')->assertOk()
            ->assertJsonPath('data.status', 'paused');

        // Batch job must no-op while paused
        (new \App\Jobs\Campaigns\ProcessCampaignBatchJob(
            $campaign2->id,
            [$campaign2->recipients()->first()->id]
        ))->handle(
            app(\App\Services\Campaigns\CampaignSender::class),
            app(\App\Services\Campaigns\CampaignStatsService::class),
            app(CampaignLauncher::class)
        );
        $this->assertSame(
            WhatsAppCampaignRecipient::STATUS_PENDING,
            $campaign2->recipients()->first()->fresh()->status
        );

        $this->postJson('/api/whatsapp/campaigns/'.$campaign2->id.'/resume')->assertOk();
        $campaign2->refresh();
        $this->assertContains($campaign2->status, [
            WhatsAppCampaign::STATUS_RUNNING,
            WhatsAppCampaign::STATUS_COMPLETED,
        ]);
        $this->assertSame(
            WhatsAppCampaignRecipient::STATUS_SENT,
            $campaign2->recipients()->first()->fresh()->status
        );
    }

    public function test_webhook_updates_campaign_recipient_status(): void
    {
        $key = $this->makeClient('0699999999', 'Nadia');
        $campaign = $this->makeCampaign(['manual_phone_keys' => [$key]]);
        $this->postJson('/api/whatsapp/campaigns/'.$campaign->id.'/send', [
            'mode' => 'now',
            'confirm' => true,
        ])->assertOk();

        $recipient = WhatsAppCampaignRecipient::query()
            ->where('whatsapp_campaign_id', $campaign->id)
            ->where('phone_key', $key)
            ->firstOrFail();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => $this->account->phone_number_id],
                        'statuses' => [[
                            'id' => $recipient->wa_message_id,
                            'status' => 'delivered',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];

        app(WebhookProcessor::class)->process($payload);

        $recipient->refresh();
        $this->assertSame(WhatsAppCampaignRecipient::STATUS_DELIVERED, $recipient->status);
        $this->assertNotNull($recipient->delivered_at);

        $payload['entry'][0]['changes'][0]['value']['statuses'][0]['status'] = 'read';
        $payload['entry'][0]['changes'][0]['value']['statuses'][0]['timestamp'] = (string) (now()->timestamp + 1);
        app(WebhookProcessor::class)->process($payload);

        $recipient->refresh();
        $this->assertSame(WhatsAppCampaignRecipient::STATUS_READ, $recipient->status);

        app(CampaignStatusUpdater::class)->syncFromMessage(
            WhatsAppMessage::query()->where('wa_message_id', $recipient->wa_message_id)->firstOrFail()
        );
    }

    public function test_crud_and_duplicate(): void
    {
        $res = $this->postJson('/api/whatsapp/campaigns', [
            'name' => 'Nouvelle',
            'whatsapp_account_id' => $this->account->id,
            'whatsapp_template_id' => $this->template->id,
            'audience_definition' => [
                'logic' => 'and',
                'rules' => [
                    ['type' => 'tag', 'operator' => 'has_any', 'tag_ids' => [1]],
                ],
            ],
        ])->assertCreated();

        $id = $res->json('data.id');
        $this->postJson('/api/whatsapp/campaigns/'.$id.'/duplicate')->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name', 'Copie — Nouvelle');
    }

    public function test_tag_service_on_client_api(): void
    {
        $key = $this->makeClient('0601010101', 'TagMe');
        $this->postJson('/api/clients/'.$key.'/tags', ['name' => 'Accessoires'])->assertOk();
        $this->getJson('/api/clients/'.$key)->assertOk()
            ->assertJsonFragment(['name' => 'Accessoires']);
        $tagId = ClientTagAssignment::query()->where('phone_key', $key)->value('client_tag_id');
        $this->deleteJson('/api/clients/'.$key.'/tags/'.$tagId)->assertOk();
        $this->assertDatabaseMissing('client_tag_assignments', [
            'phone_key' => $key,
            'client_tag_id' => $tagId,
        ]);
    }
}
