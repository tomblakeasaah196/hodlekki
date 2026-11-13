<?php
// /includes/docx.php
//
// A small, dependency-free .docx writer (Office Open XML in a zip, through
// ZipArchive): a title, a line under it and plain paragraphs, which is all
// a downloaded note needs. Hand-rolled on purpose, like includes/exams/xlsx.php:
// the CI test job has the zip extension but no Composer packages, so the
// tests can build a document and read it back. Opens in Word, LibreOffice,
// Google Docs and Pages.
//
//   docx_write(['title' => …, 'subtitle' => …, 'body' => "text\nwith lines", 'author' => …]) -> the file's bytes

const DOCX_NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

/** Text that is safe inside XML: escaped, and without the control characters XML 1.0 forbids. */
function docx_xml_text(string $s): string
{
    $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** One paragraph of $style; tabs stay tabs, spaces are kept as typed. */
function docx_paragraph(string $text, string $style = ''): string
{
    $runs = '';
    foreach (explode("\t", $text) as $i => $part) {
        if ($i > 0) {
            $runs .= '<w:r><w:tab/></w:r>';
        }
        if ($part !== '') {
            $runs .= '<w:r><w:t xml:space="preserve">' . docx_xml_text($part) . '</w:t></w:r>';
        }
    }
    $props = $style !== '' ? '<w:pPr><w:pStyle w:val="' . docx_xml_text($style) . '"/></w:pPr>' : '';
    return '<w:p>' . $props . $runs . '</w:p>';
}

/**
 * Build a document. $doc: title, subtitle (optional), body (plain text; each
 * line is a paragraph, blank lines kept), author (optional, the file's
 * creator). Returns the .docx bytes.
 */
function docx_write(array $doc): string
{
    $title = (string) ($doc['title'] ?? '');
    $body = str_replace(["\r\n", "\r"], "\n", (string) ($doc['body'] ?? ''));
    $paras = docx_paragraph($title, 'Title');
    if (($doc['subtitle'] ?? '') !== '') {
        $paras .= docx_paragraph((string) $doc['subtitle'], 'Subtitle');
    }
    foreach (explode("\n", $body) as $line) {
        $paras .= docx_paragraph($line);
    }

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="' . DOCX_NS_W . '"><w:body>' . $paras
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="' . DOCX_NS_W . '">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>'
        . '<w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
        . '<w:pPr><w:spacing w:after="60"/></w:pPr><w:rPr><w:b/><w:color w:val="1D356A"/><w:sz w:val="40"/><w:szCs w:val="40"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
        . '<w:pPr><w:spacing w:after="360"/></w:pPr><w:rPr><w:color w:val="5B6475"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:style>'
        . '</w:styles>';

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . docx_xml_text($title) . '</dc:title>'
        . '<dc:creator>' . docx_xml_text((string) ($doc['author'] ?? '')) . '</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $parts = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'word/document.xml' => $document,
        'word/styles.xml' => $styles,
        'docProps/core.xml' => $core,
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'hoddocx');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not start the Word file.');
    }
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/** A file name that is safe in a Content-Disposition header and on every OS. */
function docx_filename(string $name): string
{
    $name = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '', $name));
    $name = trim((string) preg_replace('/\s+/', ' ', $name), ' .');
    return ($name !== '' ? mb_substr($name, 0, 80) : 'Notes') . '.docx';
}
