<?php

/**
 * Social-share preview (Open Graph / WhatsApp / Telegram): 1200x630, the NDCSN "Community Updates"
 * logo centred on the brand blue. Output: public/icons/share.jpg
 *
 * Run:  php tools/make-share-image.php
 */
$w = 1200;
$h = 630;
$logo = imagecreatefrompng(__DIR__.'/logo-source.png');
imagealphablending($logo, false);
imagesavealpha($logo, true);

$out = imagecreatetruecolor($w, $h);
imagefill($out, 0, 0, imagecolorallocate($out, 0x14, 0x40, 0x7a));

// Soft lighter centre so the badge stands off the background.
for ($r = 520; $r > 0; $r -= 4) {
    $t = $r / 520;
    $c = imagecolorallocatealpha($out, 0x2a, 0x6a, 0xb0, (int) (127 - 40 * (1 - $t)));
    imagefilledellipse($out, $w / 2, $h / 2, $r * 2, $r * 2, $c);
}

$d = 570;
$tmp = imagecreatetruecolor($d, $d);
imagealphablending($tmp, false);
imagesavealpha($tmp, true);
imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
imagecopyresampled($tmp, $logo, 0, 0, 0, 0, $d, $d, imagesx($logo), imagesy($logo));

imagealphablending($out, true);
imagecopy($out, $tmp, (int) (($w - $d) / 2), (int) (($h - $d) / 2), 0, 0, $d, $d);

imagejpeg($out, dirname(__DIR__).'/public/icons/share.jpg', 85);
