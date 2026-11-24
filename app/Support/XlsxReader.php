<?php

namespace App\Support;

use App\Exceptions\ScheduleImportException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal dependency-free reader for the first visible worksheet of an .xlsx (Office Open XML) workbook.
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
     * Read every row of the first visible worksheet as a dense list of cell strings (empty cells become null).
     *
     * @return list<list<string|null>>
     *
     * @throws ScheduleImportException
     */
    public function rows(string $path): array
    {
        return $this->read($path)->rows;
    }

    /**
     * Read the first visible worksheet, including which of its cells are bold or italic and their fill colours.
     *
     * @throws ScheduleImportException
     */
    public function read(string $path): Worksheet
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ScheduleImportException('File bukan workbook .xlsx yang valid.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $cellStyles = $this->cellStyles($zip);
            $sheet = $this->loadXml($zip, $this->firstVisibleSheetPath($zip));

            return $this->worksheet($sheet, $sharedStrings, $cellStyles);
        } finally {
            $zip->close();
        }
    }

    /**
     * Resolve the archive path of the first worksheet that is not hidden, falling back to the first one declared.
     *
     * @throws ScheduleImportException
     */
    private function firstVisibleSheetPath(ZipArchive $zip): string
    {
        $workbook = $this->loadXml($zip, 'xl/workbook.xml');
        $sheets = $workbook->children(self::MAIN_NAMESPACE)->sheets->children(self::MAIN_NAMESPACE);

        if (count($sheets) === 0) {
            throw new ScheduleImportException('Workbook tidak memiliki sheet.');
        }

        $selected = $sheets[0];

        foreach ($sheets as $sheet) {
            if (! in_array((string) $sheet->attributes()['state'], ['hidden', 'veryHidden'], true)) {
                $selected = $sheet;

                break;
            }
        }

        $relationshipId = (string) $selected->attributes(self::RELATIONSHIP_NAMESPACE)['id'];

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
     * Load the bold/italic emphasis and solid RGB fill colour of every cell style (cellXfs entry), indexed by style number.
     *
     * @return list<array{bold: bool, italic: bool, fill: string|null}>
     *
     * @throws ScheduleImportException
     */
    private function cellStyles(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/styles.xml') === false) {
            return [];
        }

        $styles = $this->loadXml($zip, 'xl/styles.xml')->children(self::MAIN_NAMESPACE);

        if (! isset($styles->cellXfs)) {
            return [];
        }

        $fonts = [];

        foreach (isset($styles->fonts) ? $styles->fonts->children(self::MAIN_NAMESPACE)->font : [] as $font) {
            $properties = $font->children(self::MAIN_NAMESPACE);
            $fonts[] = ['bold' => $this->isEnabled($properties, 'b'), 'italic' => $this->isEnabled($properties, 'i')];
        }

        $fills = [];

        foreach (isset($styles->fills) ? $styles->fills->children(self::MAIN_NAMESPACE)->fill : [] as $fill) {
            $pattern = $fill->children(self::MAIN_NAMESPACE)->patternFill;
            $isSolid = isset($pattern) && (string) $pattern->attributes()['patternType'] === 'solid';
            $color = $isSolid && isset($pattern->children(self::MAIN_NAMESPACE)->fgColor)
                ? (string) $pattern->children(self::MAIN_NAMESPACE)->fgColor->attributes()['rgb']
                : '';
            $fills[] = $color !== '' ? strtoupper($color) : null;
        }

        $cellStyles = [];

        foreach ($styles->cellXfs->children(self::MAIN_NAMESPACE)->xf as $format) {
            $cellStyles[] = [
                ...$fonts[(int) $format->attributes()['fontId']] ?? ['bold' => false, 'italic' => false],
                'fill' => $fills[(int) $format->attributes()['fillId']] ?? null,
            ];
        }

        return $cellStyles;
    }

    /**
     * Determine whether a boolean font property such as <b/> is present and not switched off with val="0".
     */
    private function isEnabled(SimpleXMLElement $properties, string $name): bool
    {
        if (! isset($properties->{$name})) {
            return false;
        }

        return ! in_array((string) $properties->{$name}->attributes()['val'], ['0', 'false'], true);
    }

    /**
     * Convert worksheet XML into dense rows, the emphasis of the non-empty bold or italic cells, and the fill colour of
     * every filled cell (empty ones included, since a legend's colour swatches are blank cells).
     *
     * @param  list<string>  $sharedStrings
     * @param  list<array{bold: bool, italic: bool, fill: string|null}>  $cellStyles
     */
    private function worksheet(SimpleXMLElement $sheet, array $sharedStrings, array $cellStyles): Worksheet
    {
        $rows = [];
        $fonts = [];
        $fills = [];
        $sheetData = $sheet->children(self::MAIN_NAMESPACE)->sheetData;

        foreach ($sheetData->children(self::MAIN_NAMESPACE)->row as $row) {
            $cells = [];
            $nextColumn = 0;
            $rowNumber = (int) $row->attributes()['r'];
            $index = $rowNumber > 0 ? $rowNumber - 1 : count($rows);

            foreach ($row->children(self::MAIN_NAMESPACE)->c as $cell) {
                $reference = (string) $cell->attributes()['r'];
                $column = $reference !== '' ? $this->columnIndex($reference) : $nextColumn;
                $cells[$column] = $this->cellValue($cell, $sharedStrings);
                $nextColumn = $column + 1;

                $style = $cellStyles[(int) $cell->attributes()['s']] ?? null;

                if ($style === null) {
                    continue;
                }

                if ($cells[$column] !== null && ($style['bold'] || $style['italic'])) {
                    $fonts[$index][$column] = ['bold' => $style['bold'], 'italic' => $style['italic']];
                }

                if ($style['fill'] !== null) {
                    $fills[$index][$column] = $style['fill'];
                }
            }

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

        return new Worksheet($rows, $fonts, $fills);
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
