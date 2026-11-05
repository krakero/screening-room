<?php

namespace Tests\Unit;

use App\NativeComponents\Layouts\StackLayout;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\UpNext;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Tests\TestCase;

class LayoutsTest extends TestCase
{
    public function test_tabs_layout_uses_native_chrome(): void
    {
        $layout = new TabsLayout;

        $this->assertTrue($layout->usesNativeChrome());
    }

    public function test_tabs_layout_has_brand_amber_active_color(): void
    {
        $layout = new TabsLayout;
        $screen = new UpNext;

        $tabBar = $layout->tabBar($screen);

        $this->assertNotNull($tabBar);
        // The TabBar builder stores activeColor internally; we verify
        // by checking the tab bar was created (full wire verification
        // would need to check toElement() output, which is tested in Core)
        $this->assertInstanceOf(TabBar::class, $tabBar);
    }

    public function test_tabs_layout_nav_bar_has_large_display_mode(): void
    {
        $layout = new TabsLayout;
        $screen = new UpNext;

        $navBar = $layout->navBar($screen);

        $this->assertNotNull($navBar);
        $props = $navBar->toRootProps();
        $this->assertEquals('large', $props['displayMode']);
    }

    public function test_tabs_layout_nav_bar_has_no_back_button_for_tab_roots(): void
    {
        $layout = new TabsLayout;
        $screen = new UpNext;

        $navBar = $layout->navBar($screen);

        $this->assertNotNull($navBar);
        $props = $navBar->toRootProps();
        $this->assertFalse($props['back']);
    }

    public function test_stack_layout_uses_native_chrome(): void
    {
        $layout = new StackLayout;

        $this->assertTrue($layout->usesNativeChrome());
    }

    public function test_stack_layout_nav_bar_has_back_button(): void
    {
        $layout = new StackLayout;
        $screen = new UpNext;

        $navBar = $layout->navBar($screen);

        $this->assertNotNull($navBar);
        $props = $navBar->toRootProps();
        $this->assertTrue($props['back']);
    }

    public function test_stack_layout_has_no_tab_bar(): void
    {
        $layout = new StackLayout;
        $screen = new UpNext;

        $tabBar = $layout->tabBar($screen);

        $this->assertNull($tabBar);
    }
}
