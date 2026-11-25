<?php

declare(strict_types=1);

require __DIR__ . '/../src/ExifReader.php';
require __DIR__ . '/../src/ExifReaderException.php';

use Azanumer\ExifReader\ExifReader;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php exif-example.php <image.jpg>\n");
    exit(1);
}

$path = $argv[1];

if (!function_exists('exif_read_data')) {
    fwrite(STDERR, "The exif extension is not enabled.\n");
    exit(1);
}

try {
    $exif = ExifReader::fromFile($path);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'File:     ' . $path . "\n";
echo 'Camera:   ' . ($exif->camera() ?? 'unknown') . "\n";

$taken = $exif->takenAt();
echo 'Taken:    ' . ($taken ? $taken->format('Y-m-d H:i:s') : 'unknown') . "\n";

$dims = $exif->dimensions();
echo 'Size:     ' . ($dims ? $dims[0] . 'x' . $dims[1] : 'unknown') . "\n";

$orientation = $exif->orientation();
echo 'Orient:   ' . ($orientation ?? 'unknown') . "\n";

foreach ($exif->exposure() as $label => $value) {
    echo ucfirst($label) . ': ' . str_repeat(' ', max(0, 8 - strlen($label))) . $value . "\n";
}

$gps = $exif->gps();
if ($gps) {
    echo 'GPS:      ' . $gps[0] . ', ' . $gps[1] . "\n";
    echo 'Maps:     ' . $exif->mapsLink() . "\n";
} else {
    echo "GPS:      none\n";
}
