<?php
// /includes/trainings/pptx.php
//
// PowerPoint decks in Trainings (owner, 12 Nov 2026: real trainings arrive as
// .pptx, one deck a day). Two jobs, through ZipArchive, trusting nothing in
// the file (the same hardening as includes/exams/xlsx.php):
//
//   trainings_pptx_check($path)  is it really a PowerPoint presentation? The
//                                zip magic (PK\x03\x04) AND [Content_Types].xml
//                                declaring the presentationml main part. A
//                                renamed .exe, a .docx, a macro-enabled .pptm
//                                or a slideshow/template are refused.
//   trainings_pptx_text($path)   the slides' text, in slide order (the order of
//                                ppt/presentation.xml's slide list through its
//                                relationships, else slideN by N), for search
//                                and for PR 4b's AI. Kept in the item's details
//                                (slides_text) and never sent to a browser.
//
// Every part is size-checked before it is inflated (zip bombs), a DOCTYPE is
// refused (entity tricks), XML is parsed with LIBXML_NONET, and there are caps
// on slides and characters.

const TRAININGS_PPTX_MAX_PART_BYTES = 5 * 1024 * 1024;   // one inflated XML part
const TRAININGS_PPTX_MAX_SLIDES = 500;
const TRAININGS_PPTX_MAX_CHARS = 100000;
const TRAININGS_PPTX_MAIN_TYPE = 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml';
const TRAININGS_PPTX_NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
const TRAININGS_PPTX_NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';
const TRAININGS_PPTX_NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const TRAININGS_PPTX_NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

/** One XML part of the zip, size-checked and parsed safely; null when absent or unreadable. */
function trainings_pptx_part(ZipArchive $zip, string $name): ?SimpleXMLElement
{
    $stat = $zip->statName($name);
    if ($stat === false || (int) $stat['size'] > TRAININGS_PPTX_MAX_PART_BYTES) {
        return null;
    }
    $raw = $zip->getFromName($name);
    if ($raw === false || strlen($raw) > TRAININGS_PPTX_MAX_PART_BYTES || preg_match('/<!DOCTYPE/i', substr($raw, 0, 2048))) {
        return null;   // presentations never need a DOCTYPE; entity tricks do
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $xml === false ? null : $xml;
}

/** Open a file as a PowerPoint presentation, or null when it is not one. */
function trainings_pptx_open(string $path): ?ZipArchive
{
    if (!class_exists('ZipArchive') || !is_file($path)) {
        return null;
    }
    $h = @fopen($path, 'rb');
    if ($h === false) {
        return null;
    }
    $magic = (string) fread($h, 4);
    fclose($h);
    if ($magic !== "PK\x03\x04") {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        return null;
    }
    $types = trainings_pptx_part($zip, '[Content_Types].xml');
    $isDeck = false;
    if ($types !== null) {
        foreach ($types->children() as $node) {
            if ($node->getName() === 'Override' && (string) $node->attributes()['ContentType'] === TRAININGS_PPTX_MAIN_TYPE) {
                $isDeck = true;
                break;
            }
        }
    }
    if (!$isDeck) {
        $zip->close();
        return null;
    }
    return $zip;
}

function trainings_pptx_check(string $path): bool
{
    $zip = trainings_pptx_open($path);
    if ($zip === null) {
        return false;
    }
    $zip->close();
    return true;
}

/**
 * The slide parts in slide order: ppt/presentation.xml's p:sldIdLst through
 * ppt/_rels/presentation.xml.rels; else every ppt/slides/slideN.xml by N.
 *
 * @return list<string> part names inside the zip
 */
function trainings_pptx_slide_parts(ZipArchive $zip): array
{
    $rels = [];
    $relXml = trainings_pptx_part($zip, 'ppt/_rels/presentation.xml.rels');
    if ($relXml !== null) {
        foreach ($relXml->children(TRAININGS_PPTX_NS_PKG_REL) as $rel) {
            // attributes() with no namespace: after children($ns), $rel['Id'] would read the wrong namespace.
            $at = $rel->attributes();
            $target = (string) $at['Target'];
            if (str_ends_with((string) $at['Type'], '/slide') && $target !== '' && !str_contains($target, '..')) {
                $rels[(string) $at['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'ppt/' . $target;
            }
        }
    }
    $parts = [];
    $pres = trainings_pptx_part($zip, 'ppt/presentation.xml');
    if ($pres !== null && $rels !== []) {
        $list = $pres->children(TRAININGS_PPTX_NS_P)->sldIdLst;
        if ($list !== null) {
            foreach ($list->children(TRAININGS_PPTX_NS_P) as $sld) {
                $rid = (string) $sld->attributes(TRAININGS_PPTX_NS_R)['id'];
                if (isset($rels[$rid]) && $zip->statName($rels[$rid]) !== false) {
                    $parts[] = $rels[$rid];
                }
            }
        }
    }
    if ($parts === []) {
        $byNumber = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $byNumber[(int) $m[1]] = $name;
            }
        }
        ksort($byNumber);
        $parts = array_values($byNumber);
    }
    return array_slice(array_values(array_unique($parts)), 0, TRAININGS_PPTX_MAX_SLIDES);
}

/**
 * The text of every slide, in order: one block per slide ("Slide 3" then its
 * paragraphs), at most TRAININGS_PPTX_MAX_CHARS characters.
 *
 * @return array{ok:bool, text:string, slides:int, truncated:bool}
 */
function trainings_pptx_text(string $path): array
{
    $out = ['ok' => false, 'text' => '', 'slides' => 0, 'truncated' => false];
    $zip = trainings_pptx_open($path);
    if ($zip === null) {
        return $out;
    }
    $blocks = [];
    $chars = 0;
    $parts = trainings_pptx_slide_parts($zip);
    foreach ($parts as $n => $part) {
        $xml = trainings_pptx_part($zip, $part);
        $lines = [];
        if ($xml !== null) {
            $xml->registerXPathNamespace('a', TRAININGS_PPTX_NS_A);
            foreach ($xml->xpath('//a:p') ?: [] as $p) {
                $p->registerXPathNamespace('a', TRAININGS_PPTX_NS_A);
                $line = '';
                foreach ($p->xpath('.//a:t') ?: [] as $t) {
                    $line .= (string) $t;
                }
                $line = trim((string) preg_replace('/\s+/u', ' ', $line));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }
        $block = 'Slide ' . ($n + 1) . ($lines !== [] ? "\n" . implode("\n", $lines) : '');
        if ($chars + mb_strlen($block, 'UTF-8') > TRAININGS_PPTX_MAX_CHARS) {
            $blocks[] = mb_substr($block, 0, max(0, TRAININGS_PPTX_MAX_CHARS - $chars), 'UTF-8');
            $out['truncated'] = true;
            break;
        }
        $blocks[] = $block;
        $chars += mb_strlen($block, 'UTF-8') + 2;
    }
    $zip->close();
    $out['ok'] = true;
    $out['slides'] = count($parts);
    $out['text'] = mb_substr(implode("\n\n", $blocks), 0, TRAININGS_PPTX_MAX_CHARS, 'UTF-8');
    return $out;
}

/** "Day_1.Workers_Training.pptx" → "Day 1 Workers Training" (an item's name from its file). */
function trainings_title_from_file(string $fileName): string
{
    $base = (string) preg_replace('/\.pptx$/i', '', basename(str_replace('\\', '/', $fileName)));
    $base = (string) preg_replace('/[_.]+/', ' ', $base);
    $base = trainings_text($base, 160);
    return $base !== '' ? $base : 'Slides';
}
