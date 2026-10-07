<?php

namespace App\Services\Campaigns;

use App\Models\ClientWhatsAppConsent;
use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * WhatsApp marketing consent per client (phone_key), company-scoped.
 */
class ConsentService
{
    public function get(int $companyId, string $phoneKey): ?ClientWhatsAppConsent
    {
        return ClientWhatsAppConsent::query()
            ->where('company_id', $companyId)
            ->where('phone_key', $phoneKey)
            ->first();
    }

    public function getOrUnknown(int $companyId, string $phoneKey): ClientWhatsAppConsent
    {
        return $this->get($companyId, $phoneKey) ?? new ClientWhatsAppConsent([
            'company_id' => $companyId,
            'phone_key' => $phoneKey,
            'status' => ClientWhatsAppConsent::STATUS_UNKNOWN,
        ]);
    }

    public function set(
        int $companyId,
        string $phoneKey,
        string $status,
        ?string $source = null,
        ?int $userId = null,
        ?string $notes = null
    ): ClientWhatsAppConsent {
        abort_unless(in_array($status, [
            ClientWhatsAppConsent::STATUS_ALLOWED,
            ClientWhatsAppConsent::STATUS_REFUSED,
            ClientWhatsAppConsent::STATUS_UNKNOWN,
        ], true), 422, 'Statut de consentement invalide.');

        $row = ClientWhatsAppConsent::query()->firstOrNew([
            'company_id' => $companyId,
            'phone_key' => $phoneKey,
        ]);

        $row->status = $status;
        $row->source = $source;
        $row->updated_by = $userId;
        $row->notes = $notes;
        if ($status === ClientWhatsAppConsent::STATUS_ALLOWED) {
            $row->consented_at = Carbon::now();
            $row->refused_at = null;
        } elseif ($status === ClientWhatsAppConsent::STATUS_REFUSED) {
            $row->refused_at = Carbon::now();
        }
        $row->save();

        return $row->fresh();
    }

    /**
     * Whether a client may receive a marketing campaign for this company.
     * Default: exclude refused; unknown allowed unless require_allowed_consent_for_marketing.
     */
    public function allowsMarketing(Company $company, string $phoneKey): bool
    {
        $consent = $this->get($company->id, $phoneKey);
        $status = $consent?->status ?? ClientWhatsAppConsent::STATUS_UNKNOWN;

        if ($status === ClientWhatsAppConsent::STATUS_REFUSED) {
            // Default: exclude refused. Setting exclude_refused_consent=false allows them.
            return ! (bool) $company->campaignSetting('exclude_refused_consent', true);
        }

        if ((bool) $company->campaignSetting('require_allowed_consent_for_marketing', false)) {
            return $status === ClientWhatsAppConsent::STATUS_ALLOWED;
        }

        return true;
    }

    public function exclusionReason(Company $company, string $phoneKey): ?string
    {
        if ($this->allowsMarketing($company, $phoneKey)) {
            return null;
        }

        $status = $this->get($company->id, $phoneKey)?->status ?? ClientWhatsAppConsent::STATUS_UNKNOWN;

        return $status === ClientWhatsAppConsent::STATUS_REFUSED
            ? 'consent_refused'
            : 'consent_unknown';
    }
}
