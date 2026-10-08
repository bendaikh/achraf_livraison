<?php

namespace App\Services\Team;

use App\Models\AgentCommission;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * T6 — generates agent commissions from real order events. The agent is the user who
 * confirmed the order (fallback: the assigned agent). Rates are snapshotted on generation
 * and never recalculated; returned / cancelled orders cancel pending lines (except for the
 * « à la confirmation » trigger, which is earned at confirmation).
 */
class CommissionService
{
    /** @var array<int, ?User> */
    protected array $agents = [];

    public function agentFor(Order $order): ?User
    {
        $id = $order->confirmed_by ?: $order->assigned_user_id;
        if (! $id) {
            return null;
        }
        if (! array_key_exists($id, $this->agents)) {
            $this->agents[$id] = User::query()->find($id);
        }

        return $this->agents[$id];
    }

    public function forgetAgents(): void
    {
        $this->agents = [];
    }

    public function triggerMet(Order $order, string $trigger): bool
    {
        return match ($trigger) {
            'confirmation' => $order->isConfirmed(),
            'shipped' => $order->isConfirmed() && ($order->driver_id || $order->carrier || $order->deliveryCategory() === 'succes'),
            'delivered' => $order->deliveryCategory() === 'succes',
            'closed' => $order->deliveryCategory() === 'succes' && ($order->closing_id || $order->cod_remitted_at),
            default => false, // manual
        };
    }

    public function isLost(Order $order): bool
    {
        $failed = $order->confirmationStatusDefinition()?->counts_as_failure
            ?? $order->confirmation_status === Order::CONFIRMATION_CANCELLED;

        return in_array($order->deliveryCategory(), ['retour', 'annulation'], true) || $failed;
    }

    /** Creates / cancels the commission line of the order. Returns the line when one exists. */
    public function syncOrder(Order $order): ?AgentCommission
    {
        $agent = $this->agentFor($order);
        if (! $agent) {
            return null;
        }
        $existing = AgentCommission::query()->where('order_id', $order->id)->where('user_id', $agent->id)->first();

        if ($existing) {
            if ($existing->state === AgentCommission::PENDING && $existing->trigger !== 'confirmation' && $this->isLost($order)) {
                $existing->forceFill(['state' => AgentCommission::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'Commande '.mb_strtolower($order->deliveryStatusLabel() ?: 'annulée')])->save();
            }

            return $existing;
        }

        $kind = config('commissions.modes.'.($agent->commission_mode ?: 'none').'.kind', 'none');
        if (! in_array($kind, ['fixed', 'percent'], true) || ! $agent->commission_value) {
            return null;
        }
        $trigger = $agent->commissionTrigger() ?? 'delivered';
        if (! $this->triggerMet($order, $trigger) || ($trigger !== 'confirmation' && $this->isLost($order))) {
            return null;
        }

        $base = (float) $order->total_price;
        $amount = $kind === 'percent' ? round($base * $agent->commission_value / 100, 2) : round($agent->commission_value, 2);

        return AgentCommission::create([
            'company_id' => $agent->company_id,
            'user_id' => $agent->id,
            'order_id' => $order->id,
            'mode' => $agent->commission_mode,
            'trigger' => $trigger,
            'rate' => $agent->commission_value,
            'base_amount' => $kind === 'percent' ? $base : null,
            'amount' => $amount,
            'state' => AgentCommission::PENDING,
            'generated_at' => now(),
        ]);
    }

    /** Fixed monthly amounts for the given month (idempotent). Returns the number created. */
    public function generateMonthly(?Carbon $month = null): int
    {
        $period = ($month ?? now()->subMonth())->format('Y-m');
        $created = 0;
        $agents = User::query()->where('commission_mode', 'monthly')->where('is_active', true)->whereNotNull('commission_value')->get();
        foreach ($agents as $agent) {
            $line = AgentCommission::query()->firstOrCreate(
                ['user_id' => $agent->id, 'order_id' => null, 'period' => $period],
                ['company_id' => $agent->company_id, 'mode' => 'monthly', 'trigger' => null, 'rate' => $agent->commission_value, 'amount' => $agent->commission_value, 'state' => AgentCommission::PENDING, 'generated_at' => now()],
            );
            $created += $line->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
