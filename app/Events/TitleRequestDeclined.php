<?php

namespace App\Events;

use App\Models\Title;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when Seerr declines a title's request.
 */
class TitleRequestDeclined
{
    use Dispatchable, SerializesModels;

    public function __construct(public Title $title) {}
}
