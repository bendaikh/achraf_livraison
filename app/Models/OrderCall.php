<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Centre de confirmation — one logged call (or WhatsApp / VoIP contact) with its result. */
class OrderCall extends Model
{
    public const RESULTS = [
        'answered' => 'Répondu',
        'no_answer' => 'Pas de réponse',
        'callback' => 'Rappel demandé',
        'wrong_number' => 'Numéro incorrect',
    ];

    public const CHANNELS = [
        'phone' => 'Téléphone',
        'whatsapp' => 'WhatsApp',
        'voip' => 'VoIP',
    ];

    protected $fillable = ['order_id', 'user_id', 'result', 'channel', 'duration_seconds', 'note', 'called_at'];

    protected function casts(): array
    {
        return ['called_at' => 'datetime', 'duration_seconds' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'result' => $this->result,
            'result_label' => self::RESULTS[$this->result] ?? $this->result,
            'channel' => $this->channel,
            'channel_label' => self::CHANNELS[$this->channel] ?? $this->channel,
            'duration_seconds' => $this->duration_seconds,
            'note' => $this->note,
            'called_at' => $this->called_at?->toIso8601String(),
            'user_name' => $this->user?->name,
        ];
    }
}
