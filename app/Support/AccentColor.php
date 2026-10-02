<?php

namespace App\Support;

/**
 * Resolves a user's stored accent preference (a preset key or a custom hex
 * string) into the CSS token values used by resources/css/app.css.
 */
final class AccentColor
{
    public const string DEFAULT = 'amber';

    /**
     * @var array<string, array{accent: string, foreground: string, label: string, dark?: array{accent: string, foreground: string}}>
     */
    public const array PRESETS = [
        'amber' => ['accent' => '#e5a00d', 'foreground' => '#1c1207', 'label' => 'Amber'],
        'orange' => ['accent' => '#ea580c', 'foreground' => '#0a0a0a', 'label' => 'Orange'],
        // These five read as text/icons (`text-accent`/`text-accent-content`) directly against the
        // canvas in dozens of places. Their base (light-mode) shade falls below WCAG AA (4.5:1) against
        // the near-black dark canvas, so dark mode swaps in a lighter tint (Tailwind's 400 shade) with a
        // recomputed foreground — same fix `darkVariantForCustomHex()` derives automatically for custom hex.
        'crimson' => ['accent' => '#dc2626', 'foreground' => '#ffffff', 'label' => 'Crimson', 'dark' => ['accent' => '#f87171', 'foreground' => '#0a0a0a']],
        'rose' => ['accent' => '#e11d48', 'foreground' => '#ffffff', 'label' => 'Rose', 'dark' => ['accent' => '#fb7185', 'foreground' => '#0a0a0a']],
        'violet' => ['accent' => '#7c3aed', 'foreground' => '#ffffff', 'label' => 'Violet', 'dark' => ['accent' => '#a78bfa', 'foreground' => '#0a0a0a']],
        'indigo' => ['accent' => '#4f46e5', 'foreground' => '#ffffff', 'label' => 'Indigo', 'dark' => ['accent' => '#818cf8', 'foreground' => '#0a0a0a']],
        'blue' => ['accent' => '#2563eb', 'foreground' => '#ffffff', 'label' => 'Blue', 'dark' => ['accent' => '#60a5fa', 'foreground' => '#0a0a0a']],
        'sky' => ['accent' => '#0284c7', 'foreground' => '#0a0a0a', 'label' => 'Sky'],
        'teal' => ['accent' => '#0d9488', 'foreground' => '#0a0a0a', 'label' => 'Teal'],
        'emerald' => ['accent' => '#059669', 'foreground' => '#0a0a0a', 'label' => 'Emerald'],
        'lime' => ['accent' => '#65a30d', 'foreground' => '#0a0a0a', 'label' => 'Lime'],
    ];

    /** Minimum WCAG contrast ratio a preset's foreground must clear against its own accent color (text/icon legibility). */
    public const float MIN_TEXT_CONTRAST = 4.5;

    /** The app's canvas colors (resources/css/app.css), used to warn when a custom accent nearly disappears against the page background. */
    public const string CANVAS_LIGHT = '#ffffff';

    public const string CANVAS_DARK = '#0b0b0f';

    /** Minimum contrast (WCAG 1.4.11, non-text UI components) an accent needs against the canvas in both themes. */
    public const float MIN_CANVAS_CONTRAST = 3.0;

    public static function isPreset(?string $value): bool
    {
        return $value !== null && array_key_exists($value, self::PRESETS);
    }

