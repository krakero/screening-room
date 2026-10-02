<?php

namespace App\Services\Collection;

use App\Enums\CollectionFormat;
use App\Enums\TitleType;

/**
 * Parses a collection CSV export with validation. Returns typed rows and
 * row-level errors for invalid entries.
 */
class CsvImporter
{
    /**
     * @return array{rows: array<int, array{title: string, year: ?int, type: TitleType, season: ?int, format: CollectionFormat, edition: ?string, retailer: ?string, barcode: ?string, acquired_at: ?string, price: ?float, currency: ?string, location: ?string, notes: ?string, row_number: int}>, errors: array<int, array{row: int, message: string}>}
     */
    public function parse(string $path): array
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("CSV file not found: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \InvalidArgumentException("Cannot open CSV file: {$path}");
        }

        $header = fgetcsv($handle);

        if ($header === false || $header === null) {
            fclose($handle);

            throw new \InvalidArgumentException('CSV file is empty or invalid.');
        }

        $header = array_map('trim', $header);
        $header = array_map('strtolower', $header);

        $rows = [];
        $errors = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count($data) === 0 || (count($data) === 1 && trim($data[0]) === '')) {
                continue;
            }

            $row = array_combine($header, array_pad($data, count($header), ''));

            if ($row === false) {
                $errors[] = ['row' => $rowNumber, 'message' => 'Column count mismatch'];

                continue;
            }

            try {
                $rows[] = $this->parseRow($row, $rowNumber);
            } catch (\Exception $exception) {
                $errors[] = ['row' => $rowNumber, 'message' => $exception->getMessage()];
            }
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  array<string, string>  $row
     * @return array{title: string, year: ?int, type: TitleType, season: ?int, format: CollectionFormat, edition: ?string, retailer: ?string, barcode: ?string, acquired_at: ?string, price: ?float, currency: ?string, location: ?string, notes: ?string, row_number: int}
     */
    private function parseRow(array $row, int $rowNumber): array
    {
        $title = trim($row['title'] ?? '');

        if ($title === '') {
            throw new \Exception('Title is required');
        }

        $year = $this->parseYear($row['year'] ?? '');
        $type = $this->parseType($row['type'] ?? 'movie');
        $season = $this->parseSeason($row['season'] ?? '');
        $format = $this->parseFormat($row['format'] ?? '');

        return [
            'title' => $title,
            'year' => $year,
            'type' => $type,
            'season' => $season,
            'format' => $format,
            'edition' => $this->trimOrNull($row['edition'] ?? ''),
            'retailer' => $this->trimOrNull($row['retailer'] ?? ''),
            'barcode' => $this->trimOrNull($row['barcode'] ?? ''),
            'acquired_at' => $this->parseDate($row['acquired_at'] ?? ''),
            'price' => $this->parsePrice($row['price'] ?? ''),
            'currency' => $this->trimOrNull($row['currency'] ?? ''),
            'location' => $this->trimOrNull($row['location'] ?? ''),
            'notes' => $this->trimOrNull($row['notes'] ?? ''),
            'row_number' => $rowNumber,
        ];
    }

    private function parseYear(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new \Exception("Invalid year: {$value}");
        }

        $year = (int) $value;

        if ($year < 1800 || $year > 2100) {
            throw new \Exception("Year out of range: {$year}");
        }

        return $year;
    }

    private function parseType(string $value): TitleType
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'movie', '' => TitleType::Movie,
            'show', 'tv', 'series' => TitleType::Show,
            default => throw new \Exception("Invalid type: {$value} (must be 'movie' or 'show')"),
        };
    }

    private function parseSeason(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new \Exception("Invalid season number: {$value}");
        }

        $season = (int) $value;

        if ($season < 0) {
            throw new \Exception("Season number cannot be negative: {$season}");
        }

        return $season;
    }

    private function parseFormat(string $value): CollectionFormat
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            throw new \Exception('Format is required');
        }

        // Try to match enum values first
        $enumValue = match ($value) {
            'uhd_4k', '4k', 'uhd', '4k uhd' => CollectionFormat::Uhd4k,
            'bluray', 'blu-ray', 'bd', 'blu ray' => CollectionFormat::BluRay,
            'dvd' => CollectionFormat::Dvd,
            'digital' => CollectionFormat::Digital,
            default => null,
        };

        if ($enumValue !== null) {
            return $enumValue;
        }

        // Try to match labels case-insensitively
        foreach (CollectionFormat::cases() as $case) {
            if (strtolower($case->label()) === $value) {
                return $case;
            }
        }

        throw new \Exception("Invalid format: {$value} (must be one of: 4K UHD, Blu-ray, DVD, Digital)");
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            throw new \Exception("Invalid date: {$value}");
        }

        return date('Y-m-d', $timestamp);
    }

    private function parsePrice(string $value): ?float
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Remove currency symbols and commas
        $value = preg_replace('/[^\d.]/', '', $value);

        if (! is_numeric($value)) {
            throw new \Exception("Invalid price: {$value}");
        }

        $price = (float) $value;

        if ($price < 0) {
            throw new \Exception("Price cannot be negative: {$price}");
        }

        return $price;
    }

    private function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
