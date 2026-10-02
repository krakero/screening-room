<?php

namespace App\Support;

use App\Enums\UpdateChannel;
use App\Services\Updates\VersionInfo;

class AppVersion
{
    public static function current(): VersionInfo
    {
        return new VersionInfo(
            version: config('app.version'),
            channel: UpdateChannel::from(config('app.channel')),
            commit: config('app.commit'),
        );
    }
}
