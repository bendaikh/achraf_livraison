<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\ConversationOrderLinker;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppConversationController extends Controller
{
    protected function companyId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->resolveCompanyId();
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = WhatsAppConversation::query()
            ->where('company_id', $this->companyId($request))
            ->sum('unread_count');

        return response()->json(['unread_count' => (int) $count]);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $filter = $request->query('filter', 'all');
        $search = trim((string) $request->query('search', ''));
        $accountId = $request->query('account_id');

        $query = WhatsAppConversation::query()
            ->with(['account:id,name,display_phone_number,phone_number', 'assignee:id,name'])
            ->where('company_id', $companyId);

        if ($accountId) {
            $query->where('whatsapp_account_id', $accountId);
        }

        match ($filter) {
            'unread' => $query->where('unread_count', '>', 0),
            'assigned' => $query->whereNotNull('assigned_to'),
            'pending' => $query->where('status', WhatsAppConversation::STATUS_PENDING),
            'resolved' => $query->where('status', WhatsAppConversation::STATUS_RESOLVED),
            default => null,
        };

        if ($search !== '') {
            $digits = PhoneNormalizer::digits($search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('contact_wa_id', 'like', "%{$search}%")
                    ->orWhere('last_message_preview', 'like', "%{$search}%");

                if ($digits !== '') {
                    $q->orWhere('contact_phone', 'like', "%{$digits}%")
                        ->orWhere('contact_wa_id', 'like', "%{$digits}%");
                }

                $q->orWhereHas('orders', function ($oq) use ($search) {
                    $oq->where('name', 'like', "%{$search}%")
                        ->orWhere('order_number', 'like', "%{$search}%");
                });
            });
        }

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (WhatsAppConversation $c) => $this->serializeConversation($c));

        return response()->json(['conversations' => $conversations]);
    }

    public function show(Request $request, WhatsAppConversation $conversation, ConversationOrderLinker $linker): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $linker->link($conversation);

        $conversation->load([
            'account:id,name,display_phone_number,phone_number',
            'assignee:id,name',
            'orders' => fn ($q) => $q->latest('shopify_created_at')->limit(10),
        ]);

        $messages = WhatsAppMessage::query()
            ->with('sentBy:id,name')
            ->where('whatsapp_conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (WhatsAppMessage $m) => $m->toApiArray());

        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return response()->json([
            'conversation' => $this->serializeConversation($conversation, true),
            'messages' => $messages,
        ]);
    }

    public function markRead(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $conversation->forceFill(['unread_count' => 0])->save();

        return response()->json(['ok' => true]);
    }

    public function assign(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $conversation->forceFill([
            'assigned_to' => $data['user_id'] ?? $request->user()->id,
            'status' => WhatsAppConversation::STATUS_PENDING,
        ])->save();

        return response()->json([
            'conversation' => $this->serializeConversation($conversation->fresh(['account', 'assignee'])),
        ]);
    }

    public function resolve(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $conversation->forceFill([
            'status' => WhatsAppConversation::STATUS_RESOLVED,
            'unread_count' => 0,
        ])->save();

        return response()->json([
            'conversation' => $this->serializeConversation($conversation->fresh(['account', 'assignee'])),
        ]);
    }

    public function reopen(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        if ($conversation->company_id !== $this->companyId($request)) {
            return response()->json(['message' => 'Introuvable.'], 404);
        }

        $conversation->forceFill([
            'status' => WhatsAppConversation::STATUS_OPEN,
        ])->save();

        return response()->json([
            'conversation' => $this->serializeConversation($conversation->fresh(['account', 'assignee'])),
        ]);
    }

    public function openForOrder(Request $request, Order $order, ConversationOrderLinker $linker): JsonResponse
    {
        $companyId = $this->companyId($request);
        $conversation = $linker->findConversationForOrder($order, $companyId);

        if (! $conversation) {
            return response()->json([
                'message' => 'Aucune conversation WhatsApp pour ce numéro.',
                'conversation_id' => null,
                'phone' => $order->phone,
            ], 404);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'path' => '/whatsapp?c='.$conversation->id,
        ]);
    }

    protected function serializeConversation(WhatsAppConversation $c, bool $withOrders = false): array
    {
        $data = [
            'id' => $c->id,
            'contact_name' => $c->displayName(),
            'contact_phone' => $c->contact_phone ?: $c->contact_wa_id,
            'initials' => $c->initials(),
            'status' => $c->status,
            'unread_count' => (int) $c->unread_count,
            'last_message_preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            'assigned_to' => $c->assignee ? [
                'id' => $c->assignee->id,
                'name' => $c->assignee->name,
            ] : null,
            'account' => $c->account ? [
                'id' => $c->account->id,
                'name' => $c->account->name,
                'display_phone_number' => $c->account->display_phone_number ?: $c->account->phone_number,
            ] : null,
        ];

        if ($withOrders || $c->relationLoaded('orders')) {
            $data['orders'] = $c->orders->map(fn (Order $order) => [
                'id' => $order->id,
                'name' => $order->name,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'phone' => $order->phone,
                'status' => $order->status,
                'confirmation_status' => $order->confirmation_status,
                'confirmation_label' => Order::confirmationLabel((string) $order->confirmation_status),
                'total_price' => $order->total_price,
                'currency' => $order->currency,
                'city' => $order->shippingCity(),
                'line_items' => collect($order->line_items ?? [])->map(fn ($item) => [
                    'title' => $item['title'] ?? $item['name'] ?? 'Produit',
                    'quantity' => $item['quantity'] ?? 1,
                ])->values(),
            ])->values();
        }

        return $data;
    }
}
