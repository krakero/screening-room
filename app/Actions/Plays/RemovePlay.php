<?php

namespace App\Actions\Plays;

use App\Models\Play;

class RemovePlay
{
    public function handle(Play $play): void
    {
        $play->delete();
    }
}
