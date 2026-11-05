<?php

/**
 * Native UI — Theme Tokens
 *
 * Screening Room mobile app theme: brand amber (#e5a00d) primary,
 * pure black dark mode background, iOS system chrome colors.
 */

return [

    'theme' => [

        'light' => [
            // Brand amber primary
            'primary' => '#e5a00d',
            'on-primary' => '#1c1207',

            // Secondary: iOS neutral fill (grey buttons/chips)
            'secondary' => '#e5e5ea',
            'on-secondary' => '#18181b',

            // Surfaces
            'surface' => '#f2f2f7',      // iOS grouped background
            'on-surface' => '#18181b',
            'background' => '#ffffff',
            'on-background' => '#18181b',

            // Surface variant
            'surface-variant' => '#f2f2f7',
            'on-surface-variant' => '#52525b',

            // Lines and borders
            'outline' => '#e4e4e7',
            'outline-variant' => '#e4e4e7',

            // Destructive actions
            'destructive' => '#B91C1C',
            'on-destructive' => '#FFFFFF',

            // Success
            'success' => '#15803D',
            'on-success' => '#FFFFFF',

            // Accent (brand amber, same as primary)
            'accent' => '#e5a00d',
            'on-accent' => '#1c1207',
        ],

        'dark' => [
            // Brand amber primary (brighter in dark mode)
            'primary' => '#e5a00d',
            'on-primary' => '#1c1207',

            // Secondary
            'secondary' => '#2c2c2e',
            'on-secondary' => '#f4f4f5',

            // Surfaces - pure black background, iOS system surfaces
            'surface' => '#1c1c1e',      // iOS secondary grouped background
            'on-surface' => '#f4f4f5',
            'background' => '#000000',   // Pure black
            'on-background' => '#f4f4f5',

            // Raised surface (for elevated cards)
            'raised' => '#2c2c2e',       // iOS tertiary grouped background

            // Surface variant
            'surface-variant' => '#1c1c1e',
            'on-surface-variant' => '#a1a1aa',

            // Lines and borders
            'outline' => '#38383a',
            'outline-variant' => '#38383a',

            // Destructive actions
            'destructive' => '#F87171',
            'on-destructive' => '#000000',

            // Success
            'success' => '#4ADE80',
            'on-success' => '#052E16',

            // Accent (brand amber)
            'accent' => '#e5a00d',
            'on-accent' => '#1c1207',
        ],

        // Corner radii (points / dp)
        'radius-sm' => 4,
        'radius-md' => 8,
        'radius-lg' => 16,
        'radius-full' => 9999,

        // Font size scale (points / sp)
        'font-sm' => 14,
        'font-md' => 16,
        'font-lg' => 20,
        'font-xl' => 24,

    ],

    'fonts' => [
        'default' => 'System',
    ],

];
