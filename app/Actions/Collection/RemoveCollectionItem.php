<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;

class RemoveCollectionItem
{
    public function handle(CollectionItem $item): void
    {
        $item->delete();
    }
}
