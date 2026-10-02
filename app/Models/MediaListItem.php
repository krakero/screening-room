<?php

namespace App\Models;

use App\Observers\MediaListItemObserver;
use Database\Factories\MediaListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $media_list_id
 * @property int $title_id
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['media_list_id', 'title_id', 'position'])]
#[ObservedBy(MediaListItemObserver::class)]
class MediaListItem extends Model
{
    /** @use HasFactory<MediaListItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MediaList, $this>
     */
    public function mediaList(): BelongsTo
    {
        return $this->belongsTo(MediaList::class);
    }

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }
}
