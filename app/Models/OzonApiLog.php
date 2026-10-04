<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A failed Ozon API call (redacted: never contains the API key). */
class OzonApiLog extends Model
{
    public const ACTIONS = [
        'create_parcel' => 'Création du colis',
        'parcel_info' => 'Actualiser depuis Ozon',
        'tracking' => 'Suivi',
        'tracking_bulk' => 'Suivi groupé',
        'delivery_note' => 'Bon de livraison',
        'test' => 'Test de connexion',
        'cities' => 'Synchro des villes',
    ];

    /** Actions the « Réessayer » button can replay. */
    public const RETRYABLE = ['create_parcel', 'parcel_info', 'tracking', 'tracking_bulk', 'delivery_note', 'cities'];

    protected $fillable = [
        'company_id', 'action', 'endpoint', 'order_id', 'ozon_shipment_id', 'ozon_delivery_note_id', 'http_status',
        'message', 'payload', 'response', 'context', 'user_id', 'retried_at', 'resolved',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'response' => 'array', 'context' => 'array', 'retried_at' => 'datetime', 'resolved' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => self::ACTIONS[$this->action] ?? $this->action,
            'endpoint' => $this->endpoint,
            'order_id' => $this->order_id,
            'order_reference' => $this->order?->reference(),
            'http_status' => $this->http_status,
            'message' => $this->message,
            'payload' => $this->payload,
            'response' => $this->response,
            'user' => $this->user?->name ?? 'Système',
            'retryable' => in_array($this->action, self::RETRYABLE, true) && ! $this->resolved,
            'retried_at' => $this->retried_at?->toIso8601String(),
            'resolved' => (bool) $this->resolved,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
