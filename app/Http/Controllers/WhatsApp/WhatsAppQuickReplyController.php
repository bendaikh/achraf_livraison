<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppQuickReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppQuickReplyController extends Controller
{
    protected function companyId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->resolveCompanyId();
    }

    public function index(Request $request): JsonResponse
    {
        $activeOnly = $request->boolean('active_only');

        $replies = WhatsAppQuickReply::query()
            ->with('creator:id,name')
            ->where('company_id', $this->companyId($request))
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (WhatsAppQuickReply $r) => $r->toApiArray());

        return response()->json(['quick_replies' => $replies]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4096'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $reply = WhatsAppQuickReply::query()->create([
            'company_id' => $this->companyId($request),
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Réponse rapide créée.',
            'quick_reply' => $reply->toApiArray(),
        ], 201);
    }

    public function update(Request $request, WhatsAppQuickReply $quickReply): JsonResponse
    {
        if ($quickReply->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'body' => ['sometimes', 'string', 'max:4096'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $quickReply->fill($data)->save();

        return response()->json([
            'message' => 'Réponse rapide mise à jour.',
            'quick_reply' => $quickReply->fresh('creator')->toApiArray(),
        ]);
    }

    public function destroy(Request $request, WhatsAppQuickReply $quickReply): JsonResponse
    {
        if ($quickReply->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $quickReply->delete();

        return response()->json(['message' => 'Réponse rapide supprimée.']);
    }
}
