<?php

/**
 * Generates the app icon, splash screens and the web app's apple-touch-icon from the
 * Screening Room mark (public/favicon.svg). The mark is redrawn with ImagickDraw at full
 * resolution, because Imagick's built-in SVG renderer drops strokes and rasterises at 32px.
 * Keep the geometry below in sync with public/favicon.svg.
 *
 * Run: php mobile/resources/brand/generate.php
 */
if (! extension_loaded('imagick')) {
    fwrite(STDERR, "The Imagick extension is required.\n");
    exit(1);
}

$root = dirname(__DIR__, 3);

const AMBER = '#e5a00d';
const DARK_CANVAS = '#0b0b0f';
const LIGHT_CANVAS = '#ffffff';
const INK_LIGHT = '#18181b';
const INK_DARK = '#f4f4f5';
const FONT = '/System/Library/Fonts/SFNS.ttf';

/** Mix $fg over $bg at $alpha, so translucent parts stay opaque and overlaps don't double up. */
function blend(string $fg, string $bg, float $alpha): string
{
    [$fr, $fgG, $fb] = sscanf($fg, '#%02x%02x%02x');
    [$br, $bgG, $bb] = sscanf($bg, '#%02x%02x%02x');

    return sprintf(
        '#%02x%02x%02x',
        round($fr * $alpha + $br * (1 - $alpha)),
        round($fgG * $alpha + $bgG * (1 - $alpha)),
        round($fb * $alpha + $bb * (1 - $alpha)),
    );
}

/**
 * Draw the mark centred on ($cx, $cy), $width pixels wide. Coordinates are favicon.svg's
 * 32-unit viewBox; the mark spans x 4–28 and roughly y 4.2–28.4.
 */
function drawMark(Imagick $image, float $cx, float $cy, float $width, string $background): void
{
    $k = $width / 24;
    $point = fn (float $x, float $y): array => [$cx + ($x - 16) * $k, $cy + ($y - 16.3) * $k];

    $draw = new ImagickDraw;
    $draw->setStrokeAntialias(true);

    // Screen: 20% amber fill with a solid amber outline.
    $draw->setFillColor(new ImagickPixel(blend(AMBER, $background, 0.2)));
    $draw->setStrokeColor(new ImagickPixel(AMBER));
    $draw->setStrokeWidth(1.6 * $k);
    $draw->roundRectangle(...[...$point(4, 5), ...$point(28, 17), 2.5 * $k, 2.5 * $k]);

    // Two sound waves; the lower one at 55%.
    $draw->setFillColor(new ImagickPixel('none'));
    $draw->setStrokeWidth(1.8 * $k);
    $draw->setStrokeLineCap(Imagick::LINECAP_ROUND);

    foreach ([[23, AMBER], [27, blend(AMBER, $background, 0.55)]] as [$y, $color]) {
        $draw->setStrokeColor(new ImagickPixel($color));
        $draw->pathStart();
        $draw->pathMoveToAbsolute(...$point(12.5, $y));
        $draw->pathCurveToAbsolute(...[...$point(14.9, $y - 2), ...$point(17.3, $y - 2), ...$point(19.5, $y)]);
        $draw->pathFinish();
    }

    $image->drawImage($draw);
}

function canvas(int $width, int $height, string $background): Imagick
{
    $image = new Imagick;
    $image->newImage($width, $height, new ImagickPixel($background));
    $image->setImageFormat('png24');

    return $image;
}

function icon(int $size, string $path): void
{
    $image = canvas($size, $size, DARK_CANVAS);
    drawMark($image, $size / 2, $size / 2, $size * 0.6, DARK_CANVAS);
    $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
    $image->writeImage("png24:{$path}");
    echo "Wrote {$path}\n";
}

function splash(string $background, string $ink, string $path): void
{
    [$width, $height, $markWidth] = [1290, 2796, 360];

    $image = canvas($width, $height, $background);
    $markCenterY = $height / 2 - 120;
    drawMark($image, $width / 2, $markCenterY, $markWidth, $background);

    $text = new ImagickDraw;
    $text->setFillColor(new ImagickPixel($ink));
    if (is_file(FONT)) {
        $text->setFont(FONT);
    }
    $text->setFontSize(84);
    $text->setTextAntialias(true);
    $text->setGravity(Imagick::GRAVITY_NORTH);
    $image->annotateImage($text, 0, $markCenterY + $markWidth / 2 + 72, 0, 'Screening Room');

    $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
    $image->writeImage("png24:{$path}");
    echo "Wrote {$path}\n";
}

icon(1024, "{$root}/mobile/public/icon.png");
icon(180, "{$root}/public/apple-touch-icon.png");
splash(LIGHT_CANVAS, INK_LIGHT, "{$root}/mobile/public/splash.png");
splash(DARK_CANVAS, INK_DARK, "{$root}/mobile/public/splash-dark.png");
