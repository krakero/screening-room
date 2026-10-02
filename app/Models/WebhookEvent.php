<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source
 * @property string|null $event
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['source', 'event', 'payload', 'processed_at', 'error'])]
class WebhookEvent extends Model
{
    public function markProcessed(): void
    {
        $this->update(['processed_at' => now(), 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['error' => $error]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
