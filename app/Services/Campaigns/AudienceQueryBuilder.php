<?php

namespace App\Services\Campaigns;

use App\Models\ClientAudienceSegment;
use App\Models\ClientBlock;
use App\Models\ClientGroupMember;
use App\Models\ClientTagAssignment;
use App\Models\ClientVehicle;
use App\Models\ClientWhatsAppConsent;
use App\Models\Company;
use App\Models\Order;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\Clients\ClientService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generic audience filter engine. Builds SQL from a nested AND/OR definition, scoped by company_id.
 *
 * Definition shape:
 * {
 *   "logic": "and"|"or",
 *   "rules": [
 *     {"type":"tag","operator":"has|not_has|has_any|has_all","tag_ids":[1,2]},
 *     {"type":"group","group_ids":[1],"operator":"in|not_in"},
 *     {"type":"segment","segment_id":1},
 *     {"type":"preset_segment","key":"good"},
 *     {"type":"phone_keys","keys":["2126…"],"operator":"in|not_in"},
 *     {"type":"city","op":"eq|contains","value":"Casablanca"},
 *     {"type":"orders_count","op":"gte","value":3},
 *     {"type":"total_spent","op":"gte","value":1000},
 *     {"type":"last_order_days","op":"gt","value":180},
 *     {"type":"product_purchased","product_id":1,"title":"…"},
 *     {"type":"product_not_purchased","product_id":2,"title":"…"},
 *     {"type":"category","value":"Accessoires","op":"purchased|not_purchased"},
 *     {"type":"vehicle","brand":"Peugeot","model":"208","year_min":2020,"year_max":2025,…},
 *     {"logic":"or","rules":[…]}  // nested group
 *   ]
 * }
 */
class AudienceQueryBuilder
{
    public function __construct(
        protected ClientService $clients,
        protected TagService $tags,
        protected ConsentService $consent,
    ) {}

    /** Base eligible clients (phone_key aggregates). */
    public function baseQuery(): Builder
    {
        return $this->clients->aggregateQuery();
    }

    public function applyDefinition(Builder $q, int $companyId, ?array $definition): Builder
    {
        if (! $definition || empty($definition['rules'])) {
            return $q;
        }

        $logic = strtolower((string) ($definition['logic'] ?? 'and')) === 'or' ? 'or' : 'and';
        $q->where(function (Builder $outer) use ($definition, $companyId, $logic) {
            foreach ($definition['rules'] as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                $method = $logic === 'or' ? 'orWhere' : 'where';
                $outer->{$method}(function (Builder $inner) use ($rule, $companyId) {
                    if (isset($rule['logic'], $rule['rules'])) {
                        $this->applyDefinition($inner, $companyId, $rule);
                    } else {
                        $this->applyRule($inner, $companyId, $rule);
                    }
                });
            }
        });

        return $q;
    }

    protected function applyRule(Builder $q, int $companyId, array $rule): void
    {
        $type = (string) ($rule['type'] ?? '');

        match ($type) {
            'tag' => $this->applyTagRule($q, $companyId, $rule),
            'group' => $this->applyGroupRule($q, $companyId, $rule),
            'segment' => $this->applySavedSegment($q, $companyId, $rule),
            'preset_segment' => $this->applyPresetSegment($q, $rule),
            'phone_keys' => $this->applyPhoneKeys($q, $rule),
            'city' => $this->applyCity($q, $rule),
            'orders_count' => $this->applyNumeric($q, 'c.orders', $rule),
            'total_spent' => $this->applyNumeric($q, 'c.total', $rule),
            'last_order_days' => $this->applyLastOrderDays($q, $rule),
            'product_purchased' => $this->applyProduct($q, $rule, true),
            'product_not_purchased' => $this->applyProduct($q, $rule, false),
            'category' => $this->applyCategory($q, $rule),
            'vehicle' => $this->applyVehicle($q, $companyId, $rule),
            default => null,
        };
    }

