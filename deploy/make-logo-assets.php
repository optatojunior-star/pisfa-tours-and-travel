<?php

/**
 * Derives every logo asset from resources/brand/logo-source.png.
 *
 * The source is a square lockup: globe, PISFA wordmark, "Tour and travel", then
 * a two-line tagline. Different placements need different amounts of that:
 *
 *   mark        the globe alone      favicon, PWA icon, avatar, anywhere under 64px
 *               where the wordmark would be illegible
 *   lockup      globe + wordmark     site header, PDF letterhead, email header
 *   full        everything           about page, printed material
 *
 * Trimmed to content and re-padded, so each asset is optically centred rather
 * than inheriting the source file's whitespace.
 *
 * Run:  php deploy/make-logo-assets.php
 */
$source = 'resources/brand/logo-source.png';
$im = imagecreatefrompng($source);
$w = imagesx($im);
$h = imagesy($im);

/** Horizontal content bounds within a vertical slice. */
function horizontalBounds($im, int $top, int $bottom): array
{
    $w = imagesx($im);
    $left = $w;
    $right = 0;
    for ($y = $top; $y <= $bottom; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($im, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if ($r > 232 && $g > 232 && $b > 232) {
                continue;
            }
            if ($x < $left) {
                $left = $x;
            }
            if ($x > $right) {
                $right = $x;
            }
        }
    }

    return [$left, $right];
}

/**
 * Crops a band, drops the near-white background to transparency, and writes a
 * PNG at the requested width.
 */
function emit($im, string $path, int $top, int $bottom, int $targetWidth, int $pad = 0, ?array $bg = null, ?int $square = null): void
{
    [$left, $right] = horizontalBounds($im, $top, $bottom);
    $cw = $right - $left + 1;
    $ch = $bottom - $top + 1;

    $ratio = $ch / $cw;
    $tw = $targetWidth;
    $th = (int) round($tw * $ratio);

    // A PWA icon must be exactly square, or the platform letterboxes it and the
    // manifest's declared size becomes a lie. The mark is centred instead.
    if ($square !== null) {
        $canvasW = $canvasH = $square;
        $offsetX = (int) round(($square - $tw) / 2);
        $offsetY = (int) round(($square - $th) / 2);
    } else {
        $canvasW = $tw + $pad * 2;
        $canvasH = $th + $pad * 2;
        $offsetX = $offsetY = $pad;
    }

    $out = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($out, false);
    imagesavealpha($out, true);

    if ($bg === null) {
        imagefilledrectangle($out, 0, 0, $canvasW, $canvasH, imagecolorallocatealpha($out, 0, 0, 0, 127));
    } else {
        imagefilledrectangle($out, 0, 0, $canvasW, $canvasH, imagecolorallocate($out, ...$bg));
    }

    imagealphablending($out, true);
    imagecopyresampled($out, $im, $offsetX, $offsetY, $left, $top, $tw, $th, $cw, $ch);

    // Knock the near-white source background out to transparency, unless a
    // solid background was requested (maskable icons need full bleed).
    if ($bg === null) {
        imagealphablending($out, false);
        for ($y = 0; $y < $canvasH; $y++) {
            for ($x = 0; $x < $canvasW; $x++) {
                $rgb = imagecolorat($out, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($r > 236 && $g > 236 && $b > 236) {
                    imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, 127));
                }
            }
        }
        imagesavealpha($out, true);
    }

    imagepng($out, $path, 9);
    imagedestroy($out);
    printf("  %-38s %4dx%-4d %6d bytes\n", basename($path), $canvasW, $canvasH, filesize($path));
}

// Bands measured from the source artwork.
$MARK = [170, 623];
$LOCKUP = [170, 944];
$FULL = [170, 1059];

echo "logo assets\n";
emit($im, 'public/images/logo-mark.png', ...$MARK, targetWidth: 512);
emit($im, 'public/images/logo.png', ...$LOCKUP, targetWidth: 720);
emit($im, 'public/images/logo-full.png', ...$FULL, targetWidth: 900);

echo "\npwa + favicon (mark only - a wordmark is unreadable at 48px)\n";
$brand = [8, 160, 144];   // #08A090, the logo teal
emit($im, 'public/icons/icon-192.png', ...$MARK, targetWidth: 176, square: 192);
emit($im, 'public/icons/icon-512.png', ...$MARK, targetWidth: 470, square: 512);
// Maskable: the mark must sit inside the middle 80% so a launcher can crop it
// to any shape without clipping, and the ground must be opaque.
emit($im, 'public/icons/icon-maskable-512.png', ...$MARK, targetWidth: 330, square: 512, bg: [255, 255, 255]);
emit($im, 'public/icons/apple-touch-icon.png', ...$MARK, targetWidth: 164, square: 180, bg: [255, 255, 255]);

imagedestroy($im);

// ---------------------------------------------------------------------------
// Favicons
// ---------------------------------------------------------------------------
// public/favicon.ico existed but was zero bytes, so every browser requesting it
// got an empty file and fell back to a blank page icon.

$mark = imagecreatefrompng('public/icons/icon-512.png');

foreach ([16, 32, 48, 180] as $size) {
    $fav = imagecreatetruecolor($size, $size);
    imagealphablending($fav, false);
    imagesavealpha($fav, true);
    imagefilledrectangle($fav, 0, 0, $size, $size, imagecolorallocatealpha($fav, 0, 0, 0, 127));
    imagealphablending($fav, true);
    imagecopyresampled($fav, $mark, 0, 0, 0, 0, $size, $size, imagesx($mark), imagesy($mark));
    imagepng($fav, "public/images/favicon-{$size}.png", 9);
    imagedestroy($fav);
    printf("  favicon-%d.png\n", $size);
}

/*
 * A real .ico, so the browser's automatic /favicon.ico request gets something.
 * The ICO container can hold a PNG directly, which avoids writing a BMP encoder
 * for a 32x32 image.
 */
$png = file_get_contents('public/images/favicon-32.png');
$ico = pack('vvv', 0, 1, 1)                       // reserved, type=icon, one image
     .pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22);
file_put_contents('public/favicon.ico', $ico.$png);
printf("  favicon.ico  %d bytes\n", filesize('public/favicon.ico'));

imagedestroy($mark);
