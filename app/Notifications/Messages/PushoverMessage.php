<?php

namespace App\Notifications\Messages;

readonly class PushoverMessage
{
    public function __construct(
        public string $title,
        public string $message,
        public ?string $url = null,
    ) {}
}
