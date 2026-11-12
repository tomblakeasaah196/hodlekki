<?php
// /includes/qr.php
//
// QR codes on the server, with no Composer package: a byte-mode, error
// correction level M encoder for versions 1-10 (up to 213 bytes, plenty for
// a verify link), and a small PNG writer (zlib + CRC32, no GD). Used by the
// Trainings certificates (includes/trainings/certificates.php).
//
// Ported from qrcode-generator 1.5.0 by Kazuhiko Arase (MIT), the same
// library the pages already use in the browser
// (assets/ui/vendor/qrcode-generator-1.5.0/qrcode.mjs): the same tables, the
// same Reed-Solomon arithmetic, the same mask choice. tests/trainings/run.php
// pins its output; checked module for module against the JS library.
//
//   hod_qr_matrix('https://…')        -> list<list<bool>> (true = dark)
//   hod_qr_png($matrix, 8, 4)         -> PNG bytes (8 px per module, 4-module quiet zone)

// [count, totalCodewords, dataCodewords, (count, total, data)] per version, level M.
const HOD_QR_RS_M = [
    1 => [1, 26, 16], 2 => [1, 44, 28], 3 => [1, 70, 44], 4 => [2, 50, 32], 5 => [2, 67, 43],
    6 => [4, 43, 27], 7 => [4, 49, 31], 8 => [2, 60, 38, 2, 61, 39], 9 => [3, 58, 36, 2, 59, 37],
    10 => [4, 69, 43, 1, 70, 44],
];
const HOD_QR_ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
    7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
const HOD_QR_LEVEL_M = 0;   // the format-information bits for level M

/** GF(256) exp/log tables (x^8 + x^4 + x^3 + x^2 + 1). @return array{0:list<int>,1:array<int,int>} */
function hod_qr_gf(): array
{
    static $tables = null;
    if ($tables === null) {
        $exp = [];
        for ($i = 0; $i < 8; $i++) {
            $exp[$i] = 1 << $i;
        }
        for ($i = 8; $i < 256; $i++) {
            $exp[$i] = $exp[$i - 4] ^ $exp[$i - 5] ^ $exp[$i - 6] ^ $exp[$i - 8];
        }
        $log = [];
        for ($i = 0; $i < 255; $i++) {
            $log[$exp[$i]] = $i;
        }
        $tables = [$exp, $log];
    }
    return $tables;
}

function hod_qr_gexp(int $n): int
{
    while ($n < 0) {
        $n += 255;
    }
    while ($n >= 256) {
        $n -= 255;
    }
    return hod_qr_gf()[0][$n];
}

/** A polynomial with its leading zeros dropped and $shift zeros appended. */
function hod_qr_poly(array $num, int $shift = 0): array
{
    $offset = 0;
    while ($offset < count($num) && $num[$offset] === 0) {
        $offset++;
    }
    $out = array_slice($num, $offset);
    for ($i = 0; $i < $shift; $i++) {
        $out[] = 0;
    }
    return $out;
}

function hod_qr_poly_multiply(array $a, array $b): array
{
    $log = hod_qr_gf()[1];
    $num = array_fill(0, count($a) + count($b) - 1, 0);
    foreach ($a as $i => $ai) {
        foreach ($b as $j => $bj) {
            $num[$i + $j] ^= hod_qr_gexp($log[$ai] + $log[$bj]);
        }
    }
    return hod_qr_poly($num);
}

function hod_qr_poly_mod(array $a, array $e): array
{
    $log = hod_qr_gf()[1];
    while (count($a) - count($e) >= 0) {
        $ratio = $log[$a[0]] - $log[$e[0]];
        foreach ($e as $i => $ei) {
            $a[$i] ^= hod_qr_gexp($log[$ei] + $ratio);
        }
        $a = hod_qr_poly($a);
        if ($a === []) {
            break;
        }
    }
    return $a;
}

