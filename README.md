# PHP EXIF Reader

Dependency-free EXIF reader for JPEG and TIFF images — camera info, capture date, dimensions, exposure settings, and GPS coordinates with a Google Maps link.

## Usage

```php
require 'src/ExifReader.php';
require 'src/ExifReaderException.php';

use Azanumer\ExifReader\ExifReader;

$exif = ExifReader::fromFile('photo.jpg');

echo $exif->camera();      // "Canon EOS 2000D"
echo $exif->takenAt()->format('Y-m-d H:i:s'); // "2026-11-20 14:32:10"

[$width, $height] = $exif->dimensions(); // [6000, 4000]
echo $exif->orientation(); // 1 (normal), 6 (rotated 90° CW), ...

print_r($exif->exposure());
// ['aperture' => 'f/2.8', 'shutter' => '1/250', 'iso' => 'ISO 100', 'focal_length' => '50mm']

[$lat, $lon] = $exif->gps(); // [31.5204, 74.3587] or null
echo $exif->mapsLink();      // https://maps.google.com/?q=31.5204,74.3587
```

Reading from raw bytes (e.g. an upload held in memory) works too:

```php
$exif = ExifReader::fromString(file_get_contents('php://input'));
```

## API

| Method | Returns |
|---|---|
| `fromFile(string $path)` | `self` — throws `ExifReaderException` when unreadable or EXIF-less |
| `fromString(string $bytes)` | `self` — same, from raw image bytes |
| `get(string $section, string $key, $default = null)` | Raw tag value, e.g. `get('IFD0', 'Make')` |
| `all()` | The full raw `exif_read_data()` array |
| `make()` / `model()` / `camera()` | Camera strings (`camera()` never repeats the make) |
| `takenAt()` | `DateTimeImmutable` from `DateTimeOriginal`, or `null` |
| `dimensions()` | `[width, height]` or `null` |
| `orientation()` | EXIF orientation 1–8, or `null` |
| `exposure()` | `['aperture', 'shutter', 'iso', 'focal_length']` (only keys present) |
| `gps()` | `[latitude, longitude]` in decimal degrees, or `null` |
| `mapsLink()` | Google Maps link, or `null` |

GPS degrees/minutes/seconds rationals are converted to decimal degrees, with `S`/`W` refs applied as negatives. Shutter speeds print as `1/250` (or `2"` for exposures of one second or more).

## Requirements

- PHP 7.4+
- The `exif` extension (bundled with PHP, enabled by default on most installs)

## Example

```bash
php examples/exif-example.php photo.jpg
```

## License

MIT. See [LICENSE](LICENSE).
