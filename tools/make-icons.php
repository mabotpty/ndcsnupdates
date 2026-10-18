<?php

/**
 * Generates the favicon, home-screen (PWA) icons and notification badge from the
 * NDCSN "Community Updates" logo (tools/logo-source.png, 1000x1000, transparent).
 *
 * Run:  php tools/make-icons.php
 */
const BRAND = [0x14, 0x40, 0x7a];

function loadLogo(): GdImage
{
    $logo = imagecreatefrompng(__DIR__.'/logo-source.png');
    imagealphablending($logo, false);
    imagesavealpha($logo, true);

    return $logo;
}

/**
 * The logo scaled to $fill of the canvas and centred. Transparent background unless $bg is given
 * (maskable and iOS icons need a solid background; keep $fill <= 0.8 for maskable's safe zone).
 */
function logoIcon(GdImage $logo, int $size, float $fill, ?array $bg = null): GdImage
{
    $out = imagecreatetruecolor($size, $size);
    imagesavealpha($out, true);

    if ($bg) {
        imagealphablending($out, true);
        imagefill($out, 0, 0, imagecolorallocate($out, ...$bg));
    } else {
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    }

    $d = (int) round($size * $fill);
    $off = (int) round(($size - $d) / 2);

    // Downscale in halving steps first so the fine detail of the artwork stays clean at small sizes.
    $src = $logo;
    $w = imagesx($logo);
    while ($w / 2 > $d * 1.5) {
        $w = (int) round($w / 2);
        $half = imagecreatetruecolor($w, $w);
        imagealphablending($half, false);
        imagesavealpha($half, true);
        imagecopyresampled($half, $src, 0, 0, 0, 0, $w, $w, imagesx($src), imagesx($src));
        $src = $half;
    }

    imagealphablending($out, (bool) $bg);
    imagecopyresampled($out, $src, $off, $off, 0, 0, $d, $d, imagesx($src), imagesx($src));

    return $out;
}

function pngBytes(GdImage $img): string
{
    ob_start();
    imagepng($img, null, 9);

    return (string) ob_get_clean();
}

/** A multi-size .ico (PNG-compressed entries, supported by every current browser). */
function icoBytes(array $pngBySize): string
{
    $count = count($pngBySize);
    $header = pack('vvv', 0, 1, $count);
    $entries = '';
    $data = '';
    $offset = 6 + 16 * $count;

    foreach ($pngBySize as $size => $png) {
        $entries .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
        $data .= $png;
        $offset += strlen($png);
    }

    return $header.$entries.$data;
}

/** Monochrome status-bar badge for push notifications (Android uses only the alpha channel). */
function badge(int $size): GdImage
{
    $ss = 4;
    $n = $size * $ss;
    $img = imagecreatetruecolor($n, $n);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
    $white = imagecolorallocatealpha($img, 255, 255, 255, 0);
    imagefill($img, 0, 0, $clear);

    $pts = [[50, 8], [88, 21], [88, 48]];
    for ($t = 1; $t <= 24; $t++) {
        $u = $t / 24;
        $pts[] = [(1 - $u) ** 2 * 88 + 2 * (1 - $u) * $u * 88 + $u ** 2 * 50, (1 - $u) ** 2 * 48 + 2 * (1 - $u) * $u * 82 + $u ** 2 * 94];
    }
    for ($i = count($pts) - 2; $i >= 3; $i--) {
        $pts[] = [100 - $pts[$i][0], $pts[$i][1]];
    }
    $pts[] = [12, 48];
    $pts[] = [12, 21];
    $flat = [];
    foreach ($pts as [$x, $y]) {
        $flat[] = (int) round($x / 100 * $n);
        $flat[] = (int) round($y / 100 * $n);
    }
    imagefilledpolygon($img, $flat, $white);

    // Punch the tick out of the shield (transparent).
    $stroke = $n * 0.075;
    $seg = function (array $a, array $b) use ($img, $clear, $stroke, $n) {
        [$ax, $ay, $bx, $by] = [$a[0] / 100 * $n, $a[1] / 100 * $n, $b[0] / 100 * $n, $b[1] / 100 * $n];
        $len = max(1, sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2));
        $nx = -($by - $ay) / $len * $stroke / 2;
        $ny = ($bx - $ax) / $len * $stroke / 2;
        imagefilledpolygon($img, [
            (int) round($ax + $nx), (int) round($ay + $ny), (int) round($bx + $nx), (int) round($by + $ny),
            (int) round($bx - $nx), (int) round($by - $ny), (int) round($ax - $nx), (int) round($ay - $ny),
        ], $clear);
        foreach ([[$ax, $ay], [$bx, $by]] as [$x, $y]) {
            imagefilledellipse($img, (int) round($x), (int) round($y), (int) round($stroke), (int) round($stroke), $clear);
        }
    };
    $seg([34, 50], [46, 62]);
    $seg([46, 62], [68, 36]);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $n, $n);

    return $out;
}

$logo = loadLogo();
$public = __DIR__.'/../public';
$dir = "{$public}/icons";
if (! is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$write = function (string $path, GdImage $img) {
    file_put_contents($path, pngBytes($img));
    echo 'wrote '.str_replace(realpath(__DIR__.'/..').DIRECTORY_SEPARATOR, '', realpath($path))."\n";
};

// "any": the round logo on a transparent background, edge to edge.
$write("{$dir}/icon-192.png", logoIcon($logo, 192, 1.0));
$write("{$dir}/icon-512.png", logoIcon($logo, 512, 1.0));
// Maskable: solid brand-blue tile; the whole round logo sits inside the 80% safe zone so no OS mask can crop it.
$write("{$dir}/icon-maskable-512.png", logoIcon($logo, 512, 0.78, BRAND));
// iOS ignores transparency (it would paint black), so give it a solid tile too.
$write("{$dir}/apple-touch-icon.png", logoIcon($logo, 180, 0.90, BRAND));
$write("{$dir}/badge-96.png", badge(96));

// Favicons.
$write("{$dir}/favicon-16.png", logoIcon($logo, 16, 1.0));
$write("{$dir}/favicon-32.png", logoIcon($logo, 32, 1.0));
$write("{$dir}/favicon-48.png", logoIcon($logo, 48, 1.0));
file_put_contents("{$public}/favicon.ico", icoBytes([
    16 => pngBytes(logoIcon($logo, 16, 1.0)),
    32 => pngBytes(logoIcon($logo, 32, 1.0)),
    48 => pngBytes(logoIcon($logo, 48, 1.0)),
]));
echo "wrote favicon.ico\n";