/** The data and error-correction codewords, interleaved, for a version. */
function hod_qr_codewords(string $bytes, int $version): array
{
    $row = HOD_QR_RS_M[$version];
    $blocks = [];
    for ($k = 0; $k < count($row); $k += 3) {
        for ($n = 0; $n < $row[$k]; $n++) {
            $blocks[] = [$row[$k + 1], $row[$k + 2]];
        }
    }
    $totalData = array_sum(array_column($blocks, 1));

    // Bits: mode 0100 (byte), the length (8 bits up to version 9, 16 from 10), the bytes.
    $bits = '0100' . str_pad(decbin(strlen($bytes)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < strlen($bytes); $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    if (strlen($bits) > $totalData * 8) {
        throw new LengthException('QR data too long for version ' . $version);
    }
    if (strlen($bits) + 4 <= $totalData * 8) {
        $bits .= '0000';
    }
    while (strlen($bits) % 8 !== 0) {
        $bits .= '0';
    }
    $data = array_map('bindec', str_split($bits, 8));
    for ($pad = 0; count($data) < $totalData; $pad ^= 1) {
        $data[] = $pad === 0 ? 0xEC : 0x11;
    }

    $dc = [];
    $ec = [];
    $offset = 0;
    foreach ($blocks as $r => [$total, $dataCount]) {
        $dc[$r] = array_slice($data, $offset, $dataCount);
        $offset += $dataCount;
        $ecCount = $total - $dataCount;
        $rs = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $rs = hod_qr_poly_multiply($rs, [1, hod_qr_gexp($i)]);
        }
        $mod = hod_qr_poly_mod(hod_qr_poly($dc[$r], count($rs) - 1), $rs);
        $ec[$r] = [];
        for ($i = 0; $i < count($rs) - 1; $i++) {
            $idx = $i + count($mod) - (count($rs) - 1);
            $ec[$r][] = $idx >= 0 ? $mod[$idx] : 0;
        }
    }
    $out = [];
    for ($i = 0, $max = max(array_map('count', $dc)); $i < $max; $i++) {
        foreach ($dc as $block) {
            if ($i < count($block)) {
                $out[] = $block[$i];
            }
        }
    }
    for ($i = 0, $max = max(array_map('count', $ec)); $i < $max; $i++) {
        foreach ($ec as $block) {
            if ($i < count($block)) {
                $out[] = $block[$i];
            }
        }
    }
    return $out;
}

function hod_qr_bch_digit(int $d): int
{
    $n = 0;
    while ($d !== 0) {
        $n++;
        $d >>= 1;
    }
    return $n;
}

function hod_qr_mask(int $pattern, int $i, int $j): bool
{
    return match ($pattern) {
        0 => ($i + $j) % 2 === 0,
        1 => $i % 2 === 0,
        2 => $j % 3 === 0,
        3 => ($i + $j) % 3 === 0,
        4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
        5 => ($i * $j) % 2 + ($i * $j) % 3 === 0,
        6 => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
        default => (($i * $j) % 3 + ($i + $j) % 2) % 2 === 0,
    };
}

/** One symbol for a version, mask and codewords ($test: format bits left light, as the mask search does). */
function hod_qr_build(int $version, int $mask, array $codewords, bool $test): array
{
    $n = $version * 4 + 17;
    $m = array_fill(0, $n, array_fill(0, $n, null));
    foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$row, $col]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                if ($row + $r < 0 || $row + $r >= $n || $col + $c < 0 || $col + $c >= $n) {
                    continue;
                }
                $m[$row + $r][$col + $c] = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6)) || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
            }
        }
    }
    $pos = HOD_QR_ALIGN[$version];
    foreach ($pos as $row) {
        foreach ($pos as $col) {
            if ($m[$row][$col] !== null) {
                continue;
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $m[$row + $r][$col + $c] = $r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0);
                }
            }
        }
    }
    for ($i = 8; $i < $n - 8; $i++) {
        $m[$i][6] ??= $i % 2 === 0;
        $m[6][$i] ??= $i % 2 === 0;
    }
    // Format information: level and mask, BCH(15,5), masked.
    $data = (HOD_QR_LEVEL_M << 3) | $mask;
    $g15 = 0b10100110111;
    $d = $data << 10;
    while (hod_qr_bch_digit($d) - hod_qr_bch_digit($g15) >= 0) {
        $d ^= $g15 << (hod_qr_bch_digit($d) - hod_qr_bch_digit($g15));
    }
    $bits = (($data << 10) | $d) ^ 0b101010000010010;
    for ($i = 0; $i < 15; $i++) {
        $on = !$test && (($bits >> $i) & 1) === 1;
        if ($i < 6) {
            $m[$i][8] = $on;
        } elseif ($i < 8) {
            $m[$i + 1][8] = $on;
        } else {
            $m[$n - 15 + $i][8] = $on;
        }
        if ($i < 8) {
            $m[8][$n - $i - 1] = $on;
        } elseif ($i < 9) {
            $m[8][15 - $i] = $on;
        } else {
            $m[8][14 - $i] = $on;
        }
    }
    $m[$n - 8][8] = !$test;
    if ($version >= 7) {
        $g18 = 0b1111100100101;
        $d = $version << 12;
        while (hod_qr_bch_digit($d) - hod_qr_bch_digit($g18) >= 0) {
            $d ^= $g18 << (hod_qr_bch_digit($d) - hod_qr_bch_digit($g18));
        }
        $vbits = ($version << 12) | $d;
        for ($i = 0; $i < 18; $i++) {
            $on = !$test && (($vbits >> $i) & 1) === 1;
            $m[intdiv($i, 3)][$i % 3 + $n - 11] = $on;
            $m[$i % 3 + $n - 11][intdiv($i, 3)] = $on;
        }
    }
    // The data, in the zig-zag, masked.
    $inc = -1;
    $row = $n - 1;
    $bitIndex = 7;
    $byteIndex = 0;
    for ($col = $n - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        while (true) {
            for ($c = 0; $c < 2; $c++) {
                if ($m[$row][$col - $c] === null) {
                    $dark = $byteIndex < count($codewords) && (($codewords[$byteIndex] >> $bitIndex) & 1) === 1;
                    $m[$row][$col - $c] = hod_qr_mask($mask, $row, $col - $c) ? !$dark : $dark;
                    if (--$bitIndex === -1) {
                        $byteIndex++;
                        $bitIndex = 7;
                    }
                }
            }
            $row += $inc;
            if ($row < 0 || $row >= $n) {
                $row -= $inc;
                $inc = -$inc;
                break;
            }
        }
    }
    return $m;
}

