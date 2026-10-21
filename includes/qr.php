<?php
declare(strict_types=1);

/* =========================================================
   FatherGateway — Pure-PHP QR Code generator (byte mode, EC level M)
   No GD, no external services: builds the module matrix and renders SVG.
   Implements ISO/IEC 18004 encoding (RS blocks, mask selection, BCH).
   ========================================================= */

/** EC block layout per version for level M: [numBlocks, totalCodewords, dataCodewords]. */
function qr_ec_table(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    return $t = [
        1 => [[1,26,16]], 2 => [[1,44,28]], 3 => [[1,70,44]], 4 => [[2,50,32]],
        5 => [[2,67,43]], 6 => [[4,43,27]], 7 => [[4,49,31]], 8 => [[2,60,38],[2,61,39]],
        9 => [[3,58,36],[2,59,37]], 10 => [[4,69,43],[1,70,44]], 11 => [[1,80,50],[4,81,51]],
        12 => [[6,58,36],[2,59,37]], 13 => [[8,59,37],[1,60,38]], 14 => [[4,64,40],[5,65,41]],
        15 => [[5,65,41],[5,66,42]], 16 => [[7,73,45],[3,74,46]], 17 => [[10,74,46],[1,75,47]],
        18 => [[9,69,43],[4,70,44]], 19 => [[3,70,44],[11,71,45]], 20 => [[3,67,41],[13,68,42]],
        21 => [[17,68,42]], 22 => [[17,74,46]], 23 => [[4,75,47],[14,76,48]], 24 => [[6,73,45],[14,74,46]],
        25 => [[8,75,47],[13,76,48]], 26 => [[19,74,46],[4,75,47]], 27 => [[22,73,45],[3,74,46]],
        28 => [[3,73,45],[23,74,46]], 29 => [[21,73,45],[7,74,46]], 30 => [[19,75,47],[10,76,48]],
        31 => [[2,74,46],[29,75,47]], 32 => [[10,74,46],[23,75,47]], 33 => [[14,74,46],[21,75,47]],
        34 => [[14,74,46],[23,75,47]], 35 => [[12,75,47],[26,76,48]], 36 => [[6,75,47],[34,76,48]],
        37 => [[29,74,46],[14,75,47]], 38 => [[13,74,46],[32,75,47]], 39 => [[40,75,47],[7,76,48]],
        40 => [[18,75,47],[31,76,48]],
    ];
}

/** Alignment pattern centre coordinates per version. */
function qr_align_table(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    return $t = [
        2 => [6,18], 3 => [6,22], 4 => [6,26], 5 => [6,30], 6 => [6,34], 7 => [6,22,38],
        8 => [6,24,42], 9 => [6,26,46], 10 => [6,28,50], 11 => [6,30,54], 12 => [6,32,58],
        13 => [6,34,62], 14 => [6,26,46,66], 15 => [6,26,48,70], 16 => [6,26,50,74],
        17 => [6,30,54,78], 18 => [6,30,56,82], 19 => [6,30,58,86], 20 => [6,34,62,90],
        21 => [6,28,50,72,94], 22 => [6,26,50,74,98], 23 => [6,30,54,78,102], 24 => [6,28,54,80,106],
        25 => [6,32,58,84,110], 26 => [6,30,58,86,114], 27 => [6,34,62,90,118], 28 => [6,26,50,74,98,122],
        29 => [6,30,54,78,102,126], 30 => [6,26,52,78,104,130], 31 => [6,30,56,82,108,134],
        32 => [6,34,60,86,112,138], 33 => [6,30,58,86,114,142], 34 => [6,34,62,90,118,146],
        35 => [6,30,54,78,102,126,150], 36 => [6,24,50,76,102,128,154], 37 => [6,28,54,80,106,132,158],
        38 => [6,32,58,84,110,136,162], 39 => [6,26,54,82,110,138,166], 40 => [6,30,58,86,114,142,170],
    ];
}

/** GF(256) exp/log tables (primitive polynomial 0x11D). */
function qr_gf(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11d;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    return $t = ['exp' => $exp, 'log' => $log];
}

function qr_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    $g = qr_gf();
    return $g['exp'][$g['log'][$a] + $g['log'][$b]];
}

/** Reed-Solomon generator polynomial (highest degree first) for n EC codewords. */
function qr_gen_poly(int $n): array
{
    static $cache = [];
    if (isset($cache[$n])) {
        return $cache[$n];
    }
    $g = qr_gf();
    $poly = [1]; // lowest-degree-first
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $c) {
            $next[$j + 1] ^= $c;
            $next[$j] ^= qr_mul($c, $g['exp'][$i]);
        }
        $poly = $next;
    }
    return $cache[$n] = array_reverse($poly);
}

/** Compute n Reed-Solomon EC codewords for a data block. */
function qr_rs(array $data, int $n): array
{
    $gen = qr_gen_poly($n);
    $res = array_fill(0, $n, 0);
    foreach ($data as $b) {
        $factor = $b ^ $res[0];
        array_shift($res);
        $res[] = 0;
        for ($i = 0; $i < $n; $i++) {
            $res[$i] ^= qr_mul($gen[$i + 1], $factor);
        }
    }
    return $res;
}

