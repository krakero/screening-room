<?php

namespace App\Support;

/**
 * The single ordered list of primary nav destinations shared by the top-header
 * and sidebar app layouts, so they can't drift apart.
 */
class PrimaryNavigation
{
    /**
     * @return array<int, array{label: string, route: string, active: string, icon: string}>
     */
    public static function items(): array
    {
        $items = [
            ['label' => __('Up Next'), 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'home'],
            ['label' => __('Discover'), 'route' => 'discover', 'active' => 'discover', 'icon' => 'sparkles'],
            ['label' => __('Calendar'), 'route' => 'calendar', 'active' => 'calendar', 'icon' => 'calendar-days'],
            ['label' => __('History'), 'route' => 'history', 'active' => 'history', 'icon' => 'clock'],
            ['label' => __('Lists'), 'route' => 'lists.index', 'active' => 'lists.*', 'icon' => 'queue-list'],
            ['label' => __('Stats'), 'route' => 'stats', 'active' => 'stats', 'icon' => 'chart-bar'],
        ];

        if (auth()->user()?->collection_enabled) {
            $items[] = ['label' => __('Collection'), 'route' => 'collection.index', 'active' => 'collection.*', 'icon' => 'square-3-stack-3d'];
        }

        if (app(IntegrationSettings::class)->configured('qbittorrent.url')) {
            $items[] = ['label' => __('Downloads'), 'route' => 'downloads', 'active' => 'downloads', 'icon' => 'arrow-down-tray'];
        }

        return $items;
    }
}
