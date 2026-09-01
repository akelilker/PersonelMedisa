<?php

declare(strict_types=1);

namespace Medisa\Api\Support;

/**
 * Minimal Office Open XML (.xlsx) writer using ZipArchive — no external deps.
 * Supports multiple named sheets with header + data rows.
 */
final class SimpleXlsxWriter
{
    /** @var list<array{name:string, headers:list<string>, rows:list<list<string>>}> */
    private $sheets = [];

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function addSheet(string $name, array $headers, array $rows): void
    {
        $safeName = mb_substr(preg_replace('/[\[\]\*\?:\/\\\\]/', '', $name) ?: 'Sheet', 0, 31);
        $this->sheets[] = [
            'name' => $safeName,
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    public function buildBinary(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension required for XLSX export.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'medisa_xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('Temporary file could not be created.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('XLSX zip could not be opened.');
        }

        $sheetXmlParts = [];
        $workbookSheets = [];
        $rels = [];
        $index = 1;
        foreach ($this->sheets as $sheet) {
            $sheetPath = 'xl/worksheets/sheet' . $index . '.xml';
            $sheetXmlParts[$sheetPath] = $this->buildSheetXml($sheet['headers'], $sheet['rows']);
            $workbookSheets[] = '<sheet name="' . self::xmlEscape($sheet['name']) . '" sheetId="' . $index . '" r:id="rId' . $index . '"/>';
            $rels[] = '<Relationship Id="rId' . $index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $index . '.xml"/>';
            $index++;
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($this->sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($workbookSheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml($rels));
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($sheetXmlParts as $path => $xml) {
            $zip->addFromString($path, $xml);
        }

        $zip->close();
        $binary = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function buildSheetXml(array $headers, array $rows): string
    {
        $allRows = array_merge([$headers], $rows);
        $lines = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'];
        $lines[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $lines[] = '<sheetData>';
        $rowNum = 1;
        foreach ($allRows as $row) {
            $lines[] = '<row r="' . $rowNum . '">';
            $col = 0;
            foreach ($row as $cell) {
                $ref = self::cellRef($col, $rowNum);
                $text = self::sanitizeCell((string) $cell);
                $lines[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::xmlEscape($text) . '</t></is></c>';
                $col++;
            }
            $lines[] = '</row>';
            $rowNum++;
        }
        $lines[] = '</sheetData>';
        $lines[] = '</worksheet>';

        return implode('', $lines);
    }

    private static function sanitizeCell(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    private static function cellRef(int $col, int $row): string
    {
        $letters = '';
        $c = $col;
        do {
            $letters = chr(65 + ($c % 26)) . $letters;
            $c = intdiv($c, 26) - 1;
        } while ($c >= 0);

        return $letters . $row;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $parts = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">',
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
        ];
        for ($i = 1; $i <= $sheetCount; $i++) {
            $parts[] = '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $parts[] = '</Types>';

        return implode('', $parts);
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /** @param list<string> $sheetElements */
    private function workbookXml(array $sheetElements): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . implode('', $sheetElements) . '</sheets>'
            . '</workbook>';
    }

    /** @param list<string> $rels */
    private function workbookRelsXml(array $rels): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', $rels)
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>';
    }
}