/** Total data codewords available at a version (level M). */
function qr_data_capacity(int $version): int
{
    $sum = 0;
    foreach (qr_ec_table()[$version] as $g) {
        $sum += $g[0] * $g[2];
    }
    return $sum;
}

/** Build the interleaved codeword stream for a byte string. */
function qr_codewords(string $data, int $version): array
{
    $bits = '0100';
    $bits .= str_pad(decbin(strlen($data)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $cap = qr_data_capacity($version) * 8;
    if (strlen($bits) > $cap) {
        return [];
    }
    $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    }
    $pads = [0xEC, 0x11];
    $p = 0;
    while (strlen($bits) < $cap) {
        $bits .= str_pad(decbin($pads[$p % 2]), 8, '0', STR_PAD_LEFT);
        $p++;
    }

    $cw = [];
    foreach (str_split($bits, 8) as $byte) {
        $cw[] = (int) bindec($byte);
    }

    $dataBlocks = [];
    $ecBlocks = [];
    $pos = 0;
    foreach (qr_ec_table()[$version] as $g) {
        [$nb, $nt, $nd] = $g;
        for ($i = 0; $i < $nb; $i++) {
            $chunk = array_slice($cw, $pos, $nd);
            $pos += $nd;
            $dataBlocks[] = $chunk;
            $ecBlocks[] = qr_rs($chunk, $nt - $nd);
        }
    }

    $out = [];
    $maxData = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($dataBlocks as $b) {
            if ($i < count($b)) {
                $out[] = $b[$i];
            }
        }
    }
    $maxEc = count($ecBlocks[0]);
    for ($i = 0; $i < $maxEc; $i++) {
        foreach ($ecBlocks as $e) {
            $out[] = $e[$i];
        }
    }
    return $out;
}

/** Mask predicate. */
function qr_mask_bit(int $pattern, int $i, int $j): bool
{
    switch ($pattern) {
        case 0: return ($i + $j) % 2 === 0;
        case 1: return $i % 2 === 0;
        case 2: return $j % 3 === 0;
        case 3: return ($i + $j) % 3 === 0;
        case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0;
        case 5: return (($i * $j) % 2) + (($i * $j) % 3) === 0;
        case 6: return ((($i * $j) % 2) + (($i * $j) % 3)) % 2 === 0;
        default: return ((($i * $j) % 3) + (($i + $j) % 2)) % 2 === 0;
    }
}

/** BCH 15-bit format information. */
function qr_format_info(int $ecBits, int $mask): int
{
    $data = ($ecBits << 3) | $mask;
    $d = $data << 10;
    $g = 0x537;
    while (qr_bch_digit($d) - qr_bch_digit($g) >= 0) {
        $d ^= $g << (qr_bch_digit($d) - qr_bch_digit($g));
    }
    return (($data << 10) | $d) ^ 0x5412;
}

/** BCH 18-bit version information. */
function qr_version_info(int $version): int
{
    $d = $version << 12;
    $g = 0x1f25;
    while (qr_bch_digit($d) - qr_bch_digit($g) >= 0) {
        $d ^= $g << (qr_bch_digit($d) - qr_bch_digit($g));
    }
    return ($version << 12) | $d;
}

function qr_bch_digit(int $data): int
{
    $digit = 0;
    while ($data !== 0) {
        $digit++;
        $data >>= 1;
    }
    return $digit;
}

/** Module penalty score (used to pick the best mask). */
function qr_penalty(array $m): int
{
    $n = count($m);
    $lost = 0;

    for ($row = 0; $row < $n; $row++) {
        for ($col = 0; $col < $n; $col++) {
            $same = 0;
            $dark = $m[$row][$col];
            for ($r = -1; $r <= 1; $r++) {
                if ($row + $r < 0 || $n <= $row + $r) {
                    continue;
                }
                for ($c = -1; $c <= 1; $c++) {
                    if ($col + $c < 0 || $n <= $col + $c || ($r === 0 && $c === 0)) {
                        continue;
                    }
                    if ($dark === $m[$row + $r][$col + $c]) {
                        $same++;
                    }
                }
            }
            if ($same > 5) {
                $lost += 3 + ($same - 5);
            }
            if ($row + 1 < $n && $col + 1 < $n
                && $dark === $m[$row + 1][$col]
                && $dark === $m[$row][$col + 1]
                && $dark === $m[$row + 1][$col + 1]) {
                $lost += 3;
            }
            if ($col + 6 < $n
                && $dark && !$m[$row][$col + 1] && $m[$row][$col + 2] && $m[$row][$col + 3]
                && $m[$row][$col + 4] && !$m[$row][$col + 5] && $m[$row][$col + 6]) {
                $lost += 40;
            }
            if ($row + 6 < $n
                && $dark && !$m[$row + 1][$col] && $m[$row + 2][$col] && $m[$row + 3][$col]
                && $m[$row + 4][$col] && !$m[$row + 5][$col] && $m[$row + 6][$col]) {
                $lost += 40;
            }
        }
    }

    $darkCount = 0;
    foreach ($m as $row) {
        foreach ($row as $v) {
            if ($v) {
                $darkCount++;
            }
        }
    }
    $ratio = abs(100 * $darkCount / ($n * $n) - 50) / 5;
    $lost += (int) ($ratio * 10);
    return (int) $lost;
}

