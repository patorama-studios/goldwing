<?php
// One-shot: produce a transparent-background B&W silhouette of the Hawkesbury
// Petroliana logo for the sponsor row's default state. The colour original
// stays as the hover-reveal image.
$src = __DIR__ . '/../public_html/assets/img/sponsors/hawkesbury.png';
$dst = __DIR__ . '/../public_html/assets/img/sponsors/hawkesbury-bw.png';

$img = imagecreatefrompng($src);
imagealphablending($img, false);
imagesavealpha($img, true);
$W = imagesx($img);
$H = imagesy($img);

$out = imagecreatetruecolor($W, $H);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefill($out, 0, 0, $transparent);

// Drop red pixels (oval body + "Motorcycles" text). Preserve existing alpha.
// Convert remaining colour to high-contrast grayscale.
for ($y = 0; $y < $H; $y++) {
    for ($x = 0; $x < $W; $x++) {
        $px = imagecolorat($img, $x, $y);
        $a = ($px >> 24) & 0x7f;
        if ($a == 127) continue; // already transparent
        $r = ($px >> 16) & 0xff;
        $g = ($px >> 8) & 0xff;
        $b = $px & 0xff;
        $isRed = ($r > 90) && ($r - max($g, $b) > 35);
        if ($isRed) continue; // drop red → transparent
        $lum = (int) round(0.299*$r + 0.587*$g + 0.114*$b);
        if ($lum > 180) { $lum = 255; }
        elseif ($lum < 80) { $lum = 10; }
        $c = imagecolorallocatealpha($out, $lum, $lum, $lum, $a);
        imagesetpixel($out, $x, $y, $c);
    }
}

imagepng($out, $dst, 6);
imagedestroy($out);
imagedestroy($img);
echo "Wrote " . $dst . " (" . filesize($dst) . " bytes)\n";
