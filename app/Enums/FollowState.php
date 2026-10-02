<?php

namespace App\Enums;

enum FollowState: string
{
    case Watching = 'watching';
    case Paused = 'paused';
    case Abandoned = 'abandoned';
    case Completed = 'completed';
}