/** Build the full matrix for a fixed version + mask. */
function qr_make(string $data, int $version, int $ecBits, int $mask): array
{
    $n = $version * 4 + 17;
    $m = array_fill(0, $n, array_fill(0, $n, null));

    $finder = function (int $row, int $col) use (&$m, $n): void {
        for ($r = -1; $r <= 7; $r++) {
            if ($row + $r <= -1 || $n <= $row + $r) {
                continue;
            }
            for ($c = -1; $c <= 7; $c++) {
                if ($col + $c <= -1 || $n <= $col + $c) {
                    continue;
                }
                $on = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                    || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $m[$row + $r][$col + $c] = $on;
            }
        }
    };
    $finder(0, 0);
    $finder($n - 7, 0);
    $finder(0, $n - 7);

    // alignment patterns
    $align = qr_align_table()[$version] ?? [];
    $last = $n - 7;
    foreach ($align as $r) {
        foreach ($align as $c) {
            if (($r === 6 && $c === 6) || ($r === 6 && $c === $last) || ($r === $last && $c === 6)) {
                continue;
            }
            if ($m[$r][$c] !== null) {
                continue;
            }
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $on = max(abs($dr), abs($dc)) !== 1;
                    $m[$r + $dr][$c + $dc] = $on;
                }
            }
        }
    }

    // timing patterns
    for ($r = 8; $r < $n - 8; $r++) {
        if ($m[$r][6] === null) {
            $m[$r][6] = $r % 2 === 0;
        }
    }
    for ($c = 8; $c < $n - 8; $c++) {
        if ($m[6][$c] === null) {
            $m[6][$c] = $c % 2 === 0;
        }
    }

    // format info + dark module
    $bits = qr_format_info($ecBits, $mask);
    for ($i = 0; $i < 15; $i++) {
        $mod = (($bits >> $i) & 1) === 1;
        if ($i < 6) {
            $m[$i][8] = $mod;
        } elseif ($i < 8) {
            $m[$i + 1][8] = $mod;
        } else {
            $m[$n - 15 + $i][8] = $mod;
        }
    }
    for ($i = 0; $i < 15; $i++) {
        $mod = (($bits >> $i) & 1) === 1;
        if ($i < 8) {
            $m[8][$n - $i - 1] = $mod;
        } elseif ($i < 9) {
            $m[8][15 - $i - 1 + 1] = $mod;
        } else {
            $m[8][15 - $i - 1] = $mod;
        }
    }
    $m[$n - 8][8] = true;

    // version info (v >= 7)
    if ($version >= 7) {
        $vb = qr_version_info($version);
        for ($i = 0; $i < 18; $i++) {
            $mod = (($vb >> $i) & 1) === 1;
            $m[intdiv($i, 3)][$i % 3 + $n - 8 - 3] = $mod;
            $m[$i % 3 + $n - 8 - 3][intdiv($i, 3)] = $mod;
        }
    }

    // data placement with masking
    $data2 = qr_codewords($data, $version);
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
                    $dark = false;
                    if ($byteIndex < count($data2)) {
                        $dark = (($data2[$byteIndex] >> $bitIndex) & 1) === 1;
                    }
                    if (qr_mask_bit($mask, $row, $col - $c)) {
                        $dark = !$dark;
                    }
                    $m[$row][$col - $c] = $dark;
                    $bitIndex--;
                    if ($bitIndex === -1) {
                        $byteIndex++;
                        $bitIndex = 7;
                    }
                }
            }
            $row += $inc;
            if ($row < 0 || $n <= $row) {
                $row -= $inc;
                $inc = -$inc;
                break;
            }
        }
    }

    return $m;
}

/** Pick the smallest version that fits, then the lowest-penalty mask. */
function qr_matrix(string $data): array
{
    $version = null;
    for ($v = 1; $v <= 40; $v++) {
        if (qr_data_capacity($v) >= intdiv(strlen($data) * 8 + 4, 8) + 3) {
            $version = $v;
            break;
        }
    }
    if ($version === null) {
        $version = 40;
    }

    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = qr_make($data, $version, 0, $mask);
        $score = qr_penalty($m);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $m;
        }
    }
    return $best;
}

/** Render an SVG QR code for the given text. */
function qr_svg(string $text, int $scale = 6, int $border = 4): string
{
    $m = qr_matrix($text);
    $n = count($m);
    $size = ($n + $border * 2) * $scale;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">';
    $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';
    $svg .= '<path fill="#0b1220" d="';
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c < $n; $c++) {
            if ($m[$r][$c]) {
                $x = ($c + $border) * $scale;
                $y = ($r + $border) * $scale;
                $svg .= 'M' . $x . ' ' . $y . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
            }
        }
    }
    $svg .= '"/></svg>';
    return $svg;
}
