<?php

namespace App\Services\Confirmation;

use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\OrderWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only path that changes orders.confirmation_status.
 * Quick actions and automations call it; required fields are enforced here.
 */
class ConfirmationStatusChanger
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    public function changeByCode(Order $order, string $code, ?User $user = null, array $input = []): Order
    {
        $companyId = (int) ($order->company_id ?: ConfirmationStatus::resolveCompanyId());
        $status = ConfirmationStatus::findByCode($code, $companyId);
        if (! $status || (int) $status->company_id !== $companyId || ! $status->is_active) {
            throw ValidationException::withMessages([
                'status_code' => 'Ce statut est inactif ou n’appartient pas à cette société.',
            ]);
        }

        return $this->change($order, $status, $user, $input);
    }

    public function change(Order $order, ConfirmationStatus $status, ?User $user = null, array $input = []): Order
    {
        $companyId = (int) ($order->company_id ?: $status->company_id);
        if ((int) $status->company_id !== $companyId || ! $status->is_active) {
            throw ValidationException::withMessages([
                'status_code' => 'Ce statut est inactif ou n’appartient pas à cette société.',
            ]);
        }
        if ($order->confirmation_status === $status->code && empty($input['force'])) {
            return $order;
        }

        $this->assertRequired($order, $status, $input);
        $user ??= auth()->user();
        $recall = $this->recallMoment($input);
        $comment = trim((string) ($input['comment'] ?? $input['note'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));
        $line = $this->matchingLine($order, $input);
        $restock = trim((string) ($input['expected_restock_date'] ?? ''));
        $fromCode = (string) $order->confirmation_status;
        $fromName = $order->confirmationStatusDefinition()?->name
            ?? ConfirmationStatus::labelFor($fromCode, $companyId);
        $attempt = $status->category === ConfirmationStatus::CATEGORY_NO_ANSWER
            ? $this->nextAttempt($order)
            : null;

        return DB::transaction(function () use ($order, $status, $user, $input, $recall, $comment, $reason, $line, $restock, $fromCode, $fromName, $attempt) {
            $order->confirmation_status = $status->code;
            $order->confirmation_acted_by = $user?->id;
            $order->confirmation_acted_at = now();

            if ($status->counts_as_confirmed || $status->type === ConfirmationStatus::TYPE_SUCCESS) {
                $order->confirmed_by ??= $user?->id;
                $order->confirmed_at ??= now();
                $order->cancellation_reason = null;
            }
            if ($reason !== '') {
                $order->cancellation_reason = $reason;
            } elseif (! $status->counts_as_failure && $status->category !== ConfirmationStatus::CATEGORY_FAILED) {
                if ($status->counts_as_confirmed) {
                    $order->cancellation_reason = null;
                }
            }
            $order->postponed_until = $recall;
            if ($comment !== '') {
                $order->confirmation_note = $comment;
            }
            if (! empty($input['channel']) && array_key_exists($input['channel'], OrderCall::CHANNELS)) {
                $order->confirmation_channel = $input['channel'];
            }

            $productTitle = is_array($line) ? (string) ($line['title'] ?? $line['name'] ?? '') : '';
            $meta = array_filter([
                'from_status' => $fromCode,
                'from_status_name' => $fromName,
                'to_status' => $status->code,
                'to_status_name' => $status->name,
                'to_category' => $status->category,
                'reason' => $reason !== '' ? $reason : null,
                'comment' => $comment !== '' ? $comment : null,
                'recall_at' => $recall?->toIso8601String(),
                'product' => $productTitle !== '' ? $productTitle : null,
                'product_line_key' => $input['product_line_key'] ?? ($line['id'] ?? $line['key'] ?? null),
                'variant_id' => $input['variant_id'] ?? ($line['variant_id'] ?? null),
                'expected_restock_date' => $restock !== '' ? $restock : null,
                'attempt' => $attempt,
                'channel' => $input['channel'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $order->confirmationChangeContext = [
                'from_status' => $fromCode,
                'to_status' => $status->code,
                'to_category' => $status->category,
                'reason' => $reason !== '' ? $reason : null,
                'recall_at' => $recall?->toIso8601String(),
            ];

            $order->appendHistory(
                $status->counts_as_confirmed ? 'confirmed' : $status->code,
                $fromName.' → '.$status->name,
                $user instanceof User ? $user : null,
                $meta,
                $comment !== '' ? $comment : ($reason !== '' ? $reason : null),
            );
            $order->save();

            $this->workflow->recordConfirmation($order, $status, $user instanceof User ? $user : null, $meta + [
                'from_status_name' => $fromName,
                'comment' => $comment !== '' ? $comment : null,
            ]);

            if ($status->category === ConfirmationStatus::CATEGORY_NO_ANSWER && $user instanceof User) {
                OrderCall::create([
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'result' => 'no_answer',
                    'channel' => $input['channel'] ?? 'phone',
                    'note' => trim('Tentative '.($attempt ?? 1).($comment !== '' ? ' — '.$comment : '')),
                    'called_at' => now(),
                ]);
            }

            return $order;
        });
    }

    public function quickStatus(string $action, int $companyId): ConfirmationStatus
    {
        $all = ConfirmationStatus::cachedAll($companyId)->where('is_active', true);
        $pick = fn (callable $pred) => $all->first($pred);

        $status = match ($action) {
            'confirm' => $pick(fn (ConfirmationStatus $s) => $s->code === Order::CONFIRMATION_CONFIRMED)
                ?? $pick(fn (ConfirmationStatus $s) => $s->counts_as_confirmed && $s->category === ConfirmationStatus::CATEGORY_CONFIRMED)
                ?? $pick(fn (ConfirmationStatus $s) => (bool) $s->counts_as_confirmed),
            'no_answer' => $pick(fn (ConfirmationStatus $s) => $s->code === Order::CONFIRMATION_NO_ANSWER)
                ?? $pick(fn (ConfirmationStatus $s) => $s->category === ConfirmationStatus::CATEGORY_NO_ANSWER),
            'postpone' => $pick(fn (ConfirmationStatus $s) => $s->code === Order::CONFIRMATION_POSTPONED)
                ?? $pick(fn (ConfirmationStatus $s) => $s->category === ConfirmationStatus::CATEGORY_RECALL),
            'cancel' => $pick(fn (ConfirmationStatus $s) => $s->code === Order::CONFIRMATION_CANCELLED)
                ?? $pick(fn (ConfirmationStatus $s) => (bool) $s->counts_as_failure),
            default => null,
        };

        if (! $status) {
            throw ValidationException::withMessages([
                'status_code' => 'Aucun statut configuré pour cette action.',
            ]);
        }

        return $status;
    }

    public function assertRequired(Order $order, ConfirmationStatus $status, array $input): void
    {
        $errors = [];
        $recall = trim((string) ($input['recall_at'] ?? ''));
        $hasTime = $this->hasTime($input);

        if ($status->requires_recall_date && $status->requires_time && ($recall === '' || ! $hasTime)) {
            $errors['recall_at'] = 'La date et l’heure du rappel sont obligatoires.';
        } else {
            if ($status->requires_recall_date && $recall === '') {
                $errors['recall_at'] = 'La date de rappel est obligatoire.';
            }
            if ($status->requires_time && ! $hasTime) {
                $errors['recall_time'] = 'L’heure est obligatoire.';
            }
        }

        if ($recall !== '' && ! isset($errors['recall_at'])) {
            try {
                $moment = $this->recallMoment($input);
            } catch (\Throwable) {
                $moment = null;
                $errors['recall_at'] = 'La date de rappel est invalide.';
            }
            if ($moment && $moment->lte(now())) {
                $errors['recall_at'] = 'Le rappel doit être dans le futur.';
            }
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($status->requires_reason && $reason === '') {
            $errors['reason'] = $status->category === ConfirmationStatus::CATEGORY_FAILED
                ? 'Le motif d’annulation est obligatoire.'
                : 'Le motif est obligatoire.';
        } elseif ($status->requires_reason && mb_strlen($reason) < 2) {
            $errors['reason'] = 'Le motif est trop court.';
        } elseif ($reason !== '' && is_array($status->reason_options) && $status->reason_options !== [] && ! in_array($reason, $status->reason_options, true)) {
            $errors['reason'] = 'Choisissez un motif dans la liste.';
        }

        $comment = trim((string) ($input['comment'] ?? $input['note'] ?? ''));
        if ($status->requires_comment && $comment === '') {
            $errors['comment'] = 'Le commentaire est obligatoire.';
        }

        if ($status->requires_product) {
            $key = $input['product_line_key'] ?? null;
            $variant = $input['variant_id'] ?? null;
            if (($key === null || $key === '') && ($variant === null || $variant === '')) {
                $errors['product_line_key'] = 'Le produit concerné est obligatoire.';
            } elseif ($this->matchingLine($order, $input) === null) {
                $errors['product_line_key'] = 'Ce produit ne fait pas partie de la commande.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function formatHistoryLine(OrderStatusHistory $row, ?string $userName, ?int $companyId = null): string
    {
        $tz = \App\Models\Company::query()->find($companyId ?: ConfirmationStatus::resolveCompanyId())?->timezoneOrDefault()
            ?? 'Africa/Casablanca';
        $data = $row->data ?? [];
        $when = ($row->created_at ?? now())->timezone($tz)->format('d/m/Y H:i');
        $from = $data['from_status_name'] ?? $row->from_status_name;
        $to = $data['to_status_name'] ?? $row->status_name;
        $who = $userName ?: 'Lav’Fast Flow';
        $change = $from ? "{$who} : {$from} → {$to}" : "{$who} : {$to}";
        $parts = [$when, $change];
        if (! empty($data['product'])) {
            $parts[] = 'Produit : '.$data['product'];
        }
        if (! empty($data['expected_restock_date'])) {
            try {
                $restock = Carbon::parse($data['expected_restock_date'])->timezone($tz)->format('d/m/Y');
            } catch (\Throwable) {
                $restock = (string) $data['expected_restock_date'];
            }
            $parts[] = 'Réapprovisionnement prévu : '.$restock;
        }
        if (! empty($data['reason'])) {
            $parts[] = 'Motif : '.$data['reason'];
        }
        if (! empty($data['comment'])) {
            $parts[] = 'Commentaire : '.$data['comment'];
        }
        if (! empty($data['recall_at'])) {
            try {
                $recall = Carbon::parse($data['recall_at'])->timezone($tz)->format('d/m/Y H:i');
            } catch (\Throwable) {
                $recall = (string) $data['recall_at'];
            }
            $parts[] = 'Rappel : '.$recall;
        }
        if (! empty($data['attempt'])) {
            $parts[] = 'Tentative '.$data['attempt'];
        }

        return implode(' · ', $parts);
    }

    private function nextAttempt(Order $order): int
    {
        return (int) OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->where('kind', 'confirmation')
            ->where(function ($q) {
                $q->where('status_category', ConfirmationStatus::CATEGORY_NO_ANSWER)
                    ->orWhere('status_code', Order::CONFIRMATION_NO_ANSWER);
            })
            ->count() + 1;
    }

    private function hasTime(array $input): bool
    {
        $time = trim((string) ($input['recall_time'] ?? ''));
        if (preg_match('/^\d{1,2}:\d{2}/', $time)) {
            return true;
        }
        $recall = (string) ($input['recall_at'] ?? '');

        return (bool) preg_match('/[T\s]\d{1,2}:\d{2}/', $recall);
    }

    private function recallMoment(array $input): ?Carbon
    {
        $recall = trim((string) ($input['recall_at'] ?? ''));
        if ($recall === '') {
            return null;
        }
        $parsed = Carbon::parse($recall);
        $time = trim((string) ($input['recall_time'] ?? ''));
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $match)) {
            $parsed = $parsed->timezone(config('app.timezone'))->setTime((int) $match[1], (int) $match[2], 0);
        }

        return $parsed;
    }

    /** @return array<string, mixed>|null */
    private function matchingLine(Order $order, array $input): ?array
    {
        $key = $input['product_line_key'] ?? null;
        $variant = $input['variant_id'] ?? null;
        if (($key === null || $key === '') && ($variant === null || $variant === '')) {
            return null;
        }
        foreach ((array) $order->line_items as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $candidates = array_filter([
                $line['id'] ?? null,
                $line['key'] ?? null,
                $line['variant_id'] ?? null,
                $index,
                $index + 1,
            ], fn ($value) => $value !== null && $value !== '');
            if ($key !== null && $key !== '' && in_array((string) $key, array_map('strval', $candidates), true)) {
                return $line;
            }
            if ($variant !== null && $variant !== '' && (string) ($line['variant_id'] ?? '') === (string) $variant) {
                return $line;
            }
        }

        return null;
    }
}