    public static function isValidHex(?string $value): bool
    {
        return $value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * @return array{accent: string, foreground: string, preset: string|null}
     */
    public static function resolve(?string $value): array
    {
        if (self::isPreset($value)) {
            return [
                'accent' => self::PRESETS[$value]['accent'],
                'foreground' => self::PRESETS[$value]['foreground'],
                'preset' => $value,
            ];
        }

        if (self::isValidHex($value)) {
            return [
                'accent' => $value,
                'foreground' => self::foregroundFor($value),
                'preset' => null,
            ];
        }

        return [
            'accent' => self::PRESETS[self::DEFAULT]['accent'],
            'foreground' => self::PRESETS[self::DEFAULT]['foreground'],
            'preset' => self::DEFAULT,
        ];
    }

    /**
     * Same as resolve(), but for dark mode: returns a preset's `dark` override when it has one,
     * lightens a custom hex until it clears MIN_TEXT_CONTRAST against the dark canvas, or falls
     * back to the same value as resolve() when no dark-specific adjustment is needed.
     *
     * @return array{accent: string, foreground: string, preset: string|null}
     */
    public static function resolveForDark(?string $value): array
    {
        if (self::isPreset($value) && isset(self::PRESETS[$value]['dark'])) {
            return [
                'accent' => self::PRESETS[$value]['dark']['accent'],
                'foreground' => self::PRESETS[$value]['dark']['foreground'],
                'preset' => $value,
            ];
        }

        if (self::isPreset($value)) {
            return self::resolve($value);
        }

        if (self::isValidHex($value)) {
            $lightened = self::lightenForDarkCanvas($value);

            return [
                'accent' => $lightened,
                'foreground' => self::foregroundFor($lightened),
                'preset' => null,
            ];
        }

        return self::resolve(null);
    }

    /**
     * Lightens a hex color (preserving hue/saturation) until it clears MIN_TEXT_CONTRAST against
     * the dark canvas, or returns it unchanged if it already does.
     */
    public static function lightenForDarkCanvas(string $hex): string
    {
        if (self::contrastRatio($hex, self::CANVAS_DARK) >= self::MIN_TEXT_CONTRAST) {
            return $hex;
        }

        $hsl = self::rgbToHsl(self::hexToRgb($hex));
        $candidate = $hex;

        for ($i = 0; $i < 30 && self::contrastRatio($candidate, self::CANVAS_DARK) < self::MIN_TEXT_CONTRAST; $i++) {
            $hsl[2] = min(0.97, $hsl[2] + 0.03);
            $candidate = self::rgbToHex(self::hslToRgb($hsl));
        }

        return $candidate;
    }

    /**
     * Picks black or white, whichever contrasts better against the given accent color.
     */
    public static function foregroundFor(string $hex): string
    {
        return self::contrastRatio($hex, '#ffffff') >= self::contrastRatio($hex, '#000000')
            ? '#ffffff'
            : '#0a0a0a';
    }

    /**
     * Whether this accent color stays visible against the page canvas, in both light and dark mode
     * (the same accent value is used in both themes, so it must clear the bar against both canvases).
     */
    public static function meetsContrast(string $hex): bool
    {
        return self::contrastRatio($hex, self::CANVAS_LIGHT) >= self::MIN_CANVAS_CONTRAST
            && self::contrastRatio($hex, self::CANVAS_DARK) >= self::MIN_CANVAS_CONTRAST;
    }

    public static function contrastRatio(string $hex, string $against): float
    {
        $lighter = max(self::relativeLuminance($hex), self::relativeLuminance($against));
        $darker = min(self::relativeLuminance($hex), self::relativeLuminance($against));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');

        /** @var array{0: string, 1: string, 2: string} $channels */
        $channels = str_split($hex, 2);

        [$r, $g, $b] = array_map(
            fn (string $channel): float => self::linearize(hexdec($channel) / 255),
            $channels,
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function linearize(float $channel): float
    {
        return $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        /** @var array{0: string, 1: string, 2: string} $channels */
        $channels = str_split($hex, 2);

        return array_map(fn (string $channel): int => hexdec($channel), $channels);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private static function rgbToHex(array $rgb): string
    {
        return '#'.implode('', array_map(
            fn (float $channel): string => str_pad(dechex((int) round(min(255, max(0, $channel)))), 2, '0', STR_PAD_LEFT),
            $rgb,
        ));
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: float, 1: float, 2: float} Hue (0-360), saturation (0-1), lightness (0-1).
     */
    private static function rgbToHsl(array $rgb): array
    {
        [$r, $g, $b] = array_map(fn (int $channel): float => $channel / 255, $rgb);

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $delta = $max - $min;
        $s = $l > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

        $h = match ($max) {
            $r => fmod(($g - $b) / $delta + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $delta + 2,
            default => ($r - $g) / $delta + 4,
        };

        return [$h * 60, $s, $l];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $hsl  Hue (0-360), saturation (0-1), lightness (0-1).
     * @return array{0: float, 1: float, 2: float}
     */
    private static function hslToRgb(array $hsl): array
    {
        [$h, $s, $l] = $hsl;
        $h = $h / 360;

        if ($s === 0.0) {
            return [$l * 255, $l * 255, $l * 255];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $hueToRgb = function (float $p, float $q, float $t): float {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return [
            $hueToRgb($p, $q, $h + 1 / 3) * 255,
            $hueToRgb($p, $q, $h) * 255,
            $hueToRgb($p, $q, $h - 1 / 3) * 255,
        ];
    }
}
