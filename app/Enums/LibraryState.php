<?php

namespace App\Enums;

enum LibraryState: string
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Downloading = 'downloading';
    case Available = 'available';
    case Declined = 'declined';
    case Failed = 'failed';
}
