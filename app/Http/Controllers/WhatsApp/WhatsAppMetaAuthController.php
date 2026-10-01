<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\MetaOAuth;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppMetaAuthController extends Controller
{
    public function callback(Request $request, MetaOAuth $meta, WhatsAppCloudClient $client): RedirectResponse
    {
        if (! $meta->isConfigured()) {
            return redirect('/whatsapp/comptes?error=not_configured');
        }

        $state = (string) $request->query('state', '');
        $expected = (string) $request->session()->pull('whatsapp_meta_oauth_state', '');
        if ($state === '' || $expected === '' || ! hash_equals($expected, $state)) {
            return redirect('/whatsapp/comptes?error=state');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect('/whatsapp/comptes?error=oauth');
        }

        $companyId = (int) $request->session()->pull('whatsapp_meta_oauth_company');
        $accountId = $request->session()->pull('whatsapp_meta_oauth_account_id');
        $accountName = $request->session()->pull('whatsapp_meta_oauth_account_name', 'WhatsApp Business');

        try {
            $tokenPayload = $meta->exchangeCode($code);
            $accessToken = $tokenPayload['access_token'] ?? null;
            if (! $accessToken) {
                return redirect('/whatsapp/comptes?error=oauth');
            }

            $businesses = $client->fetchSharedWabas($accessToken);
            $wabaId = null;
            $phone = null;

            foreach ($businesses as $business) {
                $owned = data_get($business, 'owned_whatsapp_business_accounts.data')
                    ?? data_get($business, 'owned_whatsapp_business_accounts')
                    ?? [];
                if (! is_array($owned)) {
                    continue;
                }
                foreach ($owned as $waba) {
                    $wabaId = (string) ($waba['id'] ?? '');
                    $phones = data_get($waba, 'phone_numbers.data') ?? data_get($waba, 'phone_numbers') ?? [];
                    if (is_array($phones) && $phones !== []) {
                        $phone = $phones[0];
                        break 2;
                    }
                }
            }

            if (! $phone && $wabaId) {
                $phones = $client->fetchPhoneNumbers($wabaId, $accessToken);
                $phone = $phones[0] ?? null;
            }

            $phoneNumberId = isset($phone['id']) ? (string) $phone['id'] : null;
            $display = $phone['display_phone_number'] ?? null;
            $verifiedName = $phone['verified_name'] ?? null;

            $account = null;
            if ($accountId) {
                $account = WhatsAppAccount::query()
                    ->where('company_id', $companyId)
                    ->where('id', $accountId)
                    ->first();
            }

            $payload = [
                'company_id' => $companyId ?: 1,
                'name' => $accountName ?: ($verifiedName ?: 'WhatsApp Business'),
                'phone_number' => PhoneNormalizer::digits($display),
                'display_phone_number' => $display,
                'phone_number_id' => $phoneNumberId,
                'waba_id' => $wabaId,
                'access_token' => $accessToken,
                'status' => $phoneNumberId ? WhatsAppAccount::STATUS_CONNECTED : WhatsAppAccount::STATUS_DISCONNECTED,
                'is_active' => true,
                'connection_method' => 'oauth',
                'meta_error' => $phoneNumberId ? null : 'Aucun numéro trouvé — complétez le Phone Number ID manuellement.',
                'last_synced_at' => now(),
            ];

            if ($account) {
                $account->fill($payload)->save();
            } elseif ($phoneNumberId) {
                WhatsAppAccount::query()->updateOrCreate(
                    [
                        'company_id' => $payload['company_id'],
                        'phone_number_id' => $phoneNumberId,
                    ],
                    $payload
                );
            } else {
                WhatsAppAccount::query()->create($payload);
            }

            return redirect('/whatsapp/comptes?connected=1');
        } catch (\Throwable $e) {
            Log::error('WhatsApp Meta OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect('/whatsapp/comptes?error=oauth');
        }
    }
}
