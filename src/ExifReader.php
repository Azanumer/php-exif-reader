<?php

declare(strict_types=1);

namespace Azanumer\ExifReader;

/**
 * Dependency-free EXIF reader for JPEG and TIFF images.
 *
 * Wraps PHP's exif extension with a clean API: camera info, capture date,
 * dimensions, orientation, and GPS coordinates (converted from the
 * degrees/minutes/seconds rationals EXIF stores) with a Google Maps link.
 *
 * Requires the `exif` extension (bundled with PHP, enabled by default).
 */
class ExifReader
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data Raw exif_read_data() output.
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Read EXIF data from an image file.
     *
     * @param string $path Path to a JPEG or TIFF file.
     * @return self
     * @throws ExifReaderException When the file cannot be read or has no EXIF data.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ExifReaderException("Cannot read file: {$path}");
        }
        $data = @exif_read_data($path, 'ANY_TAG', true);
        if (false === $data) {
            throw new ExifReaderException("No EXIF data found in: {$path}");
        }
        return new self($data);
    }

    /**
     * Read EXIF data from raw image bytes (e.g. an upload in memory).
     *
     * @param string $bytes Raw JPEG/TIFF bytes.
     * @return self
     * @throws ExifReaderException When the bytes have no EXIF data.
     */
    public static function fromString(string $bytes): self
    {
        $tmp = tempnam(sys_get_temp_dir(), 'exif_');
        if (false === $tmp) {
            throw new ExifReaderException('Could not create a temporary file.');
        }
        try {
            file_put_contents($tmp, $bytes);
            return self::fromFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Get a raw EXIF value by section and key, e.g. get('IFD0', 'Make').
     *
     * @param string $section Section name (IFD0, EXIF, GPS, ...).
     * @param string $key Tag name.
     * @param mixed $default Value when the tag is missing.
     * @return mixed
     */
    public function get(string $section, string $key, $default = null)
    {
        return $this->data[$section][$key] ?? $default;
    }

    /**
     * Get the whole raw EXIF array (sections included).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Camera make, e.g. "Canon".
     */
    public function make(): ?string
    {
        $make = $this->get('IFD0', 'Make');
        return is_string($make) ? trim($make) : null;
    }

    /**
     * Camera model, e.g. "Canon EOS 2000D".
     */
    public function model(): ?string
    {
        $model = $this->get('IFD0', 'Model');
        return is_string($model) ? trim($model) : null;
    }

    /**
     * "Make Model", e.g. "Canon EOS 2000D" (make not repeated).
     */
    public function camera(): ?string
    {
        $make  = $this->make();
        $model = $this->model();
        if (null === $make) {
            return $model;
        }
        if (null === $model) {
            return $make;
        }
        // Model often already starts with the make ("Canon EOS ...").
        if (0 === stripos($model, $make)) {
            return $model;
        }
        return $make . ' ' . $model;
    }

    /**
     * When the photo was taken (DateTimeOriginal), or null when unknown.
     */
    public function takenAt(): ?\DateTimeImmutable
    {
        $raw = $this->get('EXIF', 'DateTimeOriginal')
            ?? $this->get('EXIF', 'DateTimeDigitized')
            ?? $this->get('IFD0', 'DateTime');
        if (!is_string($raw)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $raw);
        return false === $dt ? null : $dt;
    }

    /**
     * Image dimensions in pixels, [width, height], or null when unknown.
     *
     * @return array{int, int}|null
     */
    public function dimensions(): ?array
    {
        $width  = $this->get('COMPUTED', 'Width');
        $height = $this->get('COMPUTED', 'Height');
        if (is_numeric($width) && is_numeric($height)) {
            return [(int) $width, (int) $height];
        }
        return null;
    }

    /**
     * EXIF orientation (1-8), or null when unknown.
     * 1 = normal, 3 = 180 deg, 6 = 90 deg CW, 8 = 90 deg CCW.
     */
    public function orientation(): ?int
    {
        $value = $this->get('IFD0', 'Orientation');
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Exposure settings as a human-readable array.
     *
     * @return array<string, string>
     */
    public function exposure(): array
    {
        $out = [];
        $fNumber = $this->get('EXIF', 'FNumber');
        if (is_string($fNumber) && false !== strpos($fNumber, '/')) {
            $out['aperture'] = 'f/' . $this->rationalToFloat($fNumber);
        }
        $exposureTime = $this->get('EXIF', 'ExposureTime');
        if (is_string($exposureTime)) {
            $out['shutter'] = $this->formatShutter($exposureTime);
        }
        $iso = $this->get('EXIF', 'ISOSpeedRatings');
        if (is_numeric($iso)) {
            $out['iso'] = 'ISO ' . (int) $iso;
        }
        $focal = $this->get('EXIF', 'FocalLength');
        if (is_string($focal)) {
            $out['focal_length'] = $this->rationalToFloat($focal) . 'mm';
        }
        return $out;
    }

    /**
     * GPS coordinates as [latitude, longitude] in decimal degrees,
     * or null when the image has no GPS data.
     *
     * @return array{float, float}|null
     */
    public function gps(): ?array
    {
        $lat = $this->get('GPS', 'GPSLatitude');
        $latRef = $this->get('GPS', 'GPSLatitudeRef');
        $lon = $this->get('GPS', 'GPSLongitude');
        $lonRef = $this->get('GPS', 'GPSLongitudeRef');
        if (!is_array($lat) || !is_array($lon)) {
            return null;
        }
        $latitude  = $this->dmsToDecimal($lat);
        $longitude = $this->dmsToDecimal($lon);
        if (null === $latitude || null === $longitude) {
            return null;
        }
        if ('S' === strtoupper((string) $latRef)) {
            $latitude = -$latitude;
        }
        if ('W' === strtoupper((string) $lonRef)) {
            $longitude = -$longitude;
        }
        return [$latitude, $longitude];
    }

    /**
     * Google Maps link for the photo location, or null when no GPS data.
     */
    public function mapsLink(): ?string
    {
        $gps = $this->gps();
        if (null === $gps) {
            return null;
        }
        return sprintf('https://maps.google.com/?q=%F,%F', $gps[0], $gps[1]);
    }

    /**
     * Convert a GPS degrees/minutes/seconds array of "num/den" rationals
     * to decimal degrees.
     *
     * @param array<int, string> $dms
     */
    private function dmsToDecimal(array $dms): ?float
    {
        if (count($dms) < 3) {
            return null;
        }
        $degrees = $this->rationalToFloat((string) $dms[0]);
        $minutes = $this->rationalToFloat((string) $dms[1]);
        $seconds = $this->rationalToFloat((string) $dms[2]);
        if (null === $degrees || null === $minutes || null === $seconds) {
            return null;
        }
        return $degrees + $minutes / 60 + $seconds / 3600;
    }

    /**
     * Convert an EXIF rational string ("num/den" or "num") to float.
     */
    private function rationalToFloat(string $value): ?float
    {
        if (false !== strpos($value, '/')) {
            [$num, $den] = explode('/', $value, 2);
            if (!is_numeric($num) || !is_numeric($den) || 0 == (float) $den) {
                return null;
            }
            return (float) $num / (float) $den;
        }
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Format an exposure-time rational as "1/250" or "2"".
     */
    private function formatShutter(string $value): string
    {
        $seconds = $this->rationalToFloat($value);
        if (null === $seconds || $seconds <= 0) {
            return $value;
        }
        if ($seconds >= 1) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.') . '"';
        }
        return '1/' . (string) (int) round(1 / $seconds);
    }
}
