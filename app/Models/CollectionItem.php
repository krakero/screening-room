<?php

namespace App\Models;

use App\Enums\CollectionFormat;
use App\Observers\CollectionItemObserver;
use Database\Factories\CollectionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title_id', 'season_id', 'format', 'edition', 'retailer', 'barcode', 'acquired_at', 'price', 'currency', 'location', 'loaned_to', 'loaned_at', 'notes'])]
#[ObservedBy(CollectionItemObserver::class)]
class CollectionItem extends Model
{
    /** @use HasFactory<CollectionItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    protected function casts(): array
    {
        return [
            'format' => CollectionFormat::class,
            'acquired_at' => 'date',
            'loaned_at' => 'date',
        ];
    }
}
