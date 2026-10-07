<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\ClientTag;
use App\Services\Campaigns\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientTagController extends Controller
{
    public function __construct(protected TagService $tags) {}

    protected function companyId(Request $request): int
    {
        return (int) $request->user()->resolveCompanyId();
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->tags->listTags($this->companyId($request))->map(fn (ClientTag $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'color' => $t->color,
            'description' => $t->description,
            'assignments_count' => $t->assignments()->count(),
        ]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $tag = $this->tags->createTag($this->companyId($request), $data, $request->user()->id);

        return response()->json(['data' => [
            'id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color, 'description' => $tag->description,
        ]], 201);
    }

    public function update(Request $request, int $tag): JsonResponse
    {
        $model = ClientTag::query()->forCompany($this->companyId($request))->whereKey($tag)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $model = $this->tags->updateTag($model, $data);

        return response()->json(['data' => [
            'id' => $model->id, 'name' => $model->name, 'color' => $model->color, 'description' => $model->description,
        ]]);
    }

    public function destroy(Request $request, int $tag): JsonResponse
    {
        $model = ClientTag::query()->forCompany($this->companyId($request))->whereKey($tag)->firstOrFail();
        $this->tags->deleteTag($model);

        return response()->json(['message' => 'Tag supprimé.']);
    }

    public function forClient(Request $request, string $key): JsonResponse
    {
        $tags = $this->tags->tagsForClient($this->companyId($request), $key)->map(fn (ClientTag $t) => [
            'id' => $t->id, 'name' => $t->name, 'color' => $t->color,
        ]);

        return response()->json(['data' => $tags]);
    }

    public function assign(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'tag_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
        $companyId = $this->companyId($request);

        if (! empty($data['tag_id'])) {
            $this->tags->assign($companyId, $key, (int) $data['tag_id'], $request->user()->id);
        } else {
            abort_unless(! empty($data['name']), 422, 'tag_id ou name requis.');
            $tag = $this->tags->findOrCreate($companyId, $data['name'], $request->user()->id, $data['color'] ?? null);
            $this->tags->assign($companyId, $key, $tag->id, $request->user()->id);
        }

        return response()->json([
            'message' => 'Tag ajouté.',
            'data' => $this->tags->tagsForClient($companyId, $key)->map(fn (ClientTag $t) => [
                'id' => $t->id, 'name' => $t->name, 'color' => $t->color,
            ]),
        ]);
    }

    public function remove(Request $request, string $key, int $tag): JsonResponse
    {
        $this->tags->remove($this->companyId($request), $key, $tag);

        return response()->json(['message' => 'Tag retiré.']);
    }

    public function sync(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['integer'],
        ]);
        $this->tags->sync($this->companyId($request), $key, $data['tag_ids'], $request->user()->id);

        return response()->json([
            'message' => 'Tags synchronisés.',
            'data' => $this->tags->tagsForClient($this->companyId($request), $key)->map(fn (ClientTag $t) => [
                'id' => $t->id, 'name' => $t->name, 'color' => $t->color,
            ]),
        ]);
    }
}
