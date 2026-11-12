<?php
// /includes/exams/xlsx.php
//
// A small, dependency-free .xlsx writer and reader (Office Open XML in a zip,
// through ZipArchive): enough for the question template and for reading the
// sheets tutors fill in from Excel, LibreOffice or Google Sheets. Hand-rolled
// on purpose: the CI test job has the zip extension but no Composer
// packages, so the tests can write the template and read it back.
//
//   xlsx_write($sheets)            -> the file's bytes
//   xlsx_read($path, $sheetName)   -> ['ok', 'rows' => [rowNumber => [col => text]], 'sheet', 'error']
//
// The reader trusts nothing in the file: a size cap on every part before it
// is inflated (zip bombs), no network or external entities while parsing
// XML, at most XLSX_MAX_ROWS rows and XLSX_MAX_COLS columns, and every value
// comes back as a plain string.

const XLSX_MAX_PART_BYTES = 20 * 1024 * 1024;  // one inflated part
const XLSX_MAX_ROWS       = 2000;
const XLSX_MAX_COLS       = 30;
const XLSX_NS_MAIN        = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSX_NS_REL         = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const XLSX_NS_REL_STRICT  = 'http://purl.oclc.org/ooxml/officeDocument/relationships';

/** "A" for 0, "B" for 1 … "AA" for 26. */
function xlsx_col_letter(int $index): string
{
    $s = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

/** 0 for "A", 26 for "AA"; -1 when it is not a column. */
function xlsx_col_index(string $letters): int
{
    if (!preg_match('/^[A-Z]{1,3}$/', $letters)) {
        return -1;
    }
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** Text that is safe inside XML: escaped, and without the control characters XML 1.0 forbids. */
function xlsx_xml_text(string $s): string
{
    $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/**
 * Build a workbook. Each sheet:
 *   name     (31 characters at most)
 *   rows     list of rows, each a list of cells: a string, an int, or
 *            ['v' => value, 's' => style] with style 'head' | 'note' | 'example' | 'wrap'
 *   widths   column widths (characters), optional
 *   freeze   true: keep the first row in view
 *   lists    data validations: [['range' => 'A2:A501', 'values' => [...], 'prompt' => '…']]
 *   numbers  whole-number validations: [['range' => 'I2:I501', 'min' => 1, 'max' => 100, 'prompt' => '…']]
 *   hidden   true: a hidden sheet
 *
 * @param list<array> $sheets
 */
function xlsx_write(array $sheets): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'hodxlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('xlsx_write: cannot create the file');
    }
    $sheetEntries = $relEntries = $overrides = '';
    foreach (array_values($sheets) as $i => $sheet) {
        $n = $i + 1;
        $name = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', (string) $sheet['name']), 0, 31);
        $sheetEntries .= '<sheet name="' . xlsx_xml_text($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"'
            . (!empty($sheet['hidden']) ? ' state="hidden"' : '') . '/>';
        $relEntries .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', xlsx_sheet_xml($sheet));
    }
    $count = count($sheets);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $overrides . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . XLSX_NS_MAIN . '" xmlns:r="' . XLSX_NS_REL . '">'
        . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheetEntries . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relEntries
        . '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/styles.xml', xlsx_styles_xml());
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/** Style ids in xl/styles.xml (cellXfs order). */
const XLSX_STYLES = ['plain' => 0, 'head' => 1, 'note' => 2, 'example' => 3, 'wrap' => 4];

function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="' . XLSX_NS_MAIN . '">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF4B5563"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="11"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D356A"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function xlsx_sheet_xml(array $sheet): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . XLSX_NS_MAIN . '" xmlns:r="' . XLSX_NS_REL . '">';
    $xml .= '<sheetViews><sheetView workbookViewId="0">'
        . (!empty($sheet['freeze']) ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '')
        . '</sheetView></sheetViews>';
    if (!empty($sheet['widths'])) {
        $xml .= '<cols>';
        foreach (array_values($sheet['widths']) as $i => $w) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }
    $xml .= '<sheetData>';
    foreach (array_values($sheet['rows'] ?? []) as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $cell) {
            $value = is_array($cell) ? ($cell['v'] ?? '') : $cell;
            $style = is_array($cell) ? (XLSX_STYLES[$cell['s'] ?? 'plain'] ?? 0) : 0;
            $ref = xlsx_col_letter($c) . ($r + 1);
            $s = $style ? ' s="' . $style . '"' : '';
            if (is_int($value)) {
                $xml .= '<c r="' . $ref . '"' . $s . '><v>' . $value . '</v></c>';
            } elseif ((string) $value !== '') {
                $xml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml_text((string) $value) . '</t></is></c>';
            } elseif ($style) {
                $xml .= '<c r="' . $ref . '"' . $s . '/>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    $validations = [];
    foreach ($sheet['lists'] ?? [] as $v) {
        // An inline list: "One answer,Several answers,True/False" (255 characters at most).
        $formula = '"' . str_replace('"', '', implode(',', $v['values'])) . '"';
        $validations[] = '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' errorTitle="Choose from the list" error="Pick one of the choices in the list." promptTitle="' . xlsx_xml_text($v['title'] ?? '') . '"'
            . ' prompt="' . xlsx_xml_text($v['prompt'] ?? '') . '" sqref="' . xlsx_xml_text($v['range']) . '"><formula1>'
            . xlsx_xml_text($formula) . '</formula1></dataValidation>';
    }
    foreach ($sheet['numbers'] ?? [] as $v) {
        $validations[] = '<dataValidation type="whole" operator="between" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
            . ' errorTitle="A whole number" error="' . xlsx_xml_text('Use a whole number from ' . $v['min'] . ' to ' . $v['max'] . '.') . '"'
            . ' promptTitle="' . xlsx_xml_text($v['title'] ?? '') . '" prompt="' . xlsx_xml_text($v['prompt'] ?? '') . '" sqref="' . xlsx_xml_text($v['range']) . '">'
            . '<formula1>' . (int) $v['min'] . '</formula1><formula2>' . (int) $v['max'] . '</formula2></dataValidation>';
    }
    if ($validations !== []) {
        $xml .= '<dataValidations count="' . count($validations) . '">' . implode('', $validations) . '</dataValidations>';
    }
    return $xml . '</worksheet>';
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/** Parse one part of the package as XML, refusing anything oversized. */
function xlsx_part(ZipArchive $zip, string $name): ?SimpleXMLElement
{
    $stat = $zip->statName($name);
    if ($stat === false) {
        return null;
    }
    if ((int) $stat['size'] > XLSX_MAX_PART_BYTES) {
        throw new RuntimeException('too big');
    }
    $raw = $zip->getFromName($name);
    if ($raw === false || strlen($raw) > XLSX_MAX_PART_BYTES) {
        throw new RuntimeException('too big');
    }
    if (preg_match('/<!DOCTYPE/i', substr($raw, 0, 2048))) {
        throw new RuntimeException('doctype');   // spreadsheets never need one; entity tricks do
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $xml === false ? null : $xml;
}

/** The text of a <si> or <is>: plain <t>, or rich-text runs <r><t>. */
function xlsx_string_item(SimpleXMLElement $node): string
{
    $kids = $node->children(XLSX_NS_MAIN);
    if (count($kids) === 0) {
        $kids = $node->children();
    }
    $out = '';
    foreach ($kids as $name => $child) {
        if ($name === 't') {
            $out .= (string) $child;
        } elseif ($name === 'r') {
            $run = $child->children(XLSX_NS_MAIN);
            $out .= (string) (count($run) ? $run->t : $child->t);
        }
    }
    return $out;
}

/**
 * Read one sheet of an .xlsx file. $sheetName: the sheet to read (case does
 * not matter); when missing, the first sheet.
 *
 * @return array{ok:bool, rows:array<int,array<int,string>>, sheet:string, error:?string, truncated:bool}
 */
function xlsx_read(string $path, ?string $sheetName = null): array
{
    $fail = static fn (string $why): array => ['ok' => false, 'rows' => [], 'sheet' => '', 'error' => $why, 'truncated' => false];
    if (!class_exists('ZipArchive')) {
        return $fail('This server cannot read Excel files (the zip extension is missing).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        return $fail('This is not an Excel (.xlsx) file. Save it as "Excel Workbook" and try again.');
    }
    try {
        $book = xlsx_part($zip, 'xl/workbook.xml');
        $rels = xlsx_part($zip, 'xl/_rels/workbook.xml.rels');
        if ($book === null || $rels === null) {
            return $fail('This is not an Excel (.xlsx) file. Save it as "Excel Workbook" and try again.');
        }
        $targets = [];
        foreach ($rels->children() as $rel) {
            $targets[(string) $rel['Id']] = (string) $rel['Target'];
        }
        $chosen = null;
        $first = null;
        $sheetsNode = $book->children(XLSX_NS_MAIN)->sheets ?? null;
        $sheetsNode = ($sheetsNode !== null && count($sheetsNode)) ? $sheetsNode : $book->sheets;
        foreach ($sheetsNode->children() as $sheet) {
            $attrs = $sheet->attributes(XLSX_NS_REL);
            $rid = (string) ($attrs['id'] ?? '');
            if ($rid === '') {
                $rid = (string) ($sheet->attributes(XLSX_NS_REL_STRICT)['id'] ?? '');
            }
            $entry = ['name' => (string) $sheet['name'], 'target' => $targets[$rid] ?? ''];
            $first ??= $entry;
            if ($sheetName !== null && strcasecmp(trim($entry['name']), $sheetName) === 0) {
                $chosen = $entry;
            }
        }
        $chosen ??= $first;
        if ($chosen === null || $chosen['target'] === '') {
            return $fail('The file has no sheets.');
        }
        $target = ltrim($chosen['target'], '/');
        $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        $target = (string) preg_replace('#/(?:\./)+#', '/', $target);
        if (str_contains($target, '..')) {
            return $fail('The file could not be read.');
        }

        $shared = [];
        $sst = xlsx_part($zip, 'xl/sharedStrings.xml');
        if ($sst !== null) {
            foreach ($sst->children() as $si) {
                $shared[] = xlsx_string_item($si);
            }
        }
        $sheetXml = xlsx_part($zip, $target);
        if ($sheetXml === null) {
            return $fail('The sheet could not be read.');
        }
        $rows = [];
        $truncated = false;
        $data = $sheetXml->children(XLSX_NS_MAIN)->sheetData ?? null;
        $data = ($data !== null && count($data)) ? $data : $sheetXml->sheetData;
        foreach ($data->children() as $row) {
            $r = (int) $row['r'];
            if ($r <= 0) {
                $r = ($rows === [] ? 0 : max(array_keys($rows))) + 1;
            }
            if (count($rows) >= XLSX_MAX_ROWS) {
                $truncated = true;
                break;
            }
            $cells = [];
            $next = 0;
            foreach ($row->children() as $c) {
                $ref = (string) $c['r'];
                $col = $ref !== '' && preg_match('/^([A-Z]{1,3})\d+$/', $ref, $m) ? xlsx_col_index($m[1]) : $next;
                $next = $col + 1;
                if ($col < 0 || $col >= XLSX_MAX_COLS) {
                    continue;
                }
                $t = (string) $c['t'];
                $kids = $c->children(XLSX_NS_MAIN);
                $kids = count($kids) ? $kids : $c->children();
                $v = isset($kids->v) ? (string) $kids->v : '';
                $value = match ($t) {
                    's' => $shared[(int) $v] ?? '',
                    'inlineStr' => isset($kids->is) ? xlsx_string_item($kids->is) : '',
                    'b' => $v === '1' ? 'TRUE' : 'FALSE',
                    'e' => '',
                    'str' => $v,
                    default => xlsx_number_text($v),
                };
                if ($value !== '') {
                    $cells[$col] = $value;
                }
            }
            if ($cells !== []) {
                ksort($cells);
                $rows[$r] = $cells;
            }
        }
        return ['ok' => true, 'rows' => $rows, 'sheet' => $chosen['name'], 'error' => null, 'truncated' => $truncated];
    } catch (Throwable $e) {
        return $fail('The file could not be read. Save it again as "Excel Workbook" (.xlsx) and try again.');
    } finally {
        $zip->close();
    }
}

/** "2" for "2", "2" for "2.0", "0.5" for "0.5": numbers as a person typed them. */
function xlsx_number_text(string $v): string
{
    if ($v === '' || !is_numeric($v)) {
        return $v;
    }
    $f = (float) $v;
    return floor($f) === $f && abs($f) < 1e15 ? (string) (int) $f : rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
}
