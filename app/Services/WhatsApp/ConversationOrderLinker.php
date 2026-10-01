<?php

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Collection;

class ConversationOrderLinker
{
    public function link(WhatsAppConversation $conversation): Collection
    {
        $variants = PhoneNormalizer::matchVariants($conversation->contact_phone ?: $conversation->contact_wa_id);
        if ($variants === []) {
            return collect();
        }

        $orders = Order::query()
            ->where(function ($query) use ($variants) {
                foreach ($variants as $variant) {
                    $digits = PhoneNormalizer::digits($variant);
                    $query->orWhere(function ($inner) use ($variant, $digits) {
                        $inner->where('phone', $variant);
                        if ($digits !== '') {
                            $inner->orWhere('phone', 'like', '%'.$digits)
                                ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?", ['%'.$digits]);
                        }
                    });
                }
            })
            ->latest('shopify_created_at')
            ->limit(20)
            ->get();

        if ($orders->isNotEmpty()) {
            $conversation->orders()->syncWithoutDetaching($orders->pluck('id')->all());

            if (! filled($conversation->contact_name)) {
                $name = $orders->firstWhere('customer_name', '!=', null)?->customer_name;
                if ($name) {
                    $conversation->forceFill(['contact_name' => $name])->save();
                }
            }
        }

        return $orders;
    }

    public function findConversationForOrder(Order $order, int $companyId): ?WhatsAppConversation
    {
        $digits = PhoneNormalizer::digits($order->phone);
        if ($digits === '') {
            return null;
        }

        return WhatsAppConversation::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($digits) {
                $query->where('contact_wa_id', $digits)
                    ->orWhere('contact_phone', $digits)
                    ->orWhere('contact_wa_id', 'like', '%'.$digits)
                    ->orWhere('contact_phone', 'like', '%'.$digits);
            })
            ->latest('last_message_at')
            ->first();
    }
}
