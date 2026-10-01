<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppAppSetting;
use App\Services\WhatsApp\MetaOAuth;
use App\Services\WhatsApp\PhoneNormalizer;
use App\Services\WhatsApp\TemplateSyncService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppAccountController extends Controller
{
    protected function companyId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->resolveCompanyId();
    }

    public function index(Request $request, MetaOAuth $meta): JsonResponse
    {
        $companyId = $this->companyId($request);
        $accounts = WhatsAppAccount::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get()
            ->map(fn (WhatsAppAccount $account) => $account->toApiArray());

        return response()->json([
            'accounts' => $accounts,
            'meta' => [
                'configured' => $meta->isConfigured(),
                'app_id' => $meta->appId() ?? '',
                'has_app_secret' => filled($meta->appSecret()),
                'config_id' => $meta->configId() ?? '',
                'graph_api_version' => $meta->graphVersion(),
                'webhook_url' => $meta->webhookUrl(),
                'redirect_uri' => $meta->redirectUri(),
                'has_verify_token' => filled($meta->webhookVerifyToken()),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin()) {
            return response()->json(['message' => 'Accès réservé aux administrateurs.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'display_phone_number' => ['nullable', 'string', 'max:40'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'waba_id' => ['nullable', 'string', 'max:64'],
            'business_portfolio_id' => ['nullable', 'string', 'max:64'],
            'access_token' => ['nullable', 'string'],
            'connection_method' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $companyId = $this->companyId($request);
        $phone = PhoneNormalizer::digits($data['phone_number'] ?? $data['display_phone_number'] ?? '');

        $account = WhatsAppAccount::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'phone_number' => $phone ?: null,
            'display_phone_number' => $data['display_phone_number'] ?? $data['phone_number'] ?? null,
            'phone_number_id' => $data['phone_number_id'] ?? null,
            'waba_id' => $data['waba_id'] ?? null,
            'business_portfolio_id' => $data['business_portfolio_id'] ?? null,
            'access_token' => $data['access_token'] ?? null,
            'connection_method' => $data['connection_method'] ?? 'manual',
            'is_active' => $data['is_active'] ?? true,
            'status' => filled($data['access_token'] ?? null) && filled($data['phone_number_id'] ?? null)
                ? WhatsAppAccount::STATUS_CONNECTED
                : WhatsAppAccount::STATUS_DISCONNECTED,
        ]);

        return response()->json([
            'message' => 'Numéro WhatsApp ajouté.',
            'account' => $account->toApiArray(),
        ], 201);
    }

    public function update(Request $request, WhatsAppAccount $account): JsonResponse
    {
        if ($account->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Accès réservé aux administrateurs.'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'display_phone_number' => ['nullable', 'string', 'max:40'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'waba_id' => ['nullable', 'string', 'max:64'],
            'business_portfolio_id' => ['nullable', 'string', 'max:64'],
            'access_token' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:connected,disconnected,error'],
        ]);

        if (array_key_exists('phone_number', $data) && $data['phone_number']) {
            $data['phone_number'] = PhoneNormalizer::digits($data['phone_number']);
        }

        if (array_key_exists('access_token', $data) && ! filled($data['access_token'])) {
            unset($data['access_token']);
        }

        $account->fill($data);

        if ($account->access_token && $account->phone_number_id && $account->status !== WhatsAppAccount::STATUS_ERROR) {
            $account->status = WhatsAppAccount::STATUS_CONNECTED;
        }

        $account->save();

        return response()->json([
            'message' => 'Compte mis à jour.',
            'account' => $account->fresh()->toApiArray(),
        ]);
    }

    public function destroy(Request $request, WhatsAppAccount $account): JsonResponse
    {
        if ($account->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Accès réservé aux administrateurs.'], 403);
        }

        $account->forceFill([
            'is_active' => false,
            'status' => WhatsAppAccount::STATUS_DISCONNECTED,
            'access_token' => null,
        ])->save();

        return response()->json(['message' => 'Numéro déconnecté.']);
    }

    public function saveMetaCredentials(Request $request, MetaOAuth $meta): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Accès réservé aux administrateurs.'], 403);
        }

        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:64'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'config_id' => ['nullable', 'string', 'max:64'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'graph_api_version' => ['nullable', 'string', 'max:20'],
        ]);

        $settings = WhatsAppAppSetting::current();
        $secret = trim((string) ($data['app_secret'] ?? ''));

        if ($secret === '' && ! filled($meta->appSecret())) {
            return response()->json([
                'message' => 'Le App Secret Meta est obligatoire pour la première configuration.',
            ], 422);
        }

        $settings->forceFill([
            'app_id' => trim($data['app_id']),
            'config_id' => trim((string) ($data['config_id'] ?? '')) ?: null,
            'webhook_verify_token' => trim((string) ($data['webhook_verify_token'] ?? ''))
                ?: $settings->webhook_verify_token
                ?: bin2hex(random_bytes(16)),
            'graph_api_version' => trim((string) ($data['graph_api_version'] ?? '')) ?: 'v21.0',
        ]);

        if ($secret !== '') {
            $settings->app_secret = $secret;
        }

        $settings->save();

        return response()->json([
            'message' => 'Identifiants Meta enregistrés.',
            'meta' => [
                'configured' => $meta->isConfigured(),
                'app_id' => $meta->appId(),
                'has_app_secret' => filled($meta->appSecret()),
                'config_id' => $meta->configId(),
                'webhook_url' => $meta->webhookUrl(),
                'redirect_uri' => $meta->redirectUri(),
                'has_verify_token' => filled($meta->webhookVerifyToken()),
            ],
        ]);
    }

    public function startMetaConnect(Request $request, MetaOAuth $meta): JsonResponse
    {
        if (! $meta->isConfigured()) {
            return response()->json([
                'message' => 'Configurez d’abord l’App ID et le App Secret Meta.',
            ], 422);
        }

        $data = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'account_name' => ['nullable', 'string', 'max:120'],
        ]);

        $state = $meta->createNonce();
        $request->session()->put('whatsapp_meta_oauth_state', $state);
        $request->session()->put('whatsapp_meta_oauth_company', $this->companyId($request));
        $request->session()->put('whatsapp_meta_oauth_account_id', $data['account_id'] ?? null);
        $request->session()->put('whatsapp_meta_oauth_account_name', $data['account_name'] ?? 'WhatsApp Business');

        return response()->json([
            'authorization_url' => $meta->authorizationUrl($state),
            'embedded_signup' => [
                'app_id' => $meta->appId(),
                'config_id' => $meta->configId(),
                'graph_api_version' => $meta->graphVersion(),
                'state' => $state,
            ],
        ]);
    }

    public function completeEmbeddedSignup(Request $request, MetaOAuth $meta, WhatsAppCloudClient $client): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Accès réservé aux administrateurs.'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
            'waba_id' => ['nullable', 'string', 'max:64'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'business_id' => ['nullable', 'string', 'max:64'],
            'account_id' => ['nullable', 'integer'],
            'account_name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $tokenPayload = $meta->exchangeEmbeddedSignupCode($data['code']);
            $accessToken = $tokenPayload['access_token'] ?? null;
            if (! $accessToken) {
                return response()->json(['message' => 'Token Meta manquant.'], 422);
            }

            $companyId = $this->companyId($request);
            $wabaId = $data['waba_id'] ?? null;
            $phoneNumberId = $data['phone_number_id'] ?? null;
            $display = null;
            $verifiedName = null;

            if ($wabaId) {
                $phones = $client->fetchPhoneNumbers($wabaId, $accessToken);
                $match = collect($phones)->first(function ($phone) use ($phoneNumberId) {
                    return ! $phoneNumberId || (string) ($phone['id'] ?? '') === (string) $phoneNumberId;
                }) ?: ($phones[0] ?? null);

                if ($match) {
                    $phoneNumberId = (string) ($match['id'] ?? $phoneNumberId);
                    $display = $match['display_phone_number'] ?? null;
                    $verifiedName = $match['verified_name'] ?? null;
                }
            }

            $account = null;
            if (! empty($data['account_id'])) {
                $account = WhatsAppAccount::query()
                    ->where('company_id', $companyId)
                    ->where('id', $data['account_id'])
                    ->first();
            }

            if (! $account && $phoneNumberId) {
                $account = WhatsAppAccount::query()
                    ->where('company_id', $companyId)
                    ->where('phone_number_id', $phoneNumberId)
                    ->first();
            }

            $payload = [
                'company_id' => $companyId,
                'name' => $data['account_name'] ?? $verifiedName ?? $account?->name ?? 'WhatsApp Business',
                'phone_number' => PhoneNormalizer::digits($display),
                'display_phone_number' => $display,
                'phone_number_id' => $phoneNumberId,
                'waba_id' => $wabaId,
                'business_portfolio_id' => $data['business_id'] ?? null,
                'access_token' => $accessToken,
                'status' => $phoneNumberId ? WhatsAppAccount::STATUS_CONNECTED : WhatsAppAccount::STATUS_DISCONNECTED,
                'is_active' => true,
                'connection_method' => 'embedded_signup',
                'meta_error' => null,
                'last_synced_at' => now(),
            ];

            if ($account) {
                $account->fill($payload)->save();
            } else {
                $account = WhatsAppAccount::query()->create($payload);
            }

            return response()->json([
                'message' => 'Compte WhatsApp connecté via Meta.',
                'account' => $account->fresh()->toApiArray(),
            ]);
        } catch (\Throwable $e) {
            Log::error('WhatsApp Embedded Signup failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Échec de la connexion Meta : '.$e->getMessage(),
            ], 500);
        }
    }

    public function sync(Request $request, WhatsAppAccount $account, TemplateSyncService $templates): JsonResponse
    {
        if ($account->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        if (! $account->isConnected()) {
            return response()->json(['message' => 'Compte non connecté.'], 422);
        }

        try {
            $count = $templates->syncAccount($account);

            return response()->json([
                'message' => "{$count} template(s) synchronisé(s).",
                'synced' => $count,
                'account' => $account->fresh()->toApiArray(),
            ]);
        } catch (\Throwable $e) {
            $account->forceFill([
                'status' => WhatsAppAccount::STATUS_ERROR,
                'meta_error' => $e->getMessage(),
            ])->save();

            return response()->json([
                'message' => 'Synchronisation impossible : '.$e->getMessage(),
            ], 500);
        }
    }

    public function migrationChecklist(Request $request): JsonResponse
    {
        return response()->json([
            'title' => 'Migrer un numéro existant',
            'warning' => 'Ne jamais déconnecter l’ancien prestataire avant d’avoir vérifié que le numéro peut être repris correctement dans Lavfast Flow.',
            'steps' => [
                'Vérifier le Business Portfolio actuel du numéro.',
                'Vérifier le WhatsApp Business Account (WABA) actuel.',
                'Noter le Phone Number ID Meta.',
                'Confirmer la propriété du numéro (OTP / ownership).',
                'Vérifier les droits administrateur Meta Business.',
                'Lire les conditions de migration imposées par Meta.',
                'Connecter le numéro dans Lavfast Flow via Meta.',
                'Valider la réception des webhooks et l’envoi d’un message test.',
                'Seulement ensuite, couper l’ancien prestataire.',
            ],
            'accounts' => WhatsAppAccount::query()
                ->where('company_id', $this->companyId($request))
                ->get()
                ->map(fn (WhatsAppAccount $a) => $a->toApiArray()),
        ]);
    }
}
