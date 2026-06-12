<?php
// One-shot: produce a transparent-background B&W variant of the Hawkesbury
// Petroliana logo for the sponsor row's default state. The colour original
// stays as the hover-reveal image.
$src = __DIR__ . '/../public_html/assets/img/sponsors/hawkesbury.webp';
$dst = __DIR__ . '/../public_html/assets/img/sponsors/hawkesbury-bw.png';

$img = imagecreatefromwebp($src);
$srcW = imagesx($img);
$srcH = imagesy($img);

// Crop ~6% off each edge to remove the white frame around the design.
$crop = (int) round($srcW * 0.06);
$cropW = $srcW - 2 * $crop;
$cropH = $srcH - 2 * $crop;

$W = 1000; $H = 1000;
$out = imagecreatetruecolor($W, $H);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefill($out, 0, 0, $transparent);
imagecopyresampled($out, $img, 0, 0, $crop, $crop, $W, $H, $cropW, $cropH);
imagedestroy($img);

// Drop all red pixels (background + the "Motorcycles" red text inside the banner;
// hover swaps to the colour version so we don't need it here). Convert the rest
// to high-contrast grayscale.
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        $px = imagecolorat($out, $x, $y);
        $r = ($px >> 16) & 0xff;
        $g = ($px >> 8) & 0xff;
        $b = $px & 0xff;
        $isRed = ($r > 90) && ($r - max($g, $b) > 35);
        if ($isRed) {
            imagesetpixel($out, $x, $y, $transparent);
            continue;
        }
        $lum = (int) round(0.299*$r + 0.587*$g + 0.114*$b);
        if ($lum > 180) { $lum = 255; }
        elseif ($lum < 80) { $lum = 10; }
        $c = imagecolorallocatealpha($out, $lum, $lum, $lum, 0);
        imagesetpixel($out, $x, $y, $c);
    }
}

imagepng($out, $dst, 6);
imagedestroy($out);
echo "Wrote " . $dst . " (" . filesize($dst) . " bytes)\n";
