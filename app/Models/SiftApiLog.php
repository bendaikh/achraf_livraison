<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A failed Sift.ma API call (redacted: never contains the API key). */
class SiftApiLog extends Model
{
    public const ACTIONS = [
        'create_parcel' => 'Création du colis',
        'refresh' => 'Actualiser depuis Sift',
        'update_parcel' => 'Modification du colis',
        'cancel_parcel' => 'Annulation chez le transporteur',
        'delete_parcel' => 'Suppression / masquage',
        'waybill' => 'Étiquette',
        'sync' => 'Synchronisation',
        'list' => 'Liste des colis',
        'lookup' => 'Recherche par n° de suivi',
        'products' => 'Produits',
        'webhook_register' => 'Enregistrement du webhook',
        'test' => 'Test de connexion',
    ];

    public const RETRYABLE = ['create_parcel', 'refresh', 'update_parcel', 'cancel_parcel', 'sync'];

    protected $fillable = [
        'company_id', 'action', 'endpoint', 'method', 'order_id', 'sift_shipment_id', 'http_status', 'message', 'payload',
        'response', 'context', 'user_id', 'retried_at', 'resolved',
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
            'endpoint' => trim(($this->method ? $this->method.' ' : '').$this->endpoint),
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