/** The penalty of a symbol (the library's scoring, so the same mask wins). */
function hod_qr_penalty(array $m): float
{
    $n = count($m);
    $lost = 0.0;
    for ($row = 0; $row < $n; $row++) {
        for ($col = 0; $col < $n; $col++) {
            $same = 0;
            for ($r = -1; $r <= 1; $r++) {
                if ($row + $r < 0 || $row + $r >= $n) {
                    continue;
                }
                for ($c = -1; $c <= 1; $c++) {
                    if ($col + $c < 0 || $col + $c >= $n || ($r === 0 && $c === 0)) {
                        continue;
                    }
                    if ($m[$row][$col] === $m[$row + $r][$col + $c]) {
                        $same++;
                    }
                }
            }
            if ($same > 5) {
                $lost += 3 + $same - 5;
            }
        }
    }
    for ($row = 0; $row < $n - 1; $row++) {
        for ($col = 0; $col < $n - 1; $col++) {
            $count = (int) $m[$row][$col] + (int) $m[$row + 1][$col] + (int) $m[$row][$col + 1] + (int) $m[$row + 1][$col + 1];
            if ($count === 0 || $count === 4) {
                $lost += 3;
            }
        }
    }
    $finder = [true, false, true, true, true, false, true];
    for ($a = 0; $a < $n; $a++) {
        for ($b = 0; $b < $n - 6; $b++) {
            $h = true;
            $v = true;
            for ($k = 0; $k < 7; $k++) {
                $h = $h && $m[$a][$b + $k] === $finder[$k];
                $v = $v && $m[$b + $k][$a] === $finder[$k];
            }
            $lost += ($h ? 40 : 0) + ($v ? 40 : 0);
        }
    }
    $dark = 0;
    foreach ($m as $line) {
        $dark += count(array_filter($line));
    }
    return $lost + abs(100 * $dark / $n / $n - 50) / 5 * 10;
}

/**
 * The QR matrix for a string (bytes as given; UTF-8 stays UTF-8), the
 * smallest version from 1 to 10 at level M, the library's best mask.
 *
 * @return list<list<bool>>
 */
function hod_qr_matrix(string $data): array
{
    $version = 0;
    for ($v = 1; $v <= 10; $v++) {
        $row = HOD_QR_RS_M[$v];
        $capacity = 0;
        for ($k = 0; $k < count($row); $k += 3) {
            $capacity += $row[$k] * $row[$k + 2];
        }
        if (4 + ($v < 10 ? 8 : 16) + 8 * strlen($data) <= $capacity * 8) {
            $version = $v;
            break;
        }
    }
    if ($version === 0) {
        throw new LengthException('QR data too long (at most 213 bytes)');
    }
    $codewords = hod_qr_codewords($data, $version);
    $best = 0;
    $min = 0.0;
    for ($mask = 0; $mask < 8; $mask++) {
        $p = hod_qr_penalty(hod_qr_build($version, $mask, $codewords, true));
        if ($mask === 0 || $min > $p) {
            $min = $p;
            $best = $mask;
        }
    }
    return hod_qr_build($version, $best, $codewords, false);
}

/** A black-on-white PNG of a matrix: $scale px per module, a $margin-module quiet zone. No GD needed. */
function hod_qr_png(array $matrix, int $scale = 8, int $margin = 4): string
{
    $n = count($matrix);
    $size = ($n + 2 * $margin) * $scale;
    $raw = '';
    for ($y = 0; $y < $size; $y++) {
        $mr = intdiv($y, $scale) - $margin;
        $row = '';
        for ($x = 0; $x < $size; $x++) {
            $mc = intdiv($x, $scale) - $margin;
            $dark = $mr >= 0 && $mr < $n && $mc >= 0 && $mc < $n && $matrix[$mr][$mc];
            $row .= $dark ? '0' : '1';   // 1-bit greyscale: 0 black, 1 white
        }
        $row = str_pad($row, (int) ceil($size / 8) * 8, '1');
        $raw .= "\x00";
        foreach (str_split($row, 8) as $byte) {
            $raw .= chr(bindec($byte));
        }
    }
    $chunk = static fn (string $type, string $body): string => pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $size, $size, 1, 0, 0, 0, 0))
        . $chunk('IDAT', (string) gzcompress($raw, 9))
        . $chunk('IEND', '');
}