    protected function applyTagRule(Builder $q, int $companyId, array $rule): void
    {
        $operator = (string) ($rule['operator'] ?? 'has_any');
        $tagIds = array_map('intval', $rule['tag_ids'] ?? []);
        if ($tagIds === []) {
            $q->whereRaw('0 = 1');

            return;
        }

        $sub = ClientTagAssignment::query()
            ->select('phone_key')
            ->where('company_id', $companyId)
            ->whereIn('client_tag_id', $tagIds);

        if ($operator === 'has_all') {
            $sub->groupBy('phone_key')
                ->havingRaw('COUNT(DISTINCT client_tag_id) >= ?', [count($tagIds)]);
            $q->whereIn('c.phone_key', $sub);
        } elseif (in_array($operator, ['not_has', 'has_none'], true)) {
            $q->whereNotIn('c.phone_key', $sub->distinct());
        } else {
            // has / has_any / contains
            $q->whereIn('c.phone_key', $sub->distinct());
        }
    }

    protected function applyGroupRule(Builder $q, int $companyId, array $rule): void
    {
        $groupIds = array_map('intval', $rule['group_ids'] ?? []);
        if ($groupIds === []) {
            $q->whereRaw('0 = 1');

            return;
        }

        $sub = ClientGroupMember::query()
            ->select('client_group_members.phone_key')
            ->join('client_groups', 'client_groups.id', '=', 'client_group_members.client_group_id')
            ->where('client_groups.company_id', $companyId)
            ->whereIn('client_group_id', $groupIds)
            ->distinct();

        $op = (string) ($rule['operator'] ?? 'in');
        if ($op === 'not_in') {
            $q->whereNotIn('c.phone_key', $sub);
        } else {
            $q->whereIn('c.phone_key', $sub);
        }
    }

    protected function applySavedSegment(Builder $q, int $companyId, array $rule): void
    {
        $id = (int) ($rule['segment_id'] ?? 0);
        $segment = ClientAudienceSegment::query()->forCompany($companyId)->whereKey($id)->first();
        if (! $segment) {
            $q->whereRaw('0 = 1');

            return;
        }
        $this->applyDefinition($q, $companyId, $segment->definition ?? []);
    }

    protected function applyPresetSegment(Builder $q, array $rule): void
    {
        $key = (string) ($rule['key'] ?? '');
        if ($key === '') {
            return;
        }
        $this->clients->applySegment($q, $key);
    }

    protected function applyPhoneKeys(Builder $q, array $rule): void
    {
        $keys = array_values(array_filter(array_map('strval', $rule['keys'] ?? [])));
        $op = (string) ($rule['operator'] ?? 'in');
        if ($keys === []) {
            if ($op === 'in') {
                $q->whereRaw('0 = 1');
            }

            return;
        }
        if ($op === 'not_in') {
            $q->whereNotIn('c.phone_key', $keys);
        } else {
            $q->whereIn('c.phone_key', $keys);
        }
    }

    protected function applyCity(Builder $q, array $rule): void
    {
        $value = trim((string) ($rule['value'] ?? ''));
        if ($value === '') {
            return;
        }
        $op = (string) ($rule['op'] ?? 'eq');
        // shipping_address JSON city
        if ($op === 'contains') {
            $q->where(function (Builder $w) use ($value) {
                $w->where('lo.shipping_address', 'like', '%'.$value.'%')
                    ->orWhere('lo.customer_name', 'like', '%'.$value.'%');
            });
        } else {
            $q->where(function (Builder $w) use ($value) {
                $w->where('lo.shipping_address', 'like', '%"city":"'.$value.'"%')
                    ->orWhere('lo.shipping_address', 'like', '%"city": "'.$value.'"%')
                    ->orWhere('lo.shipping_address', 'like', '%'.$value.'%');
            });
        }
    }

    protected function applyNumeric(Builder $q, string $column, array $rule): void
    {
        $op = (string) ($rule['op'] ?? 'gte');
        $value = $rule['value'] ?? null;
        if ($value === null || $value === '') {
            return;
        }
        $sqlOp = match ($op) {
            'eq' => '=',
            'neq' => '!=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            default => '>=',
        };
        $q->where($column, $sqlOp, $value);
    }

