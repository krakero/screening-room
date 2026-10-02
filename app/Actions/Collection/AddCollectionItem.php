<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;
use App\Models\Title;

class AddCollectionItem
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Title $title, array $attributes): CollectionItem
    {
        return $title->collectionItems()->create($attributes);
    }
}
