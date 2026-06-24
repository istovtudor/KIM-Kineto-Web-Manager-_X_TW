<?php

const REPORTS_CHARTS_DIR = 'public/reports/charts';

function getReportsChartsDir(): string
{
    $dir = dirname(__DIR__) . '/' . REPORTS_CHARTS_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Cale app-relative (fara KIM_BASE); frontend rezolva cu assetUrl(). */
function reportPublicPath(string $filename): string
{
    return '/' . REPORTS_CHARTS_DIR . '/' . ltrim($filename, '/');
}

function generateChart(array $labels, array $values, string $title, string $path, string $format = 'png'): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    $labels = array_values($labels);
    $values = array_map('intval', array_values($values));
    $count = count($values);

    $w = max(600, 80 + $count * 70);
    $h = 400;
    $img = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($img, 255, 255, 255);
    $blue = imagecolorallocate($img, 41, 128, 185);
    $green = imagecolorallocate($img, 39, 174, 96);
    $gray = imagecolorallocate($img, 200, 200, 200);
    $black = imagecolorallocate($img, 30, 30, 30);
    imagefill($img, 0, 0, $white);
    imagestring($img, 5, 20, 10, $title, $black);

    if ($count === 0) {
        imagestring($img, 4, 20, (int) ($h / 2), 'Nu exista date', $black);
        $ok = $format === 'webp' && function_exists('imagewebp')
            ? imagewebp($img, $path, 80)
            : imagepng($img, $path);
        imagedestroy($img);
        return $ok;
    }

    $max = max(1, max($values));
    $barW = 40;
    $gap = max(50, min(90, (int) (($w - 80) / $count)));
    $baseY = $h - 60;
    $x = 50;

    foreach ($values as $i => $val) {
        $barH = (int) (($val / $max) * ($h - 120));
        $color = $i % 2 === 0 ? $blue : $green;
        imagefilledrectangle($img, $x, $baseY - $barH, $x + $barW, $baseY, $color);
        imagestring($img, 3, $x, $baseY + 5, substr($labels[$i] ?? '', 0, 10), $black);
        imagestring($img, 2, $x, $baseY - $barH - 15, (string) $val, $black);
        $x += $gap;
    }

    imageline($img, 40, $baseY, $w - 20, $baseY, $gray);

    $ok = $format === 'webp' && function_exists('imagewebp')
        ? imagewebp($img, $path, 80)
        : imagepng($img, $path);
    imagedestroy($img);
    return $ok;
}

function saveChartPair(array $labels, array $values, string $title, string $basename, ?string $reportsDir = null): array
{
    $reportsDir = $reportsDir ?? getReportsChartsDir();
    $pngPath = $reportsDir . '/' . $basename . '.png';
    $webpPath = $reportsDir . '/' . $basename . '.webp';
    $pngOk = generateChart($labels, $values, $title, $pngPath, 'png');
    generateChart($labels, $values, $title, $webpPath, 'webp');

    return [
        'png' => reportPublicPath($basename . '.png'),
        'webp' => reportPublicPath($basename . '.webp'),
        'generated' => $pngOk && file_exists($pngPath),
        'gd_available' => extension_loaded('gd'),
    ];
}
