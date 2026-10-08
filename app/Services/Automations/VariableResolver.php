<?php

namespace App\Services\Automations;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** Resolves {{path}} placeholders and extracts subject field values for conditions. */
class VariableResolver
{
    /**
     * Replace {{order.city}}, {{steps.action_1.trackingNumber}}, {{context.foo}} in strings.
     *
     * @param  array<string, mixed>  $context
     */
    public function interpolate(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->interpolate($v, $context), $value);
        }
        if (! is_string($value) || ! str_contains($value, '{{')) {
            return $value;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($context) {
            $resolved = Arr::get($context, $m[1]);
            if ($resolved === null) {
                // Also allow steps.<key>.<field> via nested "steps"
                $resolved = Arr::get($context, $m[1]);
            }
            if (is_bool($resolved)) {
                return $resolved ? '1' : '0';
            }
            if (is_array($resolved) || is_object($resolved)) {
                return json_encode($resolved, JSON_UNESCAPED_UNICODE);
            }

            return $resolved === null ? '' : (string) $resolved;
        }, $value);
    }

    /**
     * Build the base context bag for a subject (order, etc.).
     *
     * @return array<string, mixed>
     */
    public function subjectContext(?Model $subject, array $triggerPayload = []): array
    {
        $ctx = [
            'trigger' => $triggerPayload,
            'steps' => [],
            'vars' => [],
        ];

        if ($subject instanceof Order) {
            $address = (array) ($subject->shipping_address ?? []);
            $ctx['order'] = [
                'id' => $subject->id,
                'name' => $subject->name,
                'order_number' => $subject->order_number,
                'status' => $subject->status,
                'confirmation_status' => $subject->confirmation_status,
                'confirmation_category' => $subject->confirmationStatusDefinition()?->category,
                'delivery_status' => $subject->delivery_status,
                'financial_status' => $subject->financial_status,
                'city' => $address['city'] ?? null,
                'address' => $address['address1'] ?? ($address['address'] ?? null),
                'phone' => $subject->phone,
                'customer_name' => $subject->customer_name,
                'email' => $subject->email,
                'total_price' => $subject->total_price,
                'amount' => $subject->total_price,
                'amount_due' => $subject->amountDue(),
                'amount_paid' => (float) ($subject->amount_paid ?? 0),
                'source' => $subject->source,
                'carrier' => $subject->carrier,
                'driver_id' => $subject->driver_id,
                'assigned_user_id' => $subject->assigned_user_id,
                'note' => $subject->note,
                'internal_note' => $subject->internal_note,
                'payment_method' => $subject->financial_status === 'paid' ? 'paye' : 'cod',
                'tracking_present' => $this->orderHasTracking($subject),
            ];
            $ctx['subject'] = $ctx['order'];
            $ctx['subject_type'] = Order::class;
            $ctx['subject_id'] = $subject->id;
        } elseif ($subject) {
            $ctx['subject'] = $subject->toArray();
            $ctx['subject_type'] = $subject::class;
            $ctx['subject_id'] = $subject->getKey();
        }

        return $ctx;
    }

    /** Resolve a condition field value from context / subject. */
    public function fieldValue(string $field, array $context): mixed
    {
        // Prefer explicit order./subject. paths, then flat aliases used by the registry.
        foreach (["order.{$field}", "subject.{$field}", $field, "vars.{$field}"] as $path) {
            if (Arr::has($context, $path)) {
                return Arr::get($context, $path);
            }
        }

        return null;
    }

    protected function orderHasTracking(Order $order): bool
    {
        foreach (['speedafShipments', 'siftShipments', 'ozonShipments'] as $rel) {
            if (method_exists($order, $rel) && $order->{$rel}()->exists()) {
                return true;
            }
        }

        return filled($order->carrier);
    }
}
