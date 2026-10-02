<?php

namespace App\Events;

use App\Models\Title;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a title's library status becomes "available" (e.g. Sonarr/Radarr finished a download).
 */
class TitleBecameAvailable
{
    use Dispatchable, SerializesModels;

    public function __construct(public Title $title) {}
}
