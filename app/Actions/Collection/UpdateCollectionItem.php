<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;

class UpdateCollectionItem
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(CollectionItem $item, array $attributes): CollectionItem
    {
        $item->update($attributes);

        return $item;
    }
}
