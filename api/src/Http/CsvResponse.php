<?php

declare(strict_types=1);

namespace Medisa\Api\Http;

class CsvResponse
{
    /** @param array<int, string> $columns @param array<int, array<string, mixed>> $rows */
    public static function send($filename, array $columns, array $rows)
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . self::sanitizeFilename($filename) . '"');
            http_response_code(200);
        }

        echo "\xEF\xBB\xBF";
        echo self::build($columns, $rows);
        exit;
    }

    /** @param array<int, string> $columns @param array<int, array<string, mixed>> $rows */
    public static function build(array $columns, array $rows)
    {
        return self::buildDelimited($columns, $rows, ',');
    }

    /**
     * Semicolon CSV body without BOM (CRLF). Used by personel import reference pack.
     *
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public static function buildSemicolon(array $columns, array $rows)
    {
        return self::buildDelimited($columns, $rows, ';');
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    public static function buildDelimited(array $columns, array $rows, $delimiter)
    {
        $delimiter = (string) $delimiter;
        $lines = [];
        $lines[] = implode($delimiter, array_map(static function ($column) use ($delimiter) {
            return self::cell($column, $delimiter);
        }, $columns));
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = self::cell(isset($row[$column]) ? $row[$column] : '', $delimiter);
            }
            $lines[] = implode($delimiter, $cells);
        }

        return implode("\r\n", $lines);
    }

    /**
     * Canonical CSV cell encoder (formula injection + quoting).
     * Delimiter-aware quoting preserves comma-CSV byte contracts for existing exports.
     *
     * @param mixed $value
     */
    public static function cell($value, $delimiter = ',')
    {
        if ($value === null) {
            $text = '';
        } elseif (is_bool($value)) {
            $text = $value ? '1' : '0';
        } elseif (is_scalar($value)) {
            $text = (string) $value;
        } else {
            $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // First meaningful char after leading spaces (spaces only; tab/CR/LF are formula chars).
        $probe = ltrim($text, ' ');
        $first = $probe !== '' ? $probe[0] : '';
        if (
            $first === '='
            || $first === '+'
            || $first === '-'
            || $first === '@'
            || $first === "\t"
            || $first === "\r"
            || $first === "\n"
        ) {
            $text = "'" . $text;
        }

        $quoteNeedles = $delimiter . "\"\n\r";
        $needsQuote = strpbrk($text, $quoteNeedles) !== false;
        $escaped = str_replace('"', '""', $text);

        return $needsQuote ? '"' . $escaped . '"' : $escaped;
    }

    private static function sanitizeFilename($filename)
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $filename);

        return $safe !== '' ? $safe : 'export.csv';
    }
}
