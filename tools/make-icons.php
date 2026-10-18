<?php

/**
 * Generates the PWA / home-screen icons into public/icons.
 * Run:  php tools/make-icons.php
 */
const BRAND = [0x14, 0x40, 0x7a];

function shield(int $size, float $scale): GdImage
{
    $ss = 4; // draw 4x then downsample for smooth edges
    $n = $size * $ss;
    $img = imagecreatetruecolor($n, $n);
    imageantialias($img, true);

    $bg = imagecolorallocate($img, ...BRAND);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, $n, $n, $bg);

    // Shield outline on a 100x100 grid, scaled and centred.
    // Top edge, then a smooth curve down each side to the point (quadratic bezier).
    $pts = [[50, 10], [86, 22], [86, 48]];
    for ($t = 1; $t <= 24; $t++) {
        $u = $t / 24;
        $pts[] = [
            (1 - $u) ** 2 * 86 + 2 * (1 - $u) * $u * 86 + $u ** 2 * 50,
            (1 - $u) ** 2 * 48 + 2 * (1 - $u) * $u * 80 + $u ** 2 * 92,
        ];
    }
    for ($i = count($pts) - 2; $i >= 3; $i--) {
        $pts[] = [100 - $pts[$i][0], $pts[$i][1]];
    }
    $pts[] = [14, 48];
    $pts[] = [14, 22];
    $flat = [];
    foreach ($pts as [$x, $y]) {
        $flat[] = (int) round($n * (0.5 + ($x - 50) / 100 * $scale));
        $flat[] = (int) round($n * (0.5 + ($y - 51) / 100 * $scale));
    }
    imagefilledpolygon($img, $flat, $white);

    // Tick inside the shield, drawn as thick filled segments with round ends.
    $stroke = $n * 0.07 * $scale;
    $p = fn (float $x, float $y) => [
        $n * (0.5 + ($x - 50) / 100 * $scale),
        $n * (0.5 + ($y - 51) / 100 * $scale),
    ];
    $seg = function (array $a, array $b) use ($img, $bg, $stroke) {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len = max(1, sqrt($dx * $dx + $dy * $dy));
        $nx = -$dy / $len * $stroke / 2;
        $ny = $dx / $len * $stroke / 2;
        imagefilledpolygon($img, [
            (int) round($a[0] + $nx), (int) round($a[1] + $ny),
            (int) round($b[0] + $nx), (int) round($b[1] + $ny),
            (int) round($b[0] - $nx), (int) round($b[1] - $ny),
            (int) round($a[0] - $nx), (int) round($a[1] - $ny),
        ], $bg);
        foreach ([$a, $b] as [$x, $y]) {
            imagefilledellipse($img, (int) round($x), (int) round($y), (int) round($stroke), (int) round($stroke), $bg);
        }
    };
    $seg($p(34, 51), $p(46, 63));
    $seg($p(46, 63), $p(68, 37));

    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $n, $n);

    return $out;
}

$dir = __DIR__.'/../public/icons';
if (! is_dir($dir)) {
    mkdir($dir, 0775, true);
}

// "any" icons: shield fills most of the tile. "maskable": shield kept inside the 80% safe zone.
foreach ([
    'icon-192.png' => [192, 0.80],
    'icon-512.png' => [512, 0.80],
    'icon-maskable-512.png' => [512, 0.58],
    'apple-touch-icon.png' => [180, 0.74],
] as $file => [$size, $scale]) {
    imagepng(shield($size, $scale), "{$dir}/{$file}", 9);
    echo "wrote icons/{$file}\n";
}