    protected function applyLastOrderDays(Builder $q, array $rule): void
    {
        $op = (string) ($rule['op'] ?? 'gt');
        $days = (int) ($rule['value'] ?? 0);
        $threshold = Carbon::now()->subDays($days);
        if (in_array($op, ['gt', 'gte'], true)) {
            // last order older than N days
            $q->where('c.last_at', $op === 'gte' ? '<=' : '<', $threshold);
        } else {
            $q->where('c.last_at', $op === 'lte' ? '>=' : '>', $threshold);
        }
    }

    protected function applyProduct(Builder $q, array $rule, bool $purchased): void
    {
        $needle = trim((string) ($rule['title'] ?? $rule['sku'] ?? $rule['product_id'] ?? ''));
        if ($needle === '') {
            return;
        }
        $sub = Order::query()
            ->select('phone_key')
            ->whereNotNull('phone_key')
            ->where(function ($w) use ($needle) {
                $w->where('line_items', 'like', '%'.$needle.'%')
                    ->orWhere('shopify_line_items', 'like', '%'.$needle.'%');
            })
            ->distinct();

        if ($purchased) {
            $q->whereIn('c.phone_key', $sub);
        } else {
            $q->whereNotIn('c.phone_key', $sub);
        }
    }

    protected function applyCategory(Builder $q, array $rule): void
    {
        $value = trim((string) ($rule['value'] ?? ''));
        if ($value === '') {
            return;
        }
        $purchased = (($rule['op'] ?? 'purchased') !== 'not_purchased');
        $this->applyProduct($q, ['title' => $value], $purchased);
    }

    /**
     * Client matches if AT LEAST ONE vehicle matches ALL vehicle fields in this rule.
     */
    protected function applyVehicle(Builder $q, int $companyId, array $rule): void
    {
        $sub = ClientVehicle::query()
            ->select('phone_key')
            ->where('company_id', $companyId);

        if (! empty($rule['brand'])) {
            $sub->where('brand', $rule['brand']);
        }
        if (! empty($rule['model'])) {
            $sub->where('model', $rule['model']);
        }
        if (! empty($rule['generation'])) {
            $sub->where('generation', $rule['generation']);
        }
        if (! empty($rule['phase'])) {
            $sub->where('phase', $rule['phase']);
        }
        if (! empty($rule['body_type'])) {
            $sub->where('body_type', $rule['body_type']);
        }
        if (isset($rule['year_min']) && $rule['year_min'] !== '' && $rule['year_min'] !== null) {
            $sub->where('year', '>=', (int) $rule['year_min']);
        }
        if (isset($rule['year_max']) && $rule['year_max'] !== '' && $rule['year_max'] !== null) {
            $sub->where('year', '<=', (int) $rule['year_max']);
        }
        if (isset($rule['year']) && $rule['year'] !== '' && $rule['year'] !== null) {
            $sub->where('year', (int) $rule['year']);
        }

        $q->whereIn('c.phone_key', $sub->distinct());
    }

    /**
     * Full audience resolution for a campaign definition + manual keys + exclusions preview.
     *
     * @return array{query: Builder, company: Company}
     */
    public function audienceQuery(int $companyId, ?array $definition, array $manualKeys = []): Builder
    {
        $q = $this->baseQuery();
        $hasRules = $definition && ! empty($definition['rules']);
        $manualKeys = array_values(array_filter(array_map('strval', $manualKeys)));

        if ($hasRules && $manualKeys !== []) {
            $q->where(function (Builder $outer) use ($companyId, $definition, $manualKeys) {
                $outer->where(function (Builder $inner) use ($companyId, $definition) {
                    $this->applyDefinition($inner, $companyId, $definition);
                })->orWhereIn('c.phone_key', $manualKeys);
            });
        } elseif ($hasRules) {
            $this->applyDefinition($q, $companyId, $definition);
        } elseif ($manualKeys !== []) {
            $q->whereIn('c.phone_key', $manualKeys);
        }

        return $q;
    }

