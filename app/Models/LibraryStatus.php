<?php

namespace App\Models;

use App\Enums\LibraryState;
use App\Enums\SeerrRequestStatus;
use Database\Factories\LibraryStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $title_id
 * @property LibraryState $state
 * @property int|null $seerr_request_id
 * @property SeerrRequestStatus|null $seerr_status
 * @property int|null $sonarr_id
 * @property int|null $radarr_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title_id', 'state', 'seerr_request_id', 'seerr_status', 'sonarr_id', 'radarr_id'])]
class LibraryStatus extends Model
{
    /** @use HasFactory<LibraryStatusFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => LibraryState::class,
            'seerr_status' => SeerrRequestStatus::class,
        ];
    }
}
