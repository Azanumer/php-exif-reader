<?php

declare(strict_types=1);

namespace Azanumer\ExifReader;

/**
 * Thrown when EXIF data cannot be read (missing file, no EXIF block,
 * or the exif extension is unavailable).
 */
class ExifReaderException extends \RuntimeException
{
}