    /**
     * Apply automatic exclusions (blocked, refused consent, invalid phone, recent contact, manual).
     *
     * @return array{included: array<int, object>, excluded: array<string, int>, rows: list<array>}
     */
    public function resolveWithExclusions(
        Company $company,
        ?array $definition,
        array $manualKeys = [],
        array $manualExclusions = [],
        bool $excludeRecentlyContacted = false
    ): array {
        $rows = $this->audienceQuery($company->id, $definition, $manualKeys)->get();
        $excluded = [];
        $included = [];
        $details = [];

        $blocked = ClientBlock::query()
            ->where('company_id', $company->id)
            ->whereNull('unblocked_at')
            ->pluck('phone_key')
            ->flip();

        $manualExclusions = array_flip(array_map('strval', $manualExclusions));

        $recentKeys = [];
        if ($excludeRecentlyContacted) {
            $max = (int) $company->campaignSetting('max_campaigns_per_client', 3);
            $days = (int) $company->campaignSetting('max_campaigns_window_days', 7);
            if ($max > 0 && $days > 0) {
                $recentKeys = WhatsAppCampaignRecipient::query()
                    ->where('company_id', $company->id)
                    ->where('sent_at', '>=', Carbon::now()->subDays($days))
                    ->whereIn('status', [
                        WhatsAppCampaignRecipient::STATUS_SENT,
                        WhatsAppCampaignRecipient::STATUS_DELIVERED,
                        WhatsAppCampaignRecipient::STATUS_READ,
                    ])
                    ->select('phone_key', DB::raw('COUNT(DISTINCT whatsapp_campaign_id) as cnt'))
                    ->groupBy('phone_key')
                    ->having('cnt', '>=', $max)
                    ->pluck('phone_key')
                    ->flip()
                    ->all();
            }
        }

        foreach ($rows as $row) {
            $key = (string) $row->phone_key;
            $reason = null;

            if ($key === '' || ! preg_match('/^\d{8,15}$/', $key)) {
                $reason = 'invalid_phone';
            } elseif (isset($blocked[$key])) {
                $reason = 'blocked';
            } elseif (isset($manualExclusions[$key])) {
                $reason = 'manual_exclusion';
            } elseif ($consentReason = $this->consent->exclusionReason($company, $key)) {
                $reason = $consentReason;
            } elseif (isset($recentKeys[$key])) {
                $reason = 'recently_contacted';
            }

            if ($reason) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
                $details[] = ['phone_key' => $key, 'excluded' => true, 'reason' => $reason, 'row' => $row];
            } else {
                $included[] = $row;
                $details[] = ['phone_key' => $key, 'excluded' => false, 'reason' => null, 'row' => $row];
            }
        }

        return [
            'included' => $included,
            'excluded' => $excluded,
            'excluded_count' => array_sum($excluded),
            'included_count' => count($included),
            'details' => $details,
        ];
    }

    public function count(int $companyId, ?array $definition, array $manualKeys = []): int
    {
        return (int) $this->audienceQuery($companyId, $definition, $manualKeys)->count();
    }

    public function recentlyContactedWarning(Company $company, array $phoneKeys): array
    {
        $max = (int) $company->campaignSetting('max_campaigns_per_client', 3);
        $days = (int) $company->campaignSetting('max_campaigns_window_days', 7);
        if ($phoneKeys === [] || $max <= 0) {
            return ['count' => 0, 'phone_keys' => [], 'max' => $max, 'days' => $days];
        }

        $keys = WhatsAppCampaignRecipient::query()
            ->where('company_id', $company->id)
            ->whereIn('phone_key', $phoneKeys)
            ->where('sent_at', '>=', Carbon::now()->subDays($days))
            ->whereIn('status', [
                WhatsAppCampaignRecipient::STATUS_SENT,
                WhatsAppCampaignRecipient::STATUS_DELIVERED,
                WhatsAppCampaignRecipient::STATUS_READ,
            ])
            ->select('phone_key', DB::raw('COUNT(DISTINCT whatsapp_campaign_id) as cnt'))
            ->groupBy('phone_key')
            ->having('cnt', '>=', $max)
            ->pluck('phone_key')
            ->all();

        return [
            'count' => count($keys),
            'phone_keys' => $keys,
            'max' => $max,
            'days' => $days,
        ];
    }
}
