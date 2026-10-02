<?php

namespace App\Events;

use App\Models\Title;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when the queued Seerr request submission for a title fails.
 */
class TitleRequestFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Title $title) {}
}
