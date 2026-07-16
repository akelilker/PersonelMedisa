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
        $lines = [];
        $lines[] = implode(',', array_map([self::class, 'cell'], $columns));
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = self::cell(isset($row[$column]) ? $row[$column] : '');
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\r\n", $lines);
    }

    /** @param mixed $value */
    private static function cell($value)
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

        if ($text !== '' && preg_match('/^[=+\-@]/', $text)) {
            $text = "'" . $text;
        }

        $needsQuote = strpbrk($text, ",\"\n\r") !== false;
        $escaped = str_replace('"', '""', $text);

        return $needsQuote ? '"' . $escaped . '"' : $escaped;
    }

    private static function sanitizeFilename($filename)
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $filename);

        return $safe !== '' ? $safe : 'export.csv';
    }
}
