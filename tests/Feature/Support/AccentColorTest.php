<?php

use App\Support\AccentColor;

test('resolve falls back to the amber preset when nothing is stored', function () {
    $resolved = AccentColor::resolve(null);

    expect($resolved)->toBe([
        'accent' => '#e5a00d',
        'foreground' => '#1c1207',
        'preset' => 'amber',
    ]);
});

test('resolve returns a known preset by key', function () {
    $resolved = AccentColor::resolve('violet');

    expect($resolved)->toBe([
        'accent' => '#7c3aed',
        'foreground' => '#ffffff',
        'preset' => 'violet',
    ]);
});

test('resolve returns a custom hex color with a computed foreground', function () {
    $resolved = AccentColor::resolve('#ff00aa');

    expect($resolved['accent'])->toBe('#ff00aa')
        ->and($resolved['preset'])->toBeNull()
        ->and(in_array($resolved['foreground'], ['#ffffff', '#0a0a0a'], true))->toBeTrue();
});

test('resolve falls back to amber for garbage input', function () {
    expect(AccentColor::resolve('not-a-color'))->toBe(AccentColor::resolve(null));
});

test('isValidHex only accepts 6-digit hex colors', function () {
    expect(AccentColor::isValidHex('#7c3aed'))->toBeTrue()
        ->and(AccentColor::isValidHex('#fff'))->toBeFalse()
        ->and(AccentColor::isValidHex('7c3aed'))->toBeFalse()
        ->and(AccentColor::isValidHex('violet'))->toBeFalse();
});

test('every preset foreground stays legible against its own accent color', function () {
    foreach (AccentColor::PRESETS as $preset) {
        expect(AccentColor::contrastRatio($preset['accent'], $preset['foreground']))
            ->toBeGreaterThanOrEqual(AccentColor::MIN_TEXT_CONTRAST);
    }
});

test('meetsContrast flags a custom color that nearly disappears against the canvas', function () {
    expect(AccentColor::meetsContrast('#fdfdfd'))->toBeFalse()
        ->and(AccentColor::meetsContrast('#7c3aed'))->toBeTrue();
});

test('foregroundFor picks whichever of black or white contrasts best', function () {
    expect(AccentColor::foregroundFor('#7c3aed'))->toBe('#ffffff')
        ->and(AccentColor::foregroundFor('#fde68a'))->toBe('#0a0a0a');
});

test('resolveForDark returns a preset dark override when one exists', function () {
    expect(AccentColor::resolveForDark('violet'))->toBe([
        'accent' => '#a78bfa',
        'foreground' => '#0a0a0a',
        'preset' => 'violet',
    ]);
});

test('resolveForDark falls back to the same value for a preset with no dark override', function () {
    expect(AccentColor::resolveForDark('teal'))->toBe(AccentColor::resolve('teal'));
});

test('resolveForDark falls back to amber for nothing stored or garbage input', function () {
    expect(AccentColor::resolveForDark(null))->toBe(AccentColor::resolve(null))
        ->and(AccentColor::resolveForDark('not-a-color'))->toBe(AccentColor::resolve(null));
});

test('every flagged preset actually needed a dark override', function () {
    // Sanity check the premise: the base (light) accent value for each preset that has a
    // `dark` override really does fail MIN_TEXT_CONTRAST against the dark canvas on its own —
    // otherwise the override would be dead weight.
    foreach (AccentColor::PRESETS as $key => $preset) {
        if (! isset($preset['dark'])) {
            continue;
        }

        expect(AccentColor::contrastRatio($preset['accent'], AccentColor::CANVAS_DARK))
            ->toBeLessThan(AccentColor::MIN_TEXT_CONTRAST);
    }
});

test('every preset dark override clears AA against the dark canvas and stays legible with its foreground', function () {
    foreach (AccentColor::PRESETS as $key => $preset) {
        if (! isset($preset['dark'])) {
            continue;
        }

        expect(AccentColor::contrastRatio($preset['dark']['accent'], AccentColor::CANVAS_DARK))
            ->toBeGreaterThanOrEqual(AccentColor::MIN_TEXT_CONTRAST)
            ->and(AccentColor::contrastRatio($preset['dark']['accent'], $preset['dark']['foreground']))
            ->toBeGreaterThanOrEqual(AccentColor::MIN_TEXT_CONTRAST);
    }
});

test('lightenForDarkCanvas leaves an already-legible color unchanged', function () {
    expect(AccentColor::lightenForDarkCanvas('#a78bfa'))->toBe('#a78bfa');
});

test('lightenForDarkCanvas lightens a color that fails AA against the dark canvas', function () {
    $lightened = AccentColor::lightenForDarkCanvas('#123456');

    expect($lightened)->not->toBe('#123456')
        ->and(AccentColor::contrastRatio($lightened, AccentColor::CANVAS_DARK))
        ->toBeGreaterThanOrEqual(AccentColor::MIN_TEXT_CONTRAST);
});

test('lightenForDarkCanvas preserves hue while lightening', function () {
    $lightened = AccentColor::lightenForDarkCanvas('#4f46e5');

    // Still a blue/indigo tone (blue channel dominant), just lighter — not shifted toward gray or a different hue.
    $rgb = sscanf($lightened, '#%02x%02x%02x');
    [$r, $g, $b] = $rgb;

    expect($b)->toBeGreaterThan($r)
        ->and($lightened)->not->toBe('#4f46e5');
});

test('resolveForDark lightens a custom hex color and recomputes its foreground', function () {
    $resolved = AccentColor::resolveForDark('#123456');

    expect($resolved['preset'])->toBeNull()
        ->and($resolved['accent'])->not->toBe('#123456')
        ->and(AccentColor::contrastRatio($resolved['accent'], AccentColor::CANVAS_DARK))
        ->toBeGreaterThanOrEqual(AccentColor::MIN_TEXT_CONTRAST)
        ->and($resolved['foreground'])->toBe(AccentColor::foregroundFor($resolved['accent']));
});
