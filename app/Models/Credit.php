<?php

namespace App\Models;

use App\Enums\CreditType;
use Database\Factories\CreditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $person_id
 * @property string $creditable_type
 * @property int $creditable_id
 * @property CreditType $type
 * @property string|null $character
 * @property string|null $job
 * @property string|null $department
 * @property int $order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['person_id', 'creditable_type', 'creditable_id', 'type', 'character', 'job', 'department', 'order'])]
class Credit extends Model
{
    /** @use HasFactory<CreditFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function creditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditType::class,
        ];
    }
}
