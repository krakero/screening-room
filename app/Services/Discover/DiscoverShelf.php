<?php

namespace App\Services\Discover;

final class DiscoverShelf
{
    /**
     * @param  array<int, DiscoverItem>  $items
     */
    public function __construct(
        public readonly string $heading,
        public readonly array $items,
    ) {}
}
