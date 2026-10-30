<?php

namespace App\Support;

use App\Exceptions\ScheduleImportException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal dependency-free reader for the first worksheet of an .xlsx (Office Open XML) workbook.
 */
class XlsxReader
{
    private const string MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const string PACKAGE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Upper bound for an uncompressed XML part, protecting against zip bombs.
     */
    private const int MAX_PART_BYTES = 50 * 1024 * 1024;

    /**
     * Read every row of the first worksheet as a dense list of cell strings (empty cells become null).
     *
     * @return list<list<string|null>>
     *
     * @throws ScheduleImportException
     */
    public function rows(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ScheduleImportException('File bukan workbook .xlsx yang valid.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheet = $this->loadXml($zip, $this->firstSheetPath($zip));

            return $this->sheetRows($sheet, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * Resolve the archive path of the first worksheet declared in the workbook.
     *
     * @throws ScheduleImportException
     */
    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $this->loadXml($zip, 'xl/workbook.xml');
        $sheets = $workbook->children(self::MAIN_NAMESPACE)->sheets->children(self::MAIN_NAMESPACE);

        if (count($sheets) === 0) {
            throw new ScheduleImportException('Workbook tidak memiliki sheet.');
        }

        $relationshipId = (string) $sheets[0]->attributes(self::RELATIONSHIP_NAMESPACE)['id'];

        if ($zip->locateName('xl/_rels/workbook.xml.rels') !== false) {
            $relationships = $this->loadXml($zip, 'xl/_rels/workbook.xml.rels');

            foreach ($relationships->children(self::PACKAGE_RELATIONSHIP_NAMESPACE)->Relationship as $relationship) {
                if ((string) $relationship->attributes()['Id'] === $relationshipId) {
                    $target = (string) $relationship->attributes()['Target'];

                    return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * Load the shared string table used by string cells.
     *
     * @return list<string>
     *
     * @throws ScheduleImportException
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];

        foreach ($this->loadXml($zip, 'xl/sharedStrings.xml')->children(self::MAIN_NAMESPACE)->si as $item) {
            $strings[] = $this->richText($item);
        }

        return $strings;
    }

    /**
     * Convert worksheet XML into dense rows.
     *
     * @param  list<string>  $sharedStrings
     * @return list<list<string|null>>
     */
    private function sheetRows(SimpleXMLElement $sheet, array $sharedStrings): array
    {
        $rows = [];
        $sheetData = $sheet->children(self::MAIN_NAMESPACE)->sheetData;

        foreach ($sheetData->children(self::MAIN_NAMESPACE)->row as $row) {
            $cells = [];
            $nextColumn = 0;

            foreach ($row->children(self::MAIN_NAMESPACE)->c as $cell) {
                $reference = (string) $cell->attributes()['r'];
                $column = $reference !== '' ? $this->columnIndex($reference) : $nextColumn;
                $cells[$column] = $this->cellValue($cell, $sharedStrings);
                $nextColumn = $column + 1;
            }

            $rowNumber = (int) $row->attributes()['r'];
            $index = $rowNumber > 0 ? $rowNumber - 1 : count($rows);

            while (count($rows) < $index) {
                $rows[] = [];
            }

            $dense = [];

            if ($cells !== []) {
                for ($column = 0, $last = max(array_keys($cells)); $column <= $last; $column++) {
                    $dense[] = $cells[$column] ?? null;
                }
            }

            $rows[] = $dense;
        }

        return $rows;
    }

    /**
     * Read the textual value of a single cell.
     *
     * @param  list<string>  $sharedStrings
     */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): ?string
    {
        $children = $cell->children(self::MAIN_NAMESPACE);
        $type = (string) $cell->attributes()['t'];

        $value = match ($type) {
            's' => $sharedStrings[(int) $children->v] ?? null,
            'inlineStr' => $this->richText($children->is),
            'b' => ((string) $children->v) === '1' ? 'TRUE' : 'FALSE',
            default => isset($children->v) ? (string) $children->v : null,
        };

        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Concatenate the text runs of a string item, ignoring phonetic hints.
     */
    private function richText(SimpleXMLElement $item): string
    {
        $children = $item->children(self::MAIN_NAMESPACE);

        if (isset($children->t)) {
            return (string) $children->t;
        }

        $text = '';

        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN_NAMESPACE)->t;
        }

        return $text;
    }

    /**
     * Convert a cell reference such as "AB12" into a zero-based column index.
     */
    private function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max($index - 1, 0);
    }

    /**
     * Load and parse an XML part from the archive.
     *
     * @throws ScheduleImportException
     */
    private function loadXml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            throw new ScheduleImportException("Bagian workbook '{$name}' tidak ditemukan.");
        }

        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new ScheduleImportException('Ukuran sheet terlalu besar untuk diimpor.');
        }

        $contents = $zip->getFromName($name);
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $xml = $contents === false ? false : simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if ($xml === false) {
            throw new ScheduleImportException("Bagian workbook '{$name}' rusak atau tidak dapat dibaca.");
        }

        return $xml;
    }
}
