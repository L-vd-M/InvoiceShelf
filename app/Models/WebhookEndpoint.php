<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'event_types' => 'array',
        'enabled' => 'boolean',
        'secret_rotated_at' => 'datetime',
    ];

    public static function generateSecret(): string
    {
        return 'whsec_'.base64_encode(random_bytes(32));
    }

    public function wants(string $type): bool
    {
        return $this->enabled && (empty($this->event_types) || in_array($type, $this->event_types, true));
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
