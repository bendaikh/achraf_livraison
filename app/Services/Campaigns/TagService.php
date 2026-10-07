<?php

namespace App\Services\Campaigns;

use App\Models\ClientTag;
use App\Models\ClientTagAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reusable client tag service (campaigns + future Automations add/remove tag actions).
 * All operations are scoped by company_id + phone_key.
 */
class TagService
{
    public function listTags(int $companyId): Collection
    {
        return ClientTag::query()->forCompany($companyId)->orderBy('name')->get();
    }

    public function findOrCreate(int $companyId, string $name, ?int $userId = null, ?string $color = null): ClientTag
    {
        $name = trim($name);
        abort_if($name === '', 422, 'Nom de tag requis.');

        $tag = ClientTag::query()->forCompany($companyId)->where('name', $name)->first();
        if ($tag) {
            return $tag;
        }

        return ClientTag::query()->create([
            'company_id' => $companyId,
            'name' => $name,
            'color' => $color ?: '#2563eb',
            'created_by' => $userId,
        ]);
    }

    public function createTag(int $companyId, array $data, ?int $userId = null): ClientTag
    {
        return ClientTag::query()->create([
            'company_id' => $companyId,
            'name' => trim($data['name']),
            'color' => $data['color'] ?? '#2563eb',
            'description' => $data['description'] ?? null,
            'created_by' => $userId,
        ]);
    }

    public function updateTag(ClientTag $tag, array $data): ClientTag
    {
        $tag->forceFill([
            'name' => array_key_exists('name', $data) ? trim($data['name']) : $tag->name,
            'color' => $data['color'] ?? $tag->color,
            'description' => array_key_exists('description', $data) ? $data['description'] : $tag->description,
        ])->save();

        return $tag->fresh();
    }

    public function deleteTag(ClientTag $tag): void
    {
        $tag->delete();
    }

    /** @return Collection<int, ClientTag> */
    public function tagsForClient(int $companyId, string $phoneKey): Collection
    {
        return ClientTag::query()
            ->forCompany($companyId)
            ->whereIn('id', ClientTagAssignment::query()
                ->where('company_id', $companyId)
                ->where('phone_key', $phoneKey)
                ->select('client_tag_id'))
            ->orderBy('name')
            ->get();
    }

    public function assign(int $companyId, string $phoneKey, int $tagId, ?int $userId = null): ClientTagAssignment
    {
        $tag = ClientTag::query()->forCompany($companyId)->whereKey($tagId)->firstOrFail();

        return ClientTagAssignment::query()->firstOrCreate(
            [
                'client_tag_id' => $tag->id,
                'phone_key' => $phoneKey,
            ],
            [
                'company_id' => $companyId,
                'assigned_by' => $userId,
            ]
        );
    }

    public function assignByName(int $companyId, string $phoneKey, string $tagName, ?int $userId = null): ClientTagAssignment
    {
        $tag = $this->findOrCreate($companyId, $tagName, $userId);

        return $this->assign($companyId, $phoneKey, $tag->id, $userId);
    }

    public function remove(int $companyId, string $phoneKey, int $tagId): void
    {
        ClientTagAssignment::query()
            ->where('company_id', $companyId)
            ->where('phone_key', $phoneKey)
            ->where('client_tag_id', $tagId)
            ->delete();
    }

    public function sync(int $companyId, string $phoneKey, array $tagIds, ?int $userId = null): void
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        $valid = ClientTag::query()->forCompany($companyId)->whereIn('id', $tagIds)->pluck('id')->all();

        DB::transaction(function () use ($companyId, $phoneKey, $valid, $userId) {
            ClientTagAssignment::query()
                ->where('company_id', $companyId)
                ->where('phone_key', $phoneKey)
                ->whereNotIn('client_tag_id', $valid)
                ->delete();

            foreach ($valid as $tagId) {
                $this->assign($companyId, $phoneKey, $tagId, $userId);
            }
        });
    }

    /** Phone keys that match a tag operator. */
    public function phoneKeysMatching(int $companyId, string $operator, array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if ($tagIds === []) {
            return [];
        }

        $base = ClientTagAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('client_tag_id', $tagIds);

        return match ($operator) {
            'has', 'has_any' => $base->distinct()->pluck('phone_key')->all(),
            'has_all' => ClientTagAssignment::query()
                ->where('company_id', $companyId)
                ->whereIn('client_tag_id', $tagIds)
                ->groupBy('phone_key')
                ->havingRaw('COUNT(DISTINCT client_tag_id) >= ?', [count($tagIds)])
                ->pluck('phone_key')
                ->all(),
            'has_none', 'not_has' => array_values(array_diff(
                ClientTagAssignment::query()->where('company_id', $companyId)->distinct()->pluck('phone_key')->all(),
                $base->distinct()->pluck('phone_key')->all()
            )),
            default => [],
        };
    }
}
