<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal streaming CSV reader.
 *
 * Streams rather than slurping: observation.csv is the largest file here, but
 * a real incident's would be far larger, and nothing in the pipeline needs the
 * whole file in memory at once.
 */
class Csv
{
    /**
     * @return \Generator<int, array<string, string>>
     */
    public static function rows(string $path): \Generator
    {
        if (! is_readable($path)) {
            throw new RuntimeException("CSV not readable: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open CSV: {$path}");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');

            if ($header === false) {
                return;
            }

            // Strip a UTF-8 BOM, which would otherwise corrupt the first column name.
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                // Skip blank trailing lines.
                if ($row === [null] || $row === [''] || $row === []) {
                    continue;
                }

                $row = array_pad(array_slice($row, 0, count($header)), count($header), '');

                yield array_combine($header, array_map(
                    fn ($value) => is_string($value) ? trim($value) : '',
                    $row,
                ));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Empty CSV cells mean "not recorded", which must reach the database as
     * NULL rather than an empty string — the difference decides whether the
     * scorer treats a field as absent evidence or as an observation.
     */
    public static function nullable(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    public static function int(?string $value): ?int
    {
        $value = self::nullable($value);

        return $value === null ? null : (int) $value;
    }

    public static function float(?string $value): ?float
    {
        $value = self::nullable($value);

        return $value === null ? null : (float) $value;
    }

    public static function bool(?string $value): bool
    {
        return in_array(self::nullable($value), ['1', 'true', 'yes'], true);
    }
}
