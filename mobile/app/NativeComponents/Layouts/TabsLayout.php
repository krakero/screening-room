<?php

namespace App\NativeComponents\Layouts;

use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

class TabsLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        return NavBar::make()
            ->title($screen->navTitle())
            ->displayMode('large');
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->activeColor('#e5a00d')
            ->add(Tab::link('Up Next', '/up-next', icon: 'play'))
            ->add(Tab::link('Calendar', '/calendar', icon: 'calendar'))
            ->add(Tab::link('Discover', '/search', icon: 'sparkles'))
            ->add(Tab::link('Settings', '/settings', icon: 'settings'));
    }
}
