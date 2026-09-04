<?php
// cad.php - Complete Web CAD Engine with Strict AutoCAD 2007 (AC1021) Header & DimStyle Compliance

// Drawing file selection, revision tracking, and collaborative-presence identity.
$defaultFileName = 'cad_drawing.json';

function sanitizeDrawingFileName($fileName) {
    $fileName = trim((string)$fileName);
    if ($fileName === '' || !preg_match('/^[a-zA-Z0-9_-]+(?:\.json)?$/', $fileName)) {
        return null;
    }
    return substr($fileName, -5) === '.json' ? $fileName : $fileName . '.json';
}

function getDrawingFileName($fallback) {
    $requestedName = $_POST['file'] ?? ($_COOKIE['cad_file'] ?? $fallback);
    return sanitizeDrawingFileName($requestedName) ?? $fallback;
}

function getDrawingRevision($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    return (string)filemtime($filePath) . '-' . (string)filesize($filePath);
}

function getDrawingEntityRevision($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    $drawing = json_decode(file_get_contents($filePath), true);
    if (!is_array($drawing)) {
        return null;
    }
    return hash('sha256', json_encode($drawing['entities'] ?? $drawing, JSON_UNESCAPED_SLASHES));
}

$dataFile = __DIR__ . '/' . getDrawingFileName($defaultFileName);
$presenceFile = __DIR__ . '/cad_presence.json';

function sanitizeNickname($nickname) {
    $nickname = trim((string)$nickname);
    if ($nickname === '' || !preg_match('/^[\p{L}\p{N} _-]{1,24}$/u', $nickname)) {
        return null;
    }
    return $nickname;
}

function getUserId($clientId = '') {
    $userId = preg_match('/^[a-f0-9]{32}$/', $clientId) ? $clientId : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $userId)) {
        $userId = bin2hex(random_bytes(16));
    }
    return $userId;
}

// DXF export helpers convert editor colors and geometry into AutoCAD-compatible data.
function hexToACI($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $colors = [
        1 => [255, 0, 0],       // Red
        2 => [255, 255, 0],     // Yellow
        3 => [0, 255, 0],       // Green
        4 => [0, 255, 255],     // Cyan
        5 => [0, 0, 255],       // Blue
        6 => [255, 0, 255],     // Magenta
        7 => [255, 255, 255],   // White
        8 => [128, 128, 128],   // Gray
        9 => [192, 192, 192]    // Light Gray
    ];

    $bestIndex = 7;
    $minDist = PHP_FLOAT_MAX;
    foreach ($colors as $idx => $rgb) {
        $d = pow($r - $rgb[0], 2) + pow($g - $rgb[1], 2) + pow($b - $rgb[2], 2);
        if ($d < $minDist) {
            $minDist = $d;
            $bestIndex = $idx;
        }
    }
    return $bestIndex;
}

function getDXFGridBounds($entities) {
    $bounds = ['minX' => INF, 'minY' => INF, 'maxX' => -INF, 'maxY' => -INF];
    foreach ($entities as $entity) {
        $type = $entity['type'] ?? '';
        $points = [];
        if ($type === 'line') {
            $points = [
                [(float)$entity['x1'], (float)$entity['y1']],
                [(float)$entity['x2'], (float)$entity['y2']]
            ];
        } elseif ($type === 'rect') {
            $x1 = (float)$entity['x'];
            $y1 = (float)$entity['y'];
            $x2 = $x1 + (float)$entity['w'];
            $y2 = $y1 + (float)$entity['h'];
            $points = [[$x1, $y1], [$x2, $y2]];
        } elseif ($type === 'pline') {
            foreach (($entity['points'] ?? []) as $point) {
                $points[] = [(float)$point['x'], (float)$point['y']];
            }
        } elseif (in_array($type, ['circle', 'arc'], true)) {
            $cx = (float)$entity['cx'];
            $cy = (float)$entity['cy'];
            $radius = abs((float)$entity['r']);
            $points = [[$cx - $radius, $cy - $radius], [$cx + $radius, $cy + $radius]];
        } elseif ($type === 'ellipse') {
            $cx = (float)$entity['cx'];
            $cy = (float)$entity['cy'];
            $points = [
                [$cx - abs((float)$entity['rx']), $cy - abs((float)$entity['ry'])],
                [$cx + abs((float)$entity['rx']), $cy + abs((float)$entity['ry'])]
            ];
        } elseif ($type === 'point') {
            $points = [[(float)$entity['x'], (float)$entity['y']]];
        }
        foreach ($points as $point) {
            $bounds['minX'] = min($bounds['minX'], $point[0]);
            $bounds['minY'] = min($bounds['minY'], $point[1]);
            $bounds['maxX'] = max($bounds['maxX'], $point[0]);
            $bounds['maxY'] = max($bounds['maxY'], $point[1]);
        }
    }
    return is_infinite($bounds['minX']) ? null : $bounds;
}

function getPaperFrameSpecFromKey($paperSizeKey) {
    $paperSizeKey = strtoupper(trim((string)$paperSizeKey));
    if (!preg_match('/^(A[0-4])-(P|L)$/', $paperSizeKey, $matches)) {
        $paperSizeKey = 'A3-L';
        preg_match('/^(A[0-4])-(P|L)$/', $paperSizeKey, $matches);
    }
    $sizes = [
        'A4' => ['widthMm' => 210, 'heightMm' => 297],
        'A3' => ['widthMm' => 297, 'heightMm' => 420],
        'A2' => ['widthMm' => 420, 'heightMm' => 594],
        'A1' => ['widthMm' => 594, 'heightMm' => 841],
        'A0' => ['widthMm' => 841, 'heightMm' => 1189],
    ];
    $base = $matches[1] ?? 'A3';
    $portrait = ($matches[2] ?? 'L') === 'P';
    $size = $sizes[$base] ?? $sizes['A3'];
    return [
        'name' => $base . ' ' . ($portrait ? 'Portrait' : 'Landscape'),
        'widthMm' => $portrait ? $size['widthMm'] : $size['heightMm'],
        'heightMm' => $portrait ? $size['heightMm'] : $size['widthMm']
    ];
}

function getDXFHatchBoundaryLoops($entity) {
    $hatch = $entity['hatch'] ?? null;
    if (!$hatch || !is_array($hatch)) return [];
    $distance = abs((float)($hatch['distance'] ?? 0));
    if ($distance <= 0) return [];
    $sideSign = ((float)($hatch['sideSign'] ?? 1)) < 0 ? -1 : 1;
    $type = $entity['type'] ?? '';

    $sourcePoints = [];
    if ($type === 'line') {
        $sourcePoints = [
            ['x' => (float)$entity['x1'], 'y' => (float)$entity['y1']],
            ['x' => (float)$entity['x2'], 'y' => (float)$entity['y2']]
        ];
    } elseif ($type === 'pline' && empty($entity['closed'])) {
        $sourcePoints = array_map(static fn($point) => ['x' => (float)$point['x'], 'y' => (float)$point['y']], $entity['points'] ?? []);
    }
    if ($sourcePoints) {
        $loops = [];
        for ($index = 0; $index < count($sourcePoints) - 1; $index++) {
            $start = $sourcePoints[$index];
            $end = $sourcePoints[$index + 1];
            $dx = $end['x'] - $start['x'];
            $dy = $end['y'] - $start['y'];
            $length = hypot($dx, $dy);
            if ($length < 1e-9) continue;
            $normalX = -$dy / $length * $distance * $sideSign;
            $normalY = $dx / $length * $distance * $sideSign;
            $loops[] = [$start, $end,
                ['x' => $end['x'] + $normalX, 'y' => $end['y'] + $normalY],
                ['x' => $start['x'] + $normalX, 'y' => $start['y'] + $normalY]
            ];
        }
        return $loops;
    }

    if ($type === 'rect') {
        $x1 = (float)$entity['x'];
        $y1 = (float)$entity['y'];
        $x2 = $x1 + (float)$entity['w'];
        $y2 = $y1 + (float)$entity['h'];
        $minX = min($x1, $x2); $maxX = max($x1, $x2);
        $minY = min($y1, $y2); $maxY = max($y1, $y2);
        $inside = $sideSign < 0;
        $outer = [[
            'x' => $minX - ($inside ? 0 : $distance), 'y' => $minY - ($inside ? 0 : $distance)
        ], [
            'x' => $maxX + ($inside ? 0 : $distance), 'y' => $minY - ($inside ? 0 : $distance)
        ], [
            'x' => $maxX + ($inside ? 0 : $distance), 'y' => $maxY + ($inside ? 0 : $distance)
        ], [
            'x' => $minX - ($inside ? 0 : $distance), 'y' => $maxY + ($inside ? 0 : $distance)
        ]];
        $inner = [[['x' => $minX, 'y' => $minY], ['x' => $maxX, 'y' => $minY], ['x' => $maxX, 'y' => $maxY], ['x' => $minX, 'y' => $maxY]]];
        return $inside ? [$inner[0], $outer] : [$outer, $inner[0]];
    }

    if ($type === 'circle' || $type === 'ellipse') {
        $cx = (float)$entity['cx']; $cy = (float)$entity['cy'];
        $rx = $type === 'circle' ? (float)$entity['r'] : abs((float)$entity['rx']);
        $ry = $type === 'circle' ? (float)$entity['r'] : abs((float)$entity['ry']);
        $outerScale = $sideSign < 0 ? 0 : $distance;
        $innerScale = $sideSign < 0 ? -$distance : 0;
        $makeLoop = static function ($scale) use ($cx, $cy, $rx, $ry) {
            $points = [];
            for ($index = 0; $index < 64; $index++) {
                $angle = 2 * M_PI * $index / 64;
                $points[] = ['x' => $cx + ($rx + $scale) * cos($angle), 'y' => $cy + ($ry + $scale) * sin($angle)];
            }
            return $points;
        };
        return [$makeLoop($outerScale), $makeLoop($innerScale)];
    }

    if ($type === 'pline' && !empty($entity['closed'])) {
        $points = array_map(static fn($point) => ['x' => (float)$point['x'], 'y' => (float)$point['y']], $entity['points'] ?? []);
        return $points ? [$points] : [];
    }
    return [];
}

// AutoCAD 2007 (AC1021) DXF generator.
function generateDXF2007($entities, $angleUnit = 'deg', $printScale = 100, $paperSizeKey = 'A3-L', $paperFrameCenterX = null, $paperFrameCenterY = null) {
    $dxf = [];
    $nl = "\r\n";
    $scale = max(1.0, (float)$printScale);
    $pointTextHeight = 0.0025 * $scale;
    $pointMarkerRadius = 0.0015 * $scale;
    $pointTextOffset = $pointTextHeight * 0.9;
    $paperSpec = getPaperFrameSpecFromKey($paperSizeKey);
    $paperFrameWidth = ($paperSpec['widthMm'] / 1000) * $scale;
    $paperFrameHeight = ($paperSpec['heightMm'] / 1000) * $scale;
    $drawingBounds = getDXFGridBounds($entities);
    if ($drawingBounds) {
        $paperFrameCenterX = ((float)$drawingBounds['minX'] + (float)$drawingBounds['maxX']) / 2;
        $paperFrameCenterY = ((float)$drawingBounds['minY'] + (float)$drawingBounds['maxY']) / 2;
    } else {
        $paperFrameCenterX = is_numeric($paperFrameCenterX) ? (float)$paperFrameCenterX : 0.0;
        $paperFrameCenterY = is_numeric($paperFrameCenterY) ? (float)$paperFrameCenterY : 0.0;
    }
    $paperFrameLeft = $paperFrameCenterX - $paperFrameWidth / 2;
    $paperFrameRight = $paperFrameCenterX + $paperFrameWidth / 2;
    $paperFrameTop = $paperFrameCenterY + $paperFrameHeight / 2;
    $paperFrameBottom = $paperFrameCenterY - $paperFrameHeight / 2;
    $paperSizeBase = preg_replace('/-.*/', '', (string)$paperSizeKey) ?: 'A3';
    $paperLayoutName = sprintf(
        'ISO_%s_(%.2f_x_%.2f_MM)',
        $paperSizeBase,
        (float)$paperSpec['widthMm'],
        (float)$paperSpec['heightMm']
    );
    $alignedDimensionDefs = [];
    $encodeDxfText = function(string $text): string {
        $text = str_replace(["\\", "\r", "\n"], ["\\\\", "", " "], $text);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1253//TRANSLIT//IGNORE', $text);
            if ($converted !== false) return $converted;
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($text, 'Windows-1253', 'UTF-8');
            if ($converted !== false) return $converted;
        }
        return $text;
    };
    $encodeDxfUnicodeText = function(string $text): string {
        $text = str_replace(["\\", "\r", "\n"], ["\\\\", "", " "], $text);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) return $text;
        $encoded = '';
        foreach ($chars as $char) {
            $code = null;
            if (function_exists('mb_ord')) {
                $code = mb_ord($char, 'UTF-8');
            } elseif (function_exists('iconv')) {
                $ucs4 = iconv('UTF-8', 'UCS-4BE', $char);
                if ($ucs4 !== false && strlen($ucs4) === 4) {
                    $code = unpack('N', $ucs4)[1];
                }
            }
            if ($code === null) {
                $bytes = unpack('C*', $char);
                $code = $bytes && count($bytes) === 1 ? $bytes[1] : 63;
            }
            if ($code >= 32 && $code <= 126) {
                $encoded .= $char;
            } else {
                $encoded .= sprintf('\\U+%04X', $code);
            }
        }
        return $encoded;
    };
    $measureTextWidth = static function (string $text, float $height): float {
        $chars = function_exists('preg_split') ? preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) : false;
        if ($chars === false) {
            $chars = str_split($text);
        }
        $units = 0.0;
        foreach ($chars as $char) {
            if ($char === ' ') {
                $units += 0.35;
            } elseif (preg_match('/[il1\.\,\:\;]/u', $char)) {
                $units += 0.35;
            } elseif (preg_match('/[mwMW@#%&]/u', $char)) {
                $units += 1.1;
            } elseif (preg_match('/[0-9]/u', $char)) {
                $units += 0.6;
            } else {
                $units += 0.7;
            }
        }
        return max(1.0, $units * $height * 0.75 + $height * 0.35);
    };

    // Handles map
    $hRootDict    = "C";
    $hPlotStyles  = "D";
    $hNormalPS    = "E";
    $hGroupDict   = "F";
    $hLayoutDict  = "10";
    $hModelLayout = "11";
    $hPaperLayout = "12";
    $hModelBlockR = "23";
    $hPaperBlockR = "24";
    $hArchTickBR  = "2A";
    $hNorthArrowBR = "2E";
    $hNext        = 0x50;

    if (is_array($entities)) {
        $alignedDimIndex = 1;
        foreach ($entities as $index => $ent) {
            if (($ent['type'] ?? '') !== 'dimension' || ($ent['kind'] ?? 'distance') === 'angle') continue;
            $dx = (float)($ent['x2'] ?? 0) - (float)($ent['x1'] ?? 0);
            $dy = (float)($ent['y2'] ?? 0) - (float)($ent['y1'] ?? 0);
            $length = hypot($dx, $dy);
            if ($length < 1e-9) continue;

            $offset = (float)($ent['offset'] ?? 0);
            $nx = -$dy / $length;
            $ny = $dx / $length;
            $x1 = (float)$ent['x1'];
            $y1 = (float)$ent['y1'];
            $x2 = (float)$ent['x2'];
            $y2 = (float)$ent['y2'];
            $d1x = $x1 + $nx * $offset;
            $d1y = $y1 + $ny * $offset;
            $d2x = $x2 + $nx * $offset;
            $d2y = $y2 + $ny * $offset;
            $textX = ($d1x + $d2x) / 2;
            $textY = ($d1y + $d2y) / 2;
            $label = number_format($length, 2, '.', '');
            $labelAngle = rad2deg(atan2($dy, $dx));
            if ($labelAngle > 90 || $labelAngle < -90) $labelAngle += 180;
            $circleRadius = 0.3;
            $textHeight = $pointTextHeight;
            $textWidth = $measureTextWidth($label, $textHeight);

            $alignedDimensionDefs[$index] = [
                'blockName' => '*D' . $alignedDimIndex++,
                'blockRecordHandle' => dechex($hNext++),
                'blockHandle' => dechex($hNext++),
                'blockEndHandle' => dechex($hNext++),
                'ext1Handle' => dechex($hNext++),
                'ext2Handle' => dechex($hNext++),
                'dimLineHandle' => dechex($hNext++),
                'circle1Handle' => dechex($hNext++),
                'circle2Handle' => dechex($hNext++),
                'textHandle' => dechex($hNext++),
                'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2,
                'd1x' => $d1x, 'd1y' => $d1y, 'd2x' => $d2x, 'd2y' => $d2y,
                'textX' => $textX, 'textY' => $textY,
                'label' => $label, 'labelAngle' => $labelAngle,
                'circleRadius' => $circleRadius,
                'textHeight' => $textHeight,
                'textWidth' => $textWidth
            ];
        }
    }

    $aunits = $angleUnit === 'grad' ? 2 : ($angleUnit === 'rad' ? 3 : 0);

    // 1. HEADER SECTION (Correct System Variable Group Codes)
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}HEADER";
    $dxf[] = "9{$nl}\$ACADVER";
    $dxf[] = "1{$nl}AC1021"; // AutoCAD 2007
    $dxf[] = "9{$nl}\$DWGCODEPAGE";
    $dxf[] = "3{$nl}ANSI_1253";
    $dxf[] = "9{$nl}\$HANDSEED";
    $dxf[] = "5{$nl}FFFF";
    $dxf[] = "9{$nl}\$MEASUREMENT";
    $dxf[] = "70{$nl}1"; // Metric
    $dxf[] = "9{$nl}\$INSUNITS";
    $dxf[] = "70{$nl}6"; // Meters
    $dxf[] = "9{$nl}\$LUNITS";
    $dxf[] = "70{$nl}2"; // Decimal
    $dxf[] = "9{$nl}\$LUPREC";
    $dxf[] = "70{$nl}3"; // 3 Decimals
    $dxf[] = "9{$nl}\$AUNITS";
    $dxf[] = "70{$nl}{$aunits}"; // 0=Deg, 2=Grad
    $dxf[] = "9{$nl}\$AUPREC";
    $dxf[] = "70{$nl}4"; // 4 Decimals
    $dxf[] = "9{$nl}\$ANGBASE";
    $dxf[] = "50{$nl}90.0"; // North (+Y)
    $dxf[] = "9{$nl}\$ANGDIR";
    $dxf[] = "70{$nl}1"; // Clockwise

    // Header Dimension System Variables (Standard Header Group Codes)
    $dxf[] = "9{$nl}\$DIMSTYLE";
    $dxf[] = "2{$nl}STANDARD";
    $dxf[] = "9{$nl}\$DIMSCALE";
    $dxf[] = "40{$nl}1.0";
    $dxf[] = "9{$nl}\$DIMASZ";
    $dxf[] = "40{$nl}0.20";
    $dxf[] = "9{$nl}\$DIMEXO";
    $dxf[] = "40{$nl}0.0625";
    $dxf[] = "9{$nl}\$DIMDLI";
    $dxf[] = "40{$nl}0.38";
    $dxf[] = "9{$nl}\$DIMEXE";
    $dxf[] = "40{$nl}0.18";
    $dxf[] = "9{$nl}\$DIMDLE";
    $dxf[] = "40{$nl}0.0";
    $dxf[] = "9{$nl}\$DIMTXT";
    $dxf[] = "40{$nl}0.20";
    $dxf[] = "9{$nl}\$DIMCEN";
    $dxf[] = "40{$nl}0.0";
    $dxf[] = "9{$nl}\$DIMLFAC";
    $dxf[] = "40{$nl}1.0";
    $dxf[] = "9{$nl}\$DIMGAP";
    $dxf[] = "40{$nl}0.0";
    $dxf[] = "9{$nl}\$DIMTAD";
    $dxf[] = "70{$nl}0";
    $dxf[] = "9{$nl}\$DIMTIH";
    $dxf[] = "70{$nl}0";
    $dxf[] = "9{$nl}\$DIMTOH";
    $dxf[] = "70{$nl}0";
    $dxf[] = "9{$nl}\$DIMSE1";
    $dxf[] = "70{$nl}1";
    $dxf[] = "9{$nl}\$DIMSE2";
    $dxf[] = "70{$nl}1";
    $dxf[] = "9{$nl}\$DIMTMOVE";
    $dxf[] = "70{$nl}0";
    $dxf[] = "9{$nl}\$DIMATFIT";
    $dxf[] = "70{$nl}3";
    $dxf[] = "9{$nl}\$DIMDEC";
    $dxf[] = "70{$nl}2";
    $dxf[] = "9{$nl}\$DIMLUNIT";
    $dxf[] = "70{$nl}2";
    $dxf[] = "9{$nl}\$DIMAUNIT";
    $dxf[] = "70{$nl}{$aunits}";
    $dxf[] = "9{$nl}\$DIMADEC";
    $dxf[] = "70{$nl}4";
    $dxf[] = "9{$nl}\$DIMDSEP";
    $dxf[] = "70{$nl}46";
    $dxf[] = "9{$nl}\$DIMBLK";
    $dxf[] = "1{$nl}_ARCHTICK";
    $dxf[] = "9{$nl}\$DIMBLK1";
    $dxf[] = "1{$nl}_ARCHTICK";
    $dxf[] = "9{$nl}\$DIMBLK2";
    $dxf[] = "1{$nl}_ARCHTICK";
    $dxf[] = "9{$nl}\$DIMLDRBLK";
    $dxf[] = "1{$nl}_ARCHTICK";
    $dxf[] = "0{$nl}ENDSEC";

    // 2. CLASSES SECTION
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}CLASSES";
    $dxf[] = "0{$nl}CLASS";
    $dxf[] = "1{$nl}WIPEOUT";
    $dxf[] = "2{$nl}AcDbWipeout";
    $dxf[] = "3{$nl}WipeOut";
    $dxf[] = "90{$nl}127";
    $dxf[] = "91{$nl}0";
    $dxf[] = "280{$nl}0";
    $dxf[] = "281{$nl}1";
    $dxf[] = "0{$nl}CLASS";
    $dxf[] = "1{$nl}ACDBPLACEHOLDER";
    $dxf[] = "2{$nl}AcDbPlaceHolder";
    $dxf[] = "3{$nl}ObjectDBX Classes";
    $dxf[] = "90{$nl}0";
    $dxf[] = "91{$nl}0";
    $dxf[] = "280{$nl}0";
    $dxf[] = "281{$nl}0";
    $dxf[] = "0{$nl}CLASS";
    $dxf[] = "1{$nl}WIPEOUTVARIABLES";
    $dxf[] = "2{$nl}AcDbWipeoutVariables";
    $dxf[] = "3{$nl}WipeOut";
    $dxf[] = "90{$nl}0";
    $dxf[] = "91{$nl}0";
    $dxf[] = "280{$nl}0";
    $dxf[] = "281{$nl}0";
    $dxf[] = "0{$nl}ENDSEC";

    // 3. TABLES SECTION
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}TABLES";

    // VPORT
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}VPORT";
    $dxf[] = "5{$nl}1";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}0";
    $dxf[] = "0{$nl}ENDTAB";

    // LTYPE
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}LTYPE";
    $dxf[] = "5{$nl}2";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}3";

    $dxf[] = "0{$nl}LTYPE";
    $dxf[] = "5{$nl}17";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbLinetypeTableRecord";
    $dxf[] = "2{$nl}ByBlock";
    $dxf[] = "70{$nl}0";
    $dxf[] = "3{$nl}";
    $dxf[] = "72{$nl}65";
    $dxf[] = "73{$nl}0";
    $dxf[] = "40{$nl}0.0";

    $dxf[] = "0{$nl}LTYPE";
    $dxf[] = "5{$nl}18";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbLinetypeTableRecord";
    $dxf[] = "2{$nl}ByLayer";
    $dxf[] = "70{$nl}0";
    $dxf[] = "3{$nl}";
    $dxf[] = "72{$nl}65";
    $dxf[] = "73{$nl}0";
    $dxf[] = "40{$nl}0.0";

    $dxf[] = "0{$nl}LTYPE";
    $dxf[] = "5{$nl}19";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbLinetypeTableRecord";
    $dxf[] = "2{$nl}CONTINUOUS";
    $dxf[] = "70{$nl}0";
    $dxf[] = "3{$nl}Solid line";
    $dxf[] = "72{$nl}65";
    $dxf[] = "73{$nl}0";
    $dxf[] = "40{$nl}0.0";
    $dxf[] = "0{$nl}ENDTAB";

    // LAYER
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}LAYER";
    $dxf[] = "5{$nl}4";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}1";
    $dxf[] = "0{$nl}LAYER";
    $dxf[] = "5{$nl}5";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbLayerTableRecord";
    $dxf[] = "2{$nl}0";
    $dxf[] = "70{$nl}0";
    $dxf[] = "62{$nl}7";
    $dxf[] = "6{$nl}CONTINUOUS";
    $dxf[] = "390{$nl}{$hNormalPS}";
    $dxf[] = "0{$nl}ENDTAB";

    // STYLE
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}STYLE";
    $dxf[] = "5{$nl}6";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}1";
    $dxf[] = "0{$nl}STYLE";
    $dxf[] = "5{$nl}7";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbTextStyleTableRecord";
    $dxf[] = "2{$nl}STANDARD";
    $dxf[] = "70{$nl}0";
    $dxf[] = "40{$nl}0.0";
    $dxf[] = "41{$nl}1.0";
    $dxf[] = "50{$nl}0.0";
    $dxf[] = "71{$nl}0";
    $dxf[] = "42{$nl}0.2";
    $dxf[] = "3{$nl}txt";
    $dxf[] = "4{$nl}";
    $dxf[] = "0{$nl}ENDTAB";

    // VIEW
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}VIEW";
    $dxf[] = "5{$nl}8";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}0";
    $dxf[] = "0{$nl}ENDTAB";

    // UCS
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}UCS";
    $dxf[] = "5{$nl}9";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}0";
    $dxf[] = "0{$nl}ENDTAB";

    // APPID
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}APPID";
    $dxf[] = "5{$nl}A";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}1";
    $dxf[] = "0{$nl}APPID";
    $dxf[] = "5{$nl}B";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbRegAppTableRecord";
    $dxf[] = "2{$nl}ACAD";
    $dxf[] = "70{$nl}0";
    $dxf[] = "0{$nl}ENDTAB";

    // DIMSTYLE Table
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}DIMSTYLE";
    $dxf[] = "5{$nl}20";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}1";
    $dxf[] = "100{$nl}AcDbDimStyleTable";
    $dxf[] = "0{$nl}DIMSTYLE";
    $dxf[] = "105{$nl}21";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbDimStyleTableRecord";
    $dxf[] = "2{$nl}STANDARD";
    $dxf[] = "70{$nl}0";
    $dxf[] = "40{$nl}1.0";
    $dxf[] = "41{$nl}0.20";
    $dxf[] = "42{$nl}0.0625";
    $dxf[] = "43{$nl}0.38";
    $dxf[] = "44{$nl}0.18";
    $dxf[] = "46{$nl}0.0";
    $dxf[] = "140{$nl}0.20";
    $dxf[] = "141{$nl}0.0";
    $dxf[] = "143{$nl}1.0";
    $dxf[] = "147{$nl}0.0";
    $dxf[] = "71{$nl}0";
    $dxf[] = "72{$nl}0";
    $dxf[] = "73{$nl}0";
    $dxf[] = "74{$nl}0";
    $dxf[] = "75{$nl}1";
    $dxf[] = "76{$nl}1";
    $dxf[] = "77{$nl}0";
    $dxf[] = "271{$nl}2";
    $dxf[] = "275{$nl}{$aunits}";
    $dxf[] = "277{$nl}2";
    $dxf[] = "278{$nl}46";
    $dxf[] = "289{$nl}3";
    $dxf[] = "179{$nl}4";
    $dxf[] = "5{$nl}_ARCHTICK";
    $dxf[] = "6{$nl}_ARCHTICK";
    $dxf[] = "7{$nl}_ARCHTICK";
    $dxf[] = "342{$nl}{$hArchTickBR}";
    $dxf[] = "343{$nl}{$hArchTickBR}";
    $dxf[] = "344{$nl}{$hArchTickBR}";
    $dxf[] = "0{$nl}ENDTAB";

    // BLOCK_RECORD Table
    $dxf[] = "0{$nl}TABLE";
    $dxf[] = "2{$nl}BLOCK_RECORD";
    $dxf[] = "5{$nl}22";
    $dxf[] = "100{$nl}AcDbSymbolTable";
    $dxf[] = "70{$nl}" . (4 + count($alignedDimensionDefs));

    $dxf[] = "0{$nl}BLOCK_RECORD";
    $dxf[] = "5{$nl}{$hModelBlockR}";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbBlockTableRecord";
    $dxf[] = "2{$nl}*MODEL_SPACE";
    $dxf[] = "340{$nl}{$hModelLayout}";

    $dxf[] = "0{$nl}BLOCK_RECORD";
    $dxf[] = "5{$nl}{$hPaperBlockR}";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbBlockTableRecord";
    $dxf[] = "2{$nl}*PAPER_SPACE";
    $dxf[] = "340{$nl}{$hPaperLayout}";

    $dxf[] = "0{$nl}BLOCK_RECORD";
    $dxf[] = "5{$nl}{$hArchTickBR}";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbBlockTableRecord";
    $dxf[] = "2{$nl}_ARCHTICK";

    $dxf[] = "0{$nl}BLOCK_RECORD";
    $dxf[] = "5{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbSymbolTableRecord";
    $dxf[] = "100{$nl}AcDbBlockTableRecord";
    $dxf[] = "2{$nl}NORTH_ARROW";

    foreach ($alignedDimensionDefs as $dimDef) {
        $dxf[] = "0{$nl}BLOCK_RECORD";
        $dxf[] = "5{$nl}{$dimDef['blockRecordHandle']}";
        $dxf[] = "100{$nl}AcDbSymbolTableRecord";
        $dxf[] = "100{$nl}AcDbBlockTableRecord";
        $dxf[] = "2{$nl}{$dimDef['blockName']}";
    }

    $dxf[] = "0{$nl}ENDTAB";
    $dxf[] = "0{$nl}ENDSEC";

    // 4. BLOCKS SECTION
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}BLOCKS";

    // *MODEL_SPACE Block
    $dxf[] = "0{$nl}BLOCK";
    $dxf[] = "5{$nl}25";
    $dxf[] = "330{$nl}{$hModelBlockR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockBegin";
    $dxf[] = "2{$nl}*MODEL_SPACE";
    $dxf[] = "70{$nl}0";
    $dxf[] = "10{$nl}0.0";
    $dxf[] = "20{$nl}0.0";
    $dxf[] = "30{$nl}0.0";
    $dxf[] = "3{$nl}*MODEL_SPACE";
    $dxf[] = "1{$nl}";
    $dxf[] = "0{$nl}ENDBLK";
    $dxf[] = "5{$nl}26";
    $dxf[] = "330{$nl}{$hModelBlockR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockEnd";

    // *PAPER_SPACE Block
    $dxf[] = "0{$nl}BLOCK";
    $dxf[] = "5{$nl}27";
    $dxf[] = "330{$nl}{$hPaperBlockR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockBegin";
    $dxf[] = "2{$nl}*PAPER_SPACE";
    $dxf[] = "70{$nl}0";
    $dxf[] = "10{$nl}0.0";
    $dxf[] = "20{$nl}0.0";
    $dxf[] = "30{$nl}0.0";
    $dxf[] = "3{$nl}*PAPER_SPACE";
    $dxf[] = "1{$nl}";
    $dxf[] = "0{$nl}ENDBLK";
    $dxf[] = "5{$nl}28";
    $dxf[] = "330{$nl}{$hPaperBlockR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockEnd";

    // _ARCHTICK Block Definition
    $dxf[] = "0{$nl}BLOCK";
    $dxf[] = "5{$nl}2B";
    $dxf[] = "330{$nl}{$hArchTickBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockBegin";
    $dxf[] = "2{$nl}_ARCHTICK";
    $dxf[] = "70{$nl}1";
    $dxf[] = "10{$nl}0.0";
    $dxf[] = "20{$nl}0.0";
    $dxf[] = "30{$nl}0.0";
    $dxf[] = "3{$nl}_ARCHTICK";
    $dxf[] = "1{$nl}";
    $dxf[] = "0{$nl}LINE";
    $dxf[] = "5{$nl}2C";
    $dxf[] = "330{$nl}{$hArchTickBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}0";
    $dxf[] = "100{$nl}AcDbLine";
    $dxf[] = "10{$nl}-0.5";
    $dxf[] = "20{$nl}-0.5";
    $dxf[] = "30{$nl}0.0";
    $dxf[] = "11{$nl}0.5";
    $dxf[] = "21{$nl}0.5";
    $dxf[] = "31{$nl}0.0";
    $dxf[] = "0{$nl}ENDBLK";
    $dxf[] = "5{$nl}2D";
    $dxf[] = "330{$nl}{$hArchTickBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockEnd";

    $northArrowBlockHandle = dechex($hNext++);
    $northArrowBlockEndHandle = dechex($hNext++);
    $northArrowCircleHandle = dechex($hNext++);
    $northArrowStemHandle = dechex($hNext++);
    $northArrowLeftHeadHandle = dechex($hNext++);
    $northArrowRightHeadHandle = dechex($hNext++);
    $northArrowTextHandle = dechex($hNext++);
    $northArrowRadius = max(1.0, min($paperFrameWidth, $paperFrameHeight) * 0.03);
    $dxf[] = "0{$nl}BLOCK";
    $dxf[] = "5{$nl}{$northArrowBlockHandle}";
    $dxf[] = "330{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockBegin";
    $dxf[] = "2{$nl}NORTH_ARROW";
    $dxf[] = "70{$nl}0";
    $dxf[] = "10{$nl}0.0000";
    $dxf[] = "20{$nl}0.0000";
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "3{$nl}NORTH_ARROW";
    $dxf[] = "1{$nl}";

    $dxf[] = "0{$nl}CIRCLE";
    $dxf[] = "5{$nl}{$northArrowCircleHandle}";
    $dxf[] = "330{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}8";
    $dxf[] = "100{$nl}AcDbCircle";
    $dxf[] = "10{$nl}0.0000";
    $dxf[] = "20{$nl}0.0000";
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "40{$nl}" . sprintf('%.4f', $northArrowRadius);

    $dxf[] = "0{$nl}LINE";
    $dxf[] = "5{$nl}{$northArrowStemHandle}";
    $dxf[] = "330{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}8";
    $dxf[] = "100{$nl}AcDbLine";
    $dxf[] = "10{$nl}0.0000";
    $dxf[] = "20{$nl}" . sprintf('%.4f', -$northArrowRadius * 0.2);
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "11{$nl}0.0000";
    $dxf[] = "21{$nl}" . sprintf('%.4f', $northArrowRadius * 1.7);
    $dxf[] = "31{$nl}0.0000";

    $dxf[] = "0{$nl}LINE";
    $dxf[] = "5{$nl}{$northArrowLeftHeadHandle}";
    $dxf[] = "330{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}8";
    $dxf[] = "100{$nl}AcDbLine";
    $dxf[] = "10{$nl}0.0000";
    $dxf[] = "20{$nl}" . sprintf('%.4f', $northArrowRadius * 1.7);
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "11{$nl}" . sprintf('%.4f', -$northArrowRadius * 0.45);
    $dxf[] = "21{$nl}" . sprintf('%.4f', $northArrowRadius * 1.25);
    $dxf[] = "31{$nl}0.0000";

    $dxf[] = "0{$nl}LINE";
    $dxf[] = "5{$nl}{$northArrowRightHeadHandle}";
    $dxf[] = "330{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}8";
    $dxf[] = "100{$nl}AcDbLine";
    $dxf[] = "10{$nl}0.0000";
    $dxf[] = "20{$nl}" . sprintf('%.4f', $northArrowRadius * 1.7);
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "11{$nl}" . sprintf('%.4f', $northArrowRadius * 0.45);
    $dxf[] = "21{$nl}" . sprintf('%.4f', $northArrowRadius * 1.25);
    $dxf[] = "31{$nl}0.0000";

    $dxf[] = "0{$nl}TEXT";
    $dxf[] = "5{$nl}{$northArrowTextHandle}";
    $dxf[] = "330{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}8";
    $dxf[] = "100{$nl}AcDbText";
    $dxf[] = "10{$nl}0.0000";
    $dxf[] = "20{$nl}" . sprintf('%.4f', $northArrowRadius * 0.15);
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "40{$nl}" . sprintf('%.4f', max(0.5, $northArrowRadius * 0.8));
    $dxf[] = "1{$nl}B";
    $dxf[] = "7{$nl}STANDARD";
    $dxf[] = "50{$nl}0.0";
    $dxf[] = "100{$nl}AcDbText";

    $dxf[] = "0{$nl}ENDBLK";
    $dxf[] = "5{$nl}{$northArrowBlockEndHandle}";
    $dxf[] = "330{$nl}{$hNorthArrowBR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockEnd";

    foreach ($alignedDimensionDefs as $dimDef) {
        $dimLength = hypot($dimDef['x2'] - $dimDef['x1'], $dimDef['y2'] - $dimDef['y1']);
        $dxf[] = "0{$nl}BLOCK";
        $dxf[] = "5{$nl}{$dimDef['blockHandle']}";
        $dxf[] = "330{$nl}{$dimDef['blockRecordHandle']}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbBlockBegin";
        $dxf[] = "2{$nl}{$dimDef['blockName']}";
        $dxf[] = "70{$nl}1";
        $dxf[] = "10{$nl}0.0000";
        $dxf[] = "20{$nl}0.0000";
        $dxf[] = "30{$nl}0.0000";
        $dxf[] = "3{$nl}{$dimDef['blockName']}";
        $dxf[] = "1{$nl}";

        $dxf[] = "0{$nl}CIRCLE";
        $dxf[] = "5{$nl}{$dimDef['circle1Handle']}";
        $dxf[] = "330{$nl}{$dimDef['blockRecordHandle']}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbCircle";
        $dxf[] = "10{$nl}0.0000";
        $dxf[] = "20{$nl}0.0000";
        $dxf[] = "30{$nl}0.0000";
        $dxf[] = "40{$nl}" . sprintf('%.4f', $dimDef['circleRadius']);

        $dxf[] = "0{$nl}CIRCLE";
        $dxf[] = "5{$nl}{$dimDef['circle2Handle']}";
        $dxf[] = "330{$nl}{$dimDef['blockRecordHandle']}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbCircle";
        $dxf[] = "10{$nl}" . sprintf('%.4f', $dimLength);
        $dxf[] = "20{$nl}0.0000";
        $dxf[] = "30{$nl}0.0000";
        $dxf[] = "40{$nl}" . sprintf('%.4f', $dimDef['circleRadius']);

        $dxf[] = "0{$nl}MTEXT";
        $dxf[] = "5{$nl}{$dimDef['textHandle']}";
        $dxf[] = "330{$nl}{$dimDef['blockRecordHandle']}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbMText";
        $dxf[] = "10{$nl}" . sprintf('%.4f', $dimLength / 2);
        $dxf[] = "20{$nl}" . sprintf('%.4f', max(0.5, $dimDef['textHeight'] * 1.125));
        $dxf[] = "30{$nl}0.0000";
        $dxf[] = "40{$nl}" . sprintf('%.4f', $dimDef['textHeight']);
        $dxf[] = "41{$nl}" . sprintf('%.4f', $dimDef['textWidth']);
        $dxf[] = "1{$nl}" . $encodeDxfUnicodeText($dimDef['label']);
        $dxf[] = "7{$nl}STANDARD";
        $dxf[] = "50{$nl}0.0";
        $dxf[] = "71{$nl}5";
        $dxf[] = "72{$nl}5";

        $dxf[] = "0{$nl}ENDBLK";
        $dxf[] = "5{$nl}{$dimDef['blockEndHandle']}";
        $dxf[] = "330{$nl}{$dimDef['blockRecordHandle']}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbBlockEnd";
    }

    $dxf[] = "0{$nl}ENDSEC";

    $addLine = static function (array &$dxf, string $nl, int &$coordHandle, float $x1, float $y1, float $x2, float $y2) use ($hModelBlockR): void {
        $dxf[] = "0{$nl}LINE";
        $dxf[] = "5{$nl}" . dechex($coordHandle++);
        $dxf[] = "330{$nl}{$hModelBlockR}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbLine";
        $dxf[] = "10{$nl}" . sprintf('%.4f', $x1);
        $dxf[] = "20{$nl}" . sprintf('%.4f', $y1);
        $dxf[] = "30{$nl}0.0000";
        $dxf[] = "11{$nl}" . sprintf('%.4f', $x2);
        $dxf[] = "21{$nl}" . sprintf('%.4f', $y2);
        $dxf[] = "31{$nl}0.0000";
    };

    $addMText = static function (array &$dxf, string $nl, int &$coordHandle, float $x, float $y, float $height, float $width, string $label, float $rotation = 0.0, int $attachment = 1) use ($hModelBlockR, $encodeDxfUnicodeText): void {
        $dxf[] = "0{$nl}MTEXT";
        $dxf[] = "5{$nl}" . dechex($coordHandle++);
        $dxf[] = "330{$nl}{$hModelBlockR}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbMText";
        $dxf[] = "10{$nl}" . sprintf('%.4f', $x);
        $dxf[] = "20{$nl}" . sprintf('%.4f', $y);
        $dxf[] = "30{$nl}0.0000";
        $dxf[] = "40{$nl}" . sprintf('%.4f', $height);
        $dxf[] = "41{$nl}" . sprintf('%.4f', $width);
        $dxf[] = "1{$nl}" . $encodeDxfUnicodeText($label);
        $dxf[] = "7{$nl}STANDARD";
        $dxf[] = "50{$nl}" . sprintf('%.1f', $rotation);
        $dxf[] = "71{$nl}{$attachment}";
        $dxf[] = "72{$nl}5";
    };

    $addWipeout = static function (array &$dxf, string $nl, int &$coordHandle, float $x, float $y, float $width, float $height, float $rotation = 0.0) use ($hModelBlockR): void {
        $halfW = $width / 2.0;
        $halfH = $height / 2.0;
        $theta = deg2rad($rotation);
        $ux = $width * cos($theta);
        $uy = $width * sin($theta);
        $vx = -$height * sin($theta);
        $vy = $height * cos($theta);
        $dxf[] = "0{$nl}WIPEOUT";
        $dxf[] = "5{$nl}" . dechex($coordHandle++);
        $dxf[] = "330{$nl}{$hModelBlockR}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "100{$nl}AcDbWipeout";
        $dxf[] = "90{$nl}0";
        $dxf[] = "10{$nl}" . sprintf('%.4f', $x);
        $dxf[] = "20{$nl}" . sprintf('%.4f', $y);
        $dxf[] = "30{$nl}0.0000";
        $dxf[] = "11{$nl}" . sprintf('%.4f', $ux);
        $dxf[] = "21{$nl}" . sprintf('%.4f', $uy);
        $dxf[] = "31{$nl}0.0000";
        $dxf[] = "12{$nl}" . sprintf('%.4f', $vx);
        $dxf[] = "22{$nl}" . sprintf('%.4f', $vy);
        $dxf[] = "32{$nl}0.0000";
        $dxf[] = "13{$nl}1.0000";
        $dxf[] = "23{$nl}1.0000";
        $dxf[] = "340{$nl}0";
        $dxf[] = "70{$nl}7";
        $dxf[] = "280{$nl}1";
        $dxf[] = "281{$nl}50";
        $dxf[] = "282{$nl}50";
        $dxf[] = "283{$nl}0";
        $dxf[] = "360{$nl}0";
        $dxf[] = "71{$nl}2";
        $dxf[] = "91{$nl}5";
        $dxf[] = "14{$nl}" . sprintf('%.4f', -$halfW);
        $dxf[] = "24{$nl}" . sprintf('%.4f', $halfH);
        $dxf[] = "14{$nl}" . sprintf('%.4f', $halfW);
        $dxf[] = "24{$nl}" . sprintf('%.4f', $halfH);
        $dxf[] = "14{$nl}" . sprintf('%.4f', $halfW);
        $dxf[] = "24{$nl}" . sprintf('%.4f', -$halfH);
        $dxf[] = "14{$nl}" . sprintf('%.4f', -$halfW);
        $dxf[] = "24{$nl}" . sprintf('%.4f', -$halfH);
        $dxf[] = "14{$nl}" . sprintf('%.4f', -$halfW);
        $dxf[] = "24{$nl}" . sprintf('%.4f', $halfH);
    };

    // 5. ENTITIES SECTION
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}ENTITIES";

    if (is_array($entities)) {
        foreach ($entities as $index => $ent) {
            $color = hexToACI($ent['color'] ?? '#ffffff');
            $type = $ent['type'] ?? '';
            $handle = dechex($hNext++);

            if ($type === 'line') {
                $dxf[] = "0{$nl}LINE";
                $dxf[] = "5{$nl}{$handle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbLine";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$ent['x1']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$ent['y1']);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "11{$nl}" . sprintf('%.4f', (float)$ent['x2']);
                $dxf[] = "21{$nl}" . sprintf('%.4f', (float)$ent['y2']);
                $dxf[] = "31{$nl}0.0000";
            }
            elseif ($type === 'rect') {
                $dxf[] = "0{$nl}LWPOLYLINE";
                $dxf[] = "5{$nl}{$handle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbPolyline";
                $dxf[] = "90{$nl}4";
                $dxf[] = "70{$nl}1";
                $dxf[] = "38{$nl}0.0000";
                $pts = [
                    [(float)$ent['x'], (float)$ent['y']],
                    [(float)$ent['x'] + (float)$ent['w'], (float)$ent['y']],
                    [(float)$ent['x'] + (float)$ent['w'], (float)$ent['y'] + (float)$ent['h']],
                    [(float)$ent['x'], (float)$ent['y'] + (float)$ent['h']]
                ];
                foreach ($pts as $p) {
                    $dxf[] = "10{$nl}" . sprintf('%.4f', $p[0]);
                    $dxf[] = "20{$nl}" . sprintf('%.4f', $p[1]);
                }
            }
            elseif ($type === 'pline') {
                $pts = $ent['points'] ?? [];
                if (count($pts) > 0) {
                    $dxf[] = "0{$nl}LWPOLYLINE";
                    $dxf[] = "5{$nl}{$handle}";
                    $dxf[] = "330{$nl}{$hModelBlockR}";
                    $dxf[] = "100{$nl}AcDbEntity";
                    $dxf[] = "8{$nl}0";
                    $dxf[] = "62{$nl}{$color}";
                    $dxf[] = "100{$nl}AcDbPolyline";
                    $dxf[] = "90{$nl}" . count($pts);
                    $dxf[] = "70{$nl}" . (!empty($ent['closed']) ? '1' : '0');
                    $elevation = sprintf('%.4f', (float)($ent['elevation'] ?? 0));
                    $dxf[] = "38{$nl}{$elevation}";
                    foreach ($pts as $p) {
                        $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$p['x']);
                        $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$p['y']);
                    }
                }
            }
            elseif ($type === 'circle') {
                $dxf[] = "0{$nl}CIRCLE";
                $dxf[] = "5{$nl}{$handle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbCircle";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$ent['cx']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$ent['cy']);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "40{$nl}" . sprintf('%.4f', (float)$ent['r']);
            }
            elseif ($type === 'arc') {
                $cx = (float)$ent['cx'];
                $cy = (float)$ent['cy'];
                $r  = (float)$ent['r'];
                $startAzi = (float)$ent['startAzi'];
                $endAzi   = (float)$ent['endAzi'];

                $p1x = $cx + $r * sin($startAzi);
                $p1y = $cy + $r * cos($startAzi);
                $p2x = $cx + $r * sin($endAzi);
                $p2y = $cy + $r * cos($endAzi);

                $startMathDeg = fmod(rad2deg(atan2($p1y - $cy, $p1x - $cx)) + 360, 360);
                $endMathDeg   = fmod(rad2deg(atan2($p2y - $cy, $p2x - $cx)) + 360, 360);

                $dxf[] = "0{$nl}ARC";
                $dxf[] = "5{$nl}{$handle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbCircle";
                $dxf[] = "10{$nl}" . sprintf('%.4f', $cx);
                $dxf[] = "20{$nl}" . sprintf('%.4f', $cy);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "40{$nl}" . sprintf('%.4f', $r);
                $dxf[] = "100{$nl}AcDbArc";
                $dxf[] = "50{$nl}" . sprintf('%.4f', $endMathDeg);
                $dxf[] = "51{$nl}" . sprintf('%.4f', $startMathDeg);
            }
            elseif ($type === 'ellipse') {
                $dxf[] = "0{$nl}ELLIPSE";
                $dxf[] = "5{$nl}{$handle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbEllipse";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$ent['cx']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$ent['cy']);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "11{$nl}" . sprintf('%.4f', (float)$ent['rx']);
                $dxf[] = "21{$nl}0.0000";
                $dxf[] = "31{$nl}0.0000";
                $ratio = (float)$ent['ry'] / max(0.0001, (float)$ent['rx']);
                $dxf[] = "40{$nl}" . sprintf('%.6f', $ratio);
                $dxf[] = "41{$nl}0.0000";
                $dxf[] = "42{$nl}" . sprintf('%.6f', 2 * M_PI);
            }
            elseif ($type === 'point') {
                $pointX = (float)$ent['x'];
                $pointY = (float)$ent['y'];
                $pointZ = (float)($ent['z'] ?? 0);
                $pointName = trim((string)($ent['name'] ?? ''));
                $pointLabel = $pointName !== ''
                    ? $pointName . ':' . sprintf('%.3f', $pointZ)
                    : sprintf('%.3f', $pointZ);

                $circleHandle = $handle;
                $textHandle = dechex($hNext++);

                $dxf[] = "0{$nl}CIRCLE";
                $dxf[] = "5{$nl}{$circleHandle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbCircle";
                $dxf[] = "10{$nl}" . sprintf('%.4f', $pointX);
                $dxf[] = "20{$nl}" . sprintf('%.4f', $pointY);
                $dxf[] = "30{$nl}" . sprintf('%.4f', $pointZ);
                $dxf[] = "40{$nl}" . sprintf('%.4f', $pointMarkerRadius);

                $dxf[] = "0{$nl}MTEXT";
                $dxf[] = "5{$nl}{$textHandle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbMText";
                $dxf[] = "10{$nl}" . sprintf('%.4f', $pointX + $pointTextOffset);
                $dxf[] = "20{$nl}" . sprintf('%.4f', $pointY + $pointTextOffset);
                $dxf[] = "30{$nl}" . sprintf('%.4f', $pointZ);
                $dxf[] = "40{$nl}" . sprintf('%.4f', $pointTextHeight);
                $dxf[] = "41{$nl}" . sprintf('%.4f', $measureTextWidth($pointLabel, $pointTextHeight));
                $dxf[] = "1{$nl}" . $encodeDxfUnicodeText($pointLabel);
                $dxf[] = "7{$nl}STANDARD";
                $dxf[] = "71{$nl}1";
                $dxf[] = "72{$nl}5";
            } elseif ($type === 'text') {
                $text = (string)($ent['text'] ?? '');
                if (($ent['textMode'] ?? 'one-line') !== 'multiline') {
                    $text = preg_replace('/[\r\n]+/', ' ', $text);
                }
                $height = max(0.001, (float)($ent['height'] ?? $ent['size'] ?? 0.1));
                $justify = (string)($ent['justify'] ?? 'middle-center');
                $legacyJustify = ['left' => 'middle-left', 'center' => 'middle-center', 'right' => 'middle-right'];
                $justify = $legacyJustify[$justify] ?? $justify;
                $attachment = [
                    'top-left' => 1, 'top-center' => 2, 'top-right' => 3,
                    'middle-left' => 4, 'middle-center' => 5, 'middle-right' => 6,
                    'bottom-left' => 7, 'bottom-center' => 8, 'bottom-right' => 9
                ][$justify] ?? 5;
                $textWidth = $measureTextWidth($text, $height);
                $dxf[] = "0{$nl}MTEXT";
                $dxf[] = "5{$nl}{$handle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbMText";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)($ent['x'] ?? 0));
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)($ent['y'] ?? 0));
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "40{$nl}" . sprintf('%.4f', $height);
                $dxf[] = "41{$nl}" . sprintf('%.4f', $textWidth);
                $dxf[] = "1{$nl}" . $encodeDxfUnicodeText($text);
                $dxf[] = "7{$nl}STANDARD";
                $dxf[] = "50{$nl}" . sprintf('%.1f', (float)($ent['rotation'] ?? 0));
                $dxf[] = "71{$nl}{$attachment}";
                $dxf[] = "72{$nl}5";
            } elseif ($type === 'dimension' && ($ent['kind'] ?? 'distance') !== 'angle') {
                $dx = (float)$ent['x2'] - (float)$ent['x1'];
                $dy = (float)$ent['y2'] - (float)$ent['y1'];
                $length = hypot($dx, $dy);
                if ($length < 1e-9) continue;
                $label = number_format($length, 2, '.', '');
                // Use pre-calculated textX and textY from JSON if available, otherwise use midpoint
                $textPointX = isset($ent['textX']) ? (float)$ent['textX'] : ((float)$ent['x1'] + (float)$ent['x2']) / 2;
                $textPointY = isset($ent['textY']) ? (float)$ent['textY'] : ((float)$ent['y1'] + (float)$ent['y2']) / 2;
                $textRotation = rad2deg(atan2($dy, $dx));
                if ($textRotation > 90 || $textRotation < -90) $textRotation += 180;
                $markerRadius = 0.3;
                $wipeoutWidth = $measureTextWidth($label, $pointTextHeight) + max(0.2, $pointTextHeight * 0.8);
                $wipeoutHeight = max($pointTextHeight * 1.6, $pointTextHeight + 0.2);

                $dxf[] = "0{$nl}CIRCLE";
                $dxf[] = "5{$nl}" . dechex($hNext++);
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbCircle";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$ent['x1']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$ent['y1']);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "40{$nl}" . sprintf('%.4f', $markerRadius);

                $dxf[] = "0{$nl}CIRCLE";
                $dxf[] = "5{$nl}" . dechex($hNext++);
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbCircle";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$ent['x2']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$ent['y2']);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "40{$nl}" . sprintf('%.4f', $markerRadius);

                $addWipeout($dxf, $nl, $hNext, $textPointX, $textPointY, $wipeoutWidth, $wipeoutHeight, $textRotation);

                $dxf[] = "0{$nl}MTEXT";
                $dxf[] = "5{$nl}" . dechex($hNext++);
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbMText";
                $dxf[] = "10{$nl}" . sprintf('%.4f', $textPointX);
                $dxf[] = "20{$nl}" . sprintf('%.4f', $textPointY);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "40{$nl}" . sprintf('%.4f', $pointTextHeight);
                $dxf[] = "41{$nl}" . sprintf('%.4f', $textWidth);
                $dxf[] = "1{$nl}" . $encodeDxfUnicodeText($label);
                $dxf[] = "7{$nl}STANDARD";
                $dxf[] = "50{$nl}" . sprintf('%.1f', $textRotation);
                $dxf[] = "71{$nl}5";
                $dxf[] = "72{$nl}5";
            }

            $hatchLoops = getDXFHatchBoundaryLoops($ent);
            if ($hatchLoops) {
                $hatch = is_array($ent['hatch'] ?? null) ? $ent['hatch'] : [];
                $hatchColor = hexToACI($hatch['color'] ?? ($ent['color'] ?? '#ffffff'));
                $hatchHandle = dechex($hNext++);
                $firstPoint = $hatchLoops[0][0];
                $dxf[] = "0{$nl}HATCH";
                $dxf[] = "5{$nl}{$hatchHandle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$hatchColor}";
                $dxf[] = "100{$nl}AcDbHatch";
                $dxf[] = "10{$nl}" . sprintf('%.4f', $firstPoint['x']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', $firstPoint['y']);
                $dxf[] = "30{$nl}0.0000";
                $dxf[] = "210{$nl}0.0";
                $dxf[] = "220{$nl}0.0";
                $dxf[] = "230{$nl}1.0";
                $dxf[] = "2{$nl}ANSI31";
                $dxf[] = "70{$nl}0";
                $dxf[] = "71{$nl}0";
                $dxf[] = "91{$nl}" . count($hatchLoops);
                foreach ($hatchLoops as $loop) {
                    if (count($loop) < 3) continue;
                    $dxf[] = "92{$nl}2";
                    $dxf[] = "72{$nl}0";
                    $dxf[] = "73{$nl}1";
                    $dxf[] = "93{$nl}" . count($loop);
                    foreach ($loop as $point) {
                        $dxf[] = "10{$nl}" . sprintf('%.4f', $point['x']);
                        $dxf[] = "20{$nl}" . sprintf('%.4f', $point['y']);
                    }
                    $dxf[] = "97{$nl}0";
                }
                $dxf[] = "75{$nl}0";
                $dxf[] = "76{$nl}1";
                $dxf[] = "52{$nl}" . sprintf('%.4f', (float)($hatch['angle'] ?? 45));
                $dxf[] = "41{$nl}" . sprintf('%.4f', max(0.0001, (float)($hatch['spacing'] ?? 10)));
                $dxf[] = "77{$nl}0";
                $dxf[] = "78{$nl}1";
                $dxf[] = "53{$nl}" . sprintf('%.4f', (float)($hatch['angle'] ?? 45));
                $dxf[] = "43{$nl}0.0";
                $dxf[] = "44{$nl}0.0";
                $dxf[] = "45{$nl}1.0";
                $dxf[] = "46{$nl}1.0";
                $dxf[] = "79{$nl}0";
                $dxf[] = "98{$nl}0";
            }
        }
    }

    $gridBounds = getDXFGridBounds($entities);
    if ($gridBounds) {
        $labelHeight = 0.0025 * max(1, (float)$printScale);
        $coordStep = 20.0;
        $coordTextWidth = static fn(string $label): float => $measureTextWidth($label, $labelHeight);
        $drawingWidth = max(0.0, (float)$gridBounds['maxX'] - (float)$gridBounds['minX']);
        $drawingHeight = max(0.0, (float)$gridBounds['maxY'] - (float)$gridBounds['minY']);
        $gapX = max(0.0, $paperFrameWidth - $drawingWidth);
        $gapY = max(0.0, $paperFrameHeight - $drawingHeight);
        $coordInsetX = $gapX / 4.0;
        $coordInsetY = $gapY / 4.0;
        $coordLeft = (float)$gridBounds['minX'] - $coordInsetX;
        $coordRight = (float)$gridBounds['maxX'] + $coordInsetX;
        $coordBottom = (float)$gridBounds['minY'] - $coordInsetY;
        $coordTop = (float)$gridBounds['maxY'] + $coordInsetY;
        if ($coordLeft > $coordRight) { $coordLeft = $coordRight = (float)$gridBounds['minX']; }
        if ($coordBottom > $coordTop) { $coordBottom = $coordTop = (float)$gridBounds['minY']; }
        $labelOffset = max(0.6, $labelHeight * 0.9);
        $coordHandle = $hNext++;

        $dxf[] = "0{$nl}LWPOLYLINE";
        $dxf[] = "5{$nl}" . dechex($coordHandle++);
        $dxf[] = "330{$nl}{$hModelBlockR}";
        $dxf[] = "100{$nl}AcDbEntity";
        $dxf[] = "8{$nl}0";
        $dxf[] = "62{$nl}8";
        $dxf[] = "100{$nl}AcDbPolyline";
        $dxf[] = "90{$nl}4";
        $dxf[] = "70{$nl}1";
        $dxf[] = "10{$nl}" . sprintf('%.4f', $coordLeft);
        $dxf[] = "20{$nl}" . sprintf('%.4f', $coordBottom);
        $dxf[] = "10{$nl}" . sprintf('%.4f', $coordRight);
        $dxf[] = "20{$nl}" . sprintf('%.4f', $coordBottom);
        $dxf[] = "10{$nl}" . sprintf('%.4f', $coordRight);
        $dxf[] = "20{$nl}" . sprintf('%.4f', $coordTop);
        $dxf[] = "10{$nl}" . sprintf('%.4f', $coordLeft);
        $dxf[] = "20{$nl}" . sprintf('%.4f', $coordTop);

        $addText = static function (array &$dxf, string $nl, int &$coordHandle, float $x, float $y, float $height, string $label, float $rotation = 0.0, int $hAlign = 0) use ($hModelBlockR, $encodeDxfText): void {
            $dxf[] = "0{$nl}TEXT";
            $dxf[] = "5{$nl}" . dechex($coordHandle++);
            $dxf[] = "330{$nl}{$hModelBlockR}";
            $dxf[] = "100{$nl}AcDbEntity";
            $dxf[] = "8{$nl}0";
            $dxf[] = "100{$nl}AcDbText";
            $dxf[] = "10{$nl}" . sprintf('%.4f', $x);
            $dxf[] = "20{$nl}" . sprintf('%.4f', $y);
            $dxf[] = "30{$nl}0.0000";
            $dxf[] = "40{$nl}" . sprintf('%.4f', $height);
            $dxf[] = "1{$nl}" . $encodeDxfText($label);
            $dxf[] = "50{$nl}" . sprintf('%.1f', $rotation);
            $dxf[] = "7{$nl}STANDARD";
            if ($hAlign !== 0) {
                $dxf[] = "72{$nl}{$hAlign}";
                $dxf[] = "11{$nl}" . sprintf('%.4f', $x);
                $dxf[] = "21{$nl}" . sprintf('%.4f', $y);
                $dxf[] = "31{$nl}0.0000";
            }
            $dxf[] = "100{$nl}AcDbText";
        };

        $formatActual = static fn(float $value): string => number_format($value, 2, '.', '');

        $xLabels = [$coordLeft];
        $xStart = ceil(($coordLeft + 1e-9) / $coordStep) * $coordStep;
        for ($x = $xStart; $x < $coordRight - 1e-9; $x += $coordStep) {
            if ($x > $coordLeft + 1e-9 && $x < $coordRight - 1e-9) $xLabels[] = $x;
        }
        $xLabels[] = $coordRight;
        $xLabels = array_values(array_unique(array_map(static fn($v) => round($v, 6), $xLabels)));

        $yLabels = [$coordBottom];
        $yStart = ceil(($coordBottom + 1e-9) / $coordStep) * $coordStep;
        for ($y = $yStart; $y < $coordTop - 1e-9; $y += $coordStep) {
            if ($y > $coordBottom + 1e-9 && $y < $coordTop - 1e-9) $yLabels[] = $y;
        }
        $yLabels[] = $coordTop;
        $yLabels = array_values(array_unique(array_map(static fn($v) => round($v, 6), $yLabels)));

        foreach ($xLabels as $x) {
            $isEdge = abs($x - $coordLeft) < 1e-6 || abs($x - $coordRight) < 1e-6;
            if ($isEdge) continue;
            $label = (string)((int)round($x));
            $addLine($dxf, $nl, $coordHandle, (float)$x, $coordBottom, (float)$x, $coordBottom + $labelOffset);
            $addMText($dxf, $nl, $coordHandle, (float)$x, $coordBottom + ($labelOffset * 0.5) + 0.3, $labelHeight, $coordTextWidth($label), $label, 90.0, 4);
            $addLine($dxf, $nl, $coordHandle, (float)$x, $coordTop, (float)$x, $coordTop - $labelOffset);
        }

        foreach ($yLabels as $y) {
            $isEdge = abs($y - $coordBottom) < 1e-6 || abs($y - $coordTop) < 1e-6;
            if ($isEdge) continue;
            $label = (string)((int)round($y));
            $yTickLen = 0.6;
            $addLine($dxf, $nl, $coordHandle, $coordLeft, (float)$y, $coordLeft + $yTickLen, (float)$y);
            $addMText($dxf, $nl, $coordHandle, $coordLeft + $yTickLen, (float)$y, $labelHeight, $coordTextWidth($label), $label, 0.0, 4);
            $addLine($dxf, $nl, $coordHandle, $coordRight, (float)$y, $coordRight - $yTickLen, (float)$y);
        }

        $crossSize = 0.6;
        $crossHalf = $crossSize * 0.5;
        foreach ($xLabels as $x) {
            if (abs($x - $coordLeft) < 1e-6 || abs($x - $coordRight) < 1e-6) continue;
            foreach ($yLabels as $y) {
                if (abs($y - $coordBottom) < 1e-6 || abs($y - $coordTop) < 1e-6) continue;
                $addLine($dxf, $nl, $coordHandle, (float)$x - $crossHalf, (float)$y, (float)$x + $crossHalf, (float)$y);
                $addLine($dxf, $nl, $coordHandle, (float)$x, (float)$y - $crossHalf, (float)$x, (float)$y + $crossHalf);
            }
        }

        $hNext = max($hNext, $coordHandle);
    }

    $frameHandle = dechex($hNext++);
    $dxf[] = "0{$nl}LWPOLYLINE";
    $dxf[] = "5{$nl}{$frameHandle}";
    $dxf[] = "330{$nl}{$hModelBlockR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}8";
    $dxf[] = "100{$nl}AcDbPolyline";
    $dxf[] = "90{$nl}4";
    $dxf[] = "70{$nl}1";
    $dxf[] = "10{$nl}" . sprintf('%.4f', $paperFrameLeft);
    $dxf[] = "20{$nl}" . sprintf('%.4f', $paperFrameBottom);
    $dxf[] = "10{$nl}" . sprintf('%.4f', $paperFrameRight);
    $dxf[] = "20{$nl}" . sprintf('%.4f', $paperFrameBottom);
    $dxf[] = "10{$nl}" . sprintf('%.4f', $paperFrameRight);
    $dxf[] = "20{$nl}" . sprintf('%.4f', $paperFrameTop);
    $dxf[] = "10{$nl}" . sprintf('%.4f', $paperFrameLeft);
    $dxf[] = "20{$nl}" . sprintf('%.4f', $paperFrameTop);

    $paperLabel = sprintf(
        '%s %d mm x %d mm | %.2f m x %.2f m',
        $paperSpec['name'],
        (float)$paperSpec['widthMm'],
        (float)$paperSpec['heightMm'],
        $paperFrameWidth,
        $paperFrameHeight
    );
    $paperLabelHeight = max(0.5, 0.0018 * $scale);
    $paperLabelLength = function_exists('mb_strlen') ? mb_strlen($paperLabel, 'UTF-8') : strlen($paperLabel);
    $paperLabelWidth = $measureTextWidth($paperLabel, $paperLabelHeight);
    $paperLabelX = $paperFrameLeft + ($paperFrameWidth / 2);
    $paperLabelY = $paperFrameTop - max(0.8, $paperFrameHeight * 0.035);
    $paperLabelHandle = dechex($hNext++);
    $dxf[] = "0{$nl}MTEXT";
    $dxf[] = "5{$nl}{$paperLabelHandle}";
    $dxf[] = "330{$nl}{$hModelBlockR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "62{$nl}8";
    $dxf[] = "100{$nl}AcDbMText";
    $dxf[] = "10{$nl}" . sprintf('%.4f', $paperLabelX);
    $dxf[] = "20{$nl}" . sprintf('%.4f', $paperLabelY);
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "40{$nl}" . sprintf('%.4f', $paperLabelHeight);
    $dxf[] = "41{$nl}" . sprintf('%.4f', $paperLabelWidth);
    $dxf[] = "1{$nl}" . $encodeDxfUnicodeText($paperLabel);
    $dxf[] = "7{$nl}STANDARD";
    $dxf[] = "50{$nl}0.0";
    $dxf[] = "71{$nl}5";
    $dxf[] = "72{$nl}5";

    $northRadius = max(1.0, min($paperFrameWidth, $paperFrameHeight) * 0.03);
    $northCx = $paperFrameLeft + $northRadius * 2.2;
    $northCy = $paperFrameTop - $northRadius * 2.2;

    $northInsertHandle = dechex($hNext++);
    $dxf[] = "0{$nl}INSERT";
    $dxf[] = "5{$nl}{$northInsertHandle}";
    $dxf[] = "330{$nl}{$hModelBlockR}";
    $dxf[] = "100{$nl}AcDbEntity";
    $dxf[] = "8{$nl}0";
    $dxf[] = "100{$nl}AcDbBlockReference";
    $dxf[] = "2{$nl}NORTH_ARROW";
    $dxf[] = "10{$nl}" . sprintf('%.4f', $northCx);
    $dxf[] = "20{$nl}" . sprintf('%.4f', $northCy);
    $dxf[] = "30{$nl}0.0000";
    $dxf[] = "41{$nl}1.0000";
    $dxf[] = "42{$nl}1.0000";
    $dxf[] = "43{$nl}1.0000";
    $dxf[] = "50{$nl}0.0";

    $dxf[] = "0{$nl}ENDSEC";

    // 6. OBJECTS SECTION
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}OBJECTS";

    // Root Dictionary
    $dxf[] = "0{$nl}DICTIONARY";
    $dxf[] = "5{$nl}{$hRootDict}";
    $dxf[] = "100{$nl}AcDbDictionary";
    $dxf[] = "281{$nl}1";
    $dxf[] = "3{$nl}ACAD_GROUP";
    $dxf[] = "350{$nl}{$hGroupDict}";
    $dxf[] = "3{$nl}ACAD_LAYOUT";
    $dxf[] = "350{$nl}{$hLayoutDict}";
    $dxf[] = "3{$nl}ACAD_PLOTSTYLENAME";
    $dxf[] = "350{$nl}{$hPlotStyles}";

    // ACAD_GROUP Dictionary
    $dxf[] = "0{$nl}DICTIONARY";
    $dxf[] = "5{$nl}{$hGroupDict}";
    $dxf[] = "102{$nl}{ACAD_REACTORS";
    $dxf[] = "330{$nl}{$hRootDict}";
    $dxf[] = "102{$nl}}";
    $dxf[] = "100{$nl}AcDbDictionary";
    $dxf[] = "281{$nl}1";

    // ACAD_LAYOUT Dictionary
    $dxf[] = "0{$nl}DICTIONARY";
    $dxf[] = "5{$nl}{$hLayoutDict}";
    $dxf[] = "102{$nl}{ACAD_REACTORS";
    $dxf[] = "330{$nl}{$hRootDict}";
    $dxf[] = "102{$nl}}";
    $dxf[] = "100{$nl}AcDbDictionary";
    $dxf[] = "281{$nl}1";
    $dxf[] = "3{$nl}Model";
    $dxf[] = "350{$nl}{$hModelLayout}";
    $dxf[] = "3{$nl}Layout1";
    $dxf[] = "350{$nl}{$hPaperLayout}";

    // Model Layout Object
    $dxf[] = "0{$nl}LAYOUT";
    $dxf[] = "5{$nl}{$hModelLayout}";
    $dxf[] = "102{$nl}{ACAD_REACTORS";
    $dxf[] = "330{$nl}{$hLayoutDict}";
    $dxf[] = "102{$nl}}";
    $dxf[] = "100{$nl}AcDbPlotSettings";
    $dxf[] = "1{$nl}";
    $dxf[] = "2{$nl}none_device";
    $dxf[] = "4{$nl}";
    $dxf[] = "6{$nl}";
    $dxf[] = "40{$nl}0.0";
    $dxf[] = "41{$nl}0.0";
    $dxf[] = "42{$nl}0.0";
    $dxf[] = "43{$nl}0.0";
    $dxf[] = "44{$nl}0.0";
    $dxf[] = "45{$nl}0.0";
    $dxf[] = "46{$nl}0.0";
    $dxf[] = "47{$nl}0.0";
    $dxf[] = "48{$nl}0.0";
    $dxf[] = "49{$nl}0.0";
    $dxf[] = "140{$nl}0.0";
    $dxf[] = "141{$nl}0.0";
    $dxf[] = "142{$nl}1.0";
    $dxf[] = "143{$nl}1.0";
    $dxf[] = "70{$nl}1712";
    $dxf[] = "72{$nl}0";
    $dxf[] = "73{$nl}0";
    $dxf[] = "74{$nl}5";
    $dxf[] = "75{$nl}16";
    $dxf[] = "76{$nl}0";
    $dxf[] = "77{$nl}2";
    $dxf[] = "78{$nl}300";
    $dxf[] = "147{$nl}1.0";
    $dxf[] = "148{$nl}0.0";
    $dxf[] = "149{$nl}0.0";
    $dxf[] = "100{$nl}AcDbLayout";
    $dxf[] = "1{$nl}Model";
    $dxf[] = "70{$nl}1";
    $dxf[] = "71{$nl}0";
    $dxf[] = "10{$nl}0.0";
    $dxf[] = "20{$nl}0.0";
    $dxf[] = "11{$nl}12.0";
    $dxf[] = "21{$nl}9.0";
    $dxf[] = "12{$nl}0.0";
    $dxf[] = "22{$nl}0.0";
    $dxf[] = "32{$nl}0.0";
    $dxf[] = "14{$nl}0.0";
    $dxf[] = "24{$nl}0.0";
    $dxf[] = "34{$nl}0.0";
    $dxf[] = "15{$nl}0.0";
    $dxf[] = "25{$nl}0.0";
    $dxf[] = "35{$nl}0.0";
    $dxf[] = "146{$nl}0.0";
    $dxf[] = "13{$nl}0.0";
    $dxf[] = "23{$nl}0.0";
    $dxf[] = "33{$nl}0.0";
    $dxf[] = "16{$nl}1.0";
    $dxf[] = "26{$nl}0.0";
    $dxf[] = "36{$nl}0.0";
    $dxf[] = "17{$nl}0.0";
    $dxf[] = "27{$nl}1.0";
    $dxf[] = "37{$nl}0.0";
    $dxf[] = "76{$nl}0";
    $dxf[] = "330{$nl}{$hModelBlockR}";

    // Layout1 Paper Space Object
    $dxf[] = "0{$nl}LAYOUT";
    $dxf[] = "5{$nl}{$hPaperLayout}";
    $dxf[] = "102{$nl}{ACAD_REACTORS";
    $dxf[] = "330{$nl}{$hLayoutDict}";
    $dxf[] = "102{$nl}}";
    $dxf[] = "100{$nl}AcDbPlotSettings";
    $dxf[] = "1{$nl}";
    $dxf[] = "2{$nl}none_device";
    $dxf[] = "4{$nl}{$paperLayoutName}";
    $dxf[] = "6{$nl}";
    $dxf[] = "40{$nl}5.7";
    $dxf[] = "41{$nl}5.7";
    $dxf[] = "42{$nl}5.7";
    $dxf[] = "43{$nl}5.7";
    $dxf[] = "44{$nl}" . sprintf('%.1f', (float)$paperSpec['widthMm']);
    $dxf[] = "45{$nl}" . sprintf('%.1f', (float)$paperSpec['heightMm']);
    $dxf[] = "46{$nl}0.0";
    $dxf[] = "47{$nl}0.0";
    $dxf[] = "48{$nl}0.0";
    $dxf[] = "49{$nl}0.0";
    $dxf[] = "140{$nl}0.0";
    $dxf[] = "141{$nl}0.0";
    $dxf[] = "142{$nl}1.0";
    $dxf[] = "143{$nl}1.0";
    $dxf[] = "70{$nl}688";
    $dxf[] = "72{$nl}0";
    $dxf[] = "73{$nl}0";
    $dxf[] = "74{$nl}5";
    $dxf[] = "75{$nl}16";
    $dxf[] = "76{$nl}0";
    $dxf[] = "77{$nl}2";
    $dxf[] = "78{$nl}300";
    $dxf[] = "147{$nl}1.0";
    $dxf[] = "148{$nl}0.0";
    $dxf[] = "149{$nl}0.0";
    $dxf[] = "100{$nl}AcDbLayout";
    $dxf[] = "1{$nl}Layout1";
    $dxf[] = "70{$nl}1";
    $dxf[] = "71{$nl}1";
    $dxf[] = "10{$nl}0.0";
    $dxf[] = "20{$nl}0.0";
    $dxf[] = "11{$nl}" . sprintf('%.1f', (float)$paperSpec['widthMm']);
    $dxf[] = "21{$nl}" . sprintf('%.1f', (float)$paperSpec['heightMm']);
    $dxf[] = "12{$nl}0.0";
    $dxf[] = "22{$nl}0.0";
    $dxf[] = "32{$nl}0.0";
    $dxf[] = "14{$nl}0.0";
    $dxf[] = "24{$nl}0.0";
    $dxf[] = "34{$nl}0.0";
    $dxf[] = "15{$nl}0.0";
    $dxf[] = "25{$nl}0.0";
    $dxf[] = "35{$nl}0.0";
    $dxf[] = "146{$nl}0.0";
    $dxf[] = "13{$nl}0.0";
    $dxf[] = "23{$nl}0.0";
    $dxf[] = "33{$nl}0.0";
    $dxf[] = "16{$nl}1.0";
    $dxf[] = "26{$nl}0.0";
    $dxf[] = "36{$nl}0.0";
    $dxf[] = "17{$nl}0.0";
    $dxf[] = "27{$nl}1.0";
    $dxf[] = "37{$nl}0.0";
    $dxf[] = "76{$nl}0";
    $dxf[] = "330{$nl}{$hPaperBlockR}";

    // PlotStyles Dictionary
    $dxf[] = "0{$nl}DICTIONARY";
    $dxf[] = "5{$nl}{$hPlotStyles}";
    $dxf[] = "102{$nl}{ACAD_REACTORS";
    $dxf[] = "330{$nl}{$hRootDict}";
    $dxf[] = "102{$nl}}";
    $dxf[] = "100{$nl}AcDbDictionary";
    $dxf[] = "281{$nl}1";
    $dxf[] = "3{$nl}Normal";
    $dxf[] = "350{$nl}{$hNormalPS}";

    // Placeholder for Normal PlotStyle
    $dxf[] = "0{$nl}ACDBPLACEHOLDER";
    $dxf[] = "5{$nl}{$hNormalPS}";
    $dxf[] = "102{$nl}{ACAD_REACTORS";
    $dxf[] = "330{$nl}{$hPlotStyles}";
    $dxf[] = "102{$nl}}";

    $hWipeoutVariables = dechex($hNext++);
    $dxf[] = "0{$nl}WIPEOUTVARIABLES";
    $dxf[] = "5{$nl}{$hWipeoutVariables}";
    $dxf[] = "102{$nl}{ACAD_REACTORS";
    $dxf[] = "330{$nl}{$hRootDict}";
    $dxf[] = "102{$nl}}";
    $dxf[] = "330{$nl}{$hRootDict}";
    $dxf[] = "100{$nl}AcDbWipeoutVariables";
    $dxf[] = "70{$nl}0";

    $dxf[] = "0{$nl}ENDSEC";
    $dxf[] = "0{$nl}EOF";

    return implode($nl, $dxf);
}

// JSON API: drawing storage, active-user presence, drawing list, rename, and DXF download.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        header('Content-Type: application/json; charset=utf-8');
        $jsonContent = $_POST['data'] ?? '{}';
        $decoded = json_decode($jsonContent, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid drawing data.']);
            exit;
        }
        foreach (($decoded['entities'] ?? []) as &$entity) {
            if (is_array($entity) && ($entity['type'] ?? '') === 'text') {
                unset($entity['size']);
            }
        }
        unset($entity);
        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($dataFile, $formatted, LOCK_EX) !== false) {
            setcookie('cad_file', basename($dataFile), time() + 31536000, '', '', false, true);
            echo json_encode(['status' => 'success', 'fileName' => basename($dataFile, '.json'), 'revision' => getDrawingRevision($dataFile), 'entityRevision' => getDrawingEntityRevision($dataFile)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to write file.']);
        }
        exit;
    }

    if ($action === 'load') {
        header('Content-Type: application/json; charset=utf-8');
        if (file_exists($dataFile)) {
            setcookie('cad_file', basename($dataFile), time() + 31536000, '', '', false, true);
            echo json_encode(['status' => 'success', 'fileName' => basename($dataFile, '.json'), 'revision' => getDrawingRevision($dataFile), 'entityRevision' => getDrawingEntityRevision($dataFile), 'data' => json_decode(file_get_contents($dataFile), true)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No saved drawing found.']);
        }
        exit;
    }

    if ($action === 'check') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'success', 'fileName' => basename($dataFile, '.json'), 'revision' => getDrawingRevision($dataFile), 'entityRevision' => getDrawingEntityRevision($dataFile)]);
        exit;
    }

    if ($action === 'presence') {
        header('Content-Type: application/json; charset=utf-8');
        $userId = getUserId($_POST['clientId'] ?? '');
        $nickname = sanitizeNickname($_POST['nickname'] ?? '') ?? strtoupper(substr($userId, 0, 4));
        $presenceHandle = fopen($presenceFile, 'c+');
        if ($presenceHandle === false || !flock($presenceHandle, LOCK_EX)) {
            if ($presenceHandle !== false) fclose($presenceHandle);
            echo json_encode(['status' => 'error', 'message' => 'Presence is temporarily unavailable.']);
            exit;
        }
        rewind($presenceHandle);
        $presence = json_decode(stream_get_contents($presenceHandle), true);
        if (!is_array($presence)) {
            $presence = [];
        }
        $now = time();
        foreach ($presence as $id => $user) {
            if (($user['file'] ?? '') !== basename($dataFile) || ($user['lastSeen'] ?? 0) < $now - 15) {
                unset($presence[$id]);
            }
        }
        foreach ($presence as $id => $user) {
            if ($id !== $userId && ($user['nickname'] ?? '') === $nickname) {
                flock($presenceHandle, LOCK_UN);
                fclose($presenceHandle);
                echo json_encode(['status' => 'error', 'message' => 'This username is already in use.']);
                exit;
            }
        }
        $presence[$userId] = ['nickname' => $nickname, 'file' => basename($dataFile), 'lastSeen' => $now];
        rewind($presenceHandle);
        ftruncate($presenceHandle, 0);
        fwrite($presenceHandle, json_encode($presence, JSON_UNESCAPED_UNICODE));
        fflush($presenceHandle);
        flock($presenceHandle, LOCK_UN);
        fclose($presenceHandle);
        $users = array_map(static fn($user) => $user['nickname'], $presence);
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        echo json_encode(['status' => 'success', 'users' => $users]);
        exit;
    }

    if ($action === 'list') {
        header('Content-Type: application/json; charset=utf-8');
        $drawings = [];
        foreach (glob(__DIR__ . '/*.json') ?: [] as $filePath) {
            $fileName = basename($filePath, '.json');
            if ($fileName === 'cad_presence') {
                continue;
            }
            if (sanitizeDrawingFileName($fileName . '.json') === $fileName . '.json') {
                $drawings[] = $fileName;
            }
        }
        sort($drawings, SORT_NATURAL | SORT_FLAG_CASE);
        echo json_encode(['status' => 'success', 'files' => $drawings, 'activeFile' => basename($dataFile, '.json')]);
        exit;
    }

    if ($action === 'rename') {
        header('Content-Type: application/json; charset=utf-8');
        $newFileName = sanitizeDrawingFileName($_POST['newFile'] ?? '');
        $oldFileName = basename($dataFile);
        $newFilePath = $newFileName ? __DIR__ . '/' . $newFileName : null;
        if ($newFilePath === null) {
            echo json_encode(['status' => 'error', 'message' => 'Use only letters, numbers, hyphens, or underscores.']);
        } elseif ($newFileName === $oldFileName) {
            echo json_encode(['status' => 'success', 'fileName' => basename($newFileName, '.json')]);
        } elseif (file_exists($newFilePath)) {
            echo json_encode(['status' => 'error', 'message' => 'A drawing with that name already exists.']);
        } elseif (!file_exists($dataFile) || !rename($dataFile, $newFilePath)) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to rename drawing.']);
        } else {
            setcookie('cad_file', $newFileName, time() + 31536000, '', '', false, true);
            echo json_encode(['status' => 'success', 'fileName' => basename($newFileName, '.json')]);
        }
        exit;
    }

    if ($action === 'export_dxf') {
        $raw = $_POST['data'] ?? '';
        $parsed = json_decode($raw, true);

        $entities = [];
        $angleUnit = 'deg';
        $printScale = max(1, (float)($_POST['printScale'] ?? 100));
        $paperSizeKey = 'A3-L';
        $paperFrameCenterX = null;
        $paperFrameCenterY = null;
        if (is_array($parsed)) {
            if (isset($parsed['entities']) && is_array($parsed['entities'])) {
                $entities = $parsed['entities'];
                $angleUnit = $parsed['angleUnit'] ?? 'deg';
                $paperSizeKey = $parsed['paperSize'] ?? $paperSizeKey;
                $paperFrameCenterX = $parsed['paperFrameCenterX'] ?? null;
                $paperFrameCenterY = $parsed['paperFrameCenterY'] ?? null;
            } elseif (isset($parsed[0]) && is_array($parsed[0])) {
                $entities = $parsed;
            }
        }

        $dxfContent = generateDXF2007($entities, $angleUnit, $printScale, $paperSizeKey, $paperFrameCenterX, $paperFrameCenterY);

        header('Content-Type: application/dxf');
        header('Content-Disposition: attachment; filename="' . basename($dataFile, '.json') . '.dxf"');
        header('Content-Length: ' . strlen($dxfContent));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $dxfContent;
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web CAD Engine (AutoCAD 2007 DXF Export)</title>
    <style>
        :root {
            --bg-dark: #1e1e1e;
            --bg-panel: #252526;
            --bg-toolbar: #2d2d2d;
            --border-color: #3f3f46;
            --accent: #007acc;
            --text-main: #d4d4d4;
            --text-muted: #858585;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; user-select: none; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; flex-direction: column; height: 100vh; background: var(--bg-dark); color: var(--text-main); overflow: hidden; }

        #toolbar {
            min-height: 48px;
            height: 48px;
            flex: 0 0 48px;
            background: var(--bg-toolbar);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            padding: 0 12px;
            gap: 8px;
            z-index: 10;
        }
        .btn-group { display: flex; gap: 4px; border-right: 1px solid var(--border-color); padding: 6px 8px 6px 0; align-items: center; }
        button, select, input[type="text"], input[type="color"] {
            background: #3c3c3c;
            color: #fff;
            border: 1px solid #555;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            transition: 0.15s;
        }
        button:hover, select:hover { background: #4c4c4c; }
        button.active { background: var(--accent); border-color: #0098ff; }
        .icon-btn {
            width: 30px;
            height: 28px;
            padding: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .icon-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
        .icon-btn .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        input[type="color"] { padding: 0 2px; width: 32px; height: 26px; }
        label { font-size: 11px; display: flex; align-items: center; gap: 4px; cursor: pointer; color: var(--text-main); }

        #main-container { display: flex; flex: 1; position: relative; height: calc(100vh - 74px); }
        #viewport { flex: 1; position: relative; height: 100%; cursor: crosshair; background: #121212; }
        canvas { display: block; width: 100%; height: 100%; }

        #properties-palette {
            width: 330px;
            min-width: 240px;
            max-width: 600px;
            flex: 0 0 auto;
            resize: horizontal;
            overflow: auto;
            background: var(--bg-panel);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            font-size: 12px;
        }
        #properties-palette.collapsed {
            width: 0 !important;
            min-width: 0;
            flex-basis: 0 !important;
            border-left: 0;
            overflow: hidden;
        }
        #properties-palette.collapsed .panel-header,
        #properties-palette.collapsed .panel-content {
            display: none;
        }
        #toggle-properties-float {
            display: none;
            position: absolute;
            right: 8px;
            top: 8px;
            z-index: 3;
        }
        #properties-resizer {
            width: 6px;
            flex: 0 0 6px;
            background: #303038;
            border-left: 1px solid #444;
            border-right: 1px solid #1a1a1a;
            cursor: col-resize;
        }
        #properties-resizer:hover,
        #properties-resizer.dragging { background: var(--accent); }
        .panel-header {
            background: #2d2d30;
            padding: 8px 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 11px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
        }
        #toggle-properties {
            border: 0;
            background: transparent;
            padding: 0 3px;
            color: var(--text-main);
            font-size: 14px;
            line-height: 1;
        }
        .panel-content { flex: 1; overflow-y: auto; padding: 10px; }
        .prop-group { margin-bottom: 15px; }
        .prop-group-title {
            font-weight: 600;
            color: #4ec9b0;
            border-bottom: 1px solid #333;
            padding-bottom: 3px;
            margin-bottom: 8px;
            text-transform: uppercase;
            font-size: 10px;
        }
        .prop-row {
            display: grid;
            grid-template-columns: 115px 1fr;
            align-items: center;
            margin-bottom: 6px;
            gap: 6px;
        }
        .prop-row label { font-size: 11px; color: var(--text-muted); }
        .prop-row input, .prop-row select {
            width: 100%;
            padding: 3px 6px;
            font-size: 11px;
            font-family: monospace;
            background: #1e1e1e;
            border: 1px solid #444;
        }
        .prop-row input:focus { border-color: var(--accent); outline: none; }
        .prop-row input[readonly] { background: #2a2a2a; color: #888; border-color: #333; }

        .cad-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            font-family: monospace;
            margin-top: 6px;
        }
        .cad-table th, .cad-table td {
            border: 1px solid #3f3f46;
            padding: 4px 6px;
            text-align: right;
        }
        .cad-table th {
            background: #2d2d30;
            color: #4ec9b0;
            text-align: center;
            font-size: 10px;
        }
        .cad-table tr:hover { background: rgba(0, 122, 204, 0.2); cursor: pointer; }
        .cad-table tr.active-row { background: rgba(0, 229, 255, 0.25); font-weight: bold; }

        #statusbar {
            height: 26px;
            background: #007acc;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 12px;
            font-size: 11px;
            font-family: 'Consolas', monospace;
            gap: 12px;
            overflow: hidden;
        }
        #statusbar > div { min-width: 0; white-space: nowrap; }
        #statusbar > div:last-child { display: flex; align-items: center; gap: 4px; overflow-x: auto; }
        #active-users { flex: 0 0 auto; color: #fff; }
        .osnap-badge {
            background: rgba(0,0,0,0.25);
            padding: 2px 6px;
            border-radius: 2px;
            font-size: 10px;
        }

        #toast-container {
            position: fixed;
            bottom: 36px;
            right: 340px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 1000;
            pointer-events: none;
        }
        .toast {
            pointer-events: auto;
            background: rgba(37, 37, 38, 0.95);
            border: 1px solid #444;
            border-left: 4px solid var(--accent);
            color: #eee;
            padding: 10px 14px;
            border-radius: 4px;
            font-size: 12px;
            min-width: 240px;
            max-width: 380px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transform: translateX(120%);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .toast.show { transform: translateX(0); opacity: 1; }
        .toast-success { border-left-color: #4caf50; }
        .toast-error { border-left-color: #f44336; }
        .toast-info { border-left-color: #2196f3; }
        .toast-warning { border-left-color: #ff9800; }
        #point-import-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.55);
            z-index: 1100;
        }
        #point-import-modal.open { display: flex; }
        .point-import-panel {
            width: min(520px, calc(100vw - 32px));
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            padding: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.45);
        }
        .point-import-panel h3 { margin-bottom: 8px; font-size: 14px; }
        #point-import-input {
            width: 100%;
            min-height: 180px;
            resize: vertical;
            padding: 8px;
            color: #fff;
            background: #1e1e1e;
            border: 1px solid #555;
            font: 12px Consolas, monospace;
        }
        .contour-option { display: block; margin: 8px 0; }
        .contour-settings { display: flex; align-items: center; gap: 8px; margin: 12px 0; }
        #contour-interval { width: 90px; }
        #point-import-fields[hidden] { display: none; }
        .point-import-actions { display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px; }
        @media (max-width: 760px) {
            #toolbar { align-items: stretch; padding: 0 8px; gap: 4px; height: auto; flex-basis: auto; overflow: visible; }
            .btn-group { border-right: 0; border-bottom: 1px solid var(--border-color); padding: 4px 4px 4px 0; }
            #toolbar .btn-group:last-child { flex: 1 1 100%; flex-wrap: wrap; }
            #drawing-file-name { min-width: 110px; flex: 1 1 130px; }
            #drawing-file-select { min-width: 130px; flex: 1 1 150px; }
            #main-container { min-height: 0; }
            #properties-palette { width: 280px; min-width: 220px; }
        }
    </style>
</head>
<body>

<div id="toolbar">
    <div class="btn-group">
        <button id="tool-select" class="tool-btn icon-btn active" data-tool="select" title="Select"><svg viewBox="0 0 24 24"><path d="M5 3l4 14 3-4 4 5 2-2-4-5 5-1z"/></svg><span class="sr-only">Select</span></button>
        <button id="tool-line" class="tool-btn icon-btn" data-tool="line" title="Line"><svg viewBox="0 0 24 24"><path d="M5 19L19 5"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="5" r="2"/></svg><span class="sr-only">Line</span></button>
        <button id="tool-pline" class="tool-btn icon-btn" data-tool="pline" title="Polyline (PL)"><svg viewBox="0 0 24 24"><path d="M4 18l5-7 5 3 6-8"/><circle cx="4" cy="18" r="1.5"/><circle cx="9" cy="11" r="1.5"/><circle cx="14" cy="14" r="1.5"/><circle cx="20" cy="6" r="1.5"/></svg><span class="sr-only">Polyline</span></button>
        <button id="tool-rect" class="tool-btn icon-btn" data-tool="rect" title="Rectangle"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14"/><path d="M4 5h3M4 5v3M20 19h-3M20 19v-3"/></svg><span class="sr-only">Rectangle</span></button>
        <button id="tool-circle" class="tool-btn icon-btn" data-tool="circle" title="Circle"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="7"/></svg><span class="sr-only">Circle</span></button>
        <button id="tool-arc" class="tool-btn icon-btn" data-tool="arc" title="Arc"><svg viewBox="0 0 24 24"><path d="M4 17a9 9 0 0 1 13-10"/></svg><span class="sr-only">Arc</span></button>
        <button id="tool-ellipse" class="tool-btn icon-btn" data-tool="ellipse" title="Ellipse"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="12" rx="8" ry="5"/></svg><span class="sr-only">Ellipse</span></button>
        <button id="tool-point" class="tool-btn icon-btn" data-tool="point" title="Point"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="2.5" fill="currentColor"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/></svg><span class="sr-only">Point</span></button>
        <button id="tool-text" class="tool-btn icon-btn" data-tool="text" title="Text"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 18h10M6 6v12M18 6v12M8 10h8"/><path d="M8 14h8"/></svg><span class="sr-only">Text</span></button>
        <button id="btn-generate-contours" class="icon-btn" title="Generate Contours"><svg viewBox="0 0 24 24"><path d="M4 7c3-3 6 3 9 0s6 3 7 0M4 12c3-3 6 3 9 0s6 3 7 0M4 17c3-3 6 3 9 0s6 3 7 0"/></svg><span class="sr-only">Generate Contours</span></button>
        <button id="btn-move" class="icon-btn" title="Move selected objects (M)"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"/><path d="M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3"/></svg><span class="sr-only">Move</span></button>
        <button id="btn-scale" class="icon-btn" title="Scale selected objects (S)"><svg viewBox="0 0 24 24"><path d="M5 19L19 5M8 5h11v11"/><path d="M5 19h11V8"/></svg><span class="sr-only">Scale</span></button>
        <button id="btn-offset" class="icon-btn" title="Offset selected object (O)"><svg viewBox="0 0 24 24"><path d="M4 20V4h16v16H4z"/><path d="M8 16V8h8v8H8z"/></svg><span class="sr-only">Offset</span></button>
        <button id="btn-trim" class="icon-btn" title="Trim selected object (T)"><svg viewBox="0 0 24 24"><path d="M5 17L17 5"/><path d="M8 9l2 2M14 15l3 3"/><path d="M4 12h4M16 12h4"/><circle cx="17" cy="5" r="2"/><circle cx="5" cy="17" r="2"/></svg><span class="sr-only">Trim</span></button>
        <button id="btn-dimension" class="icon-btn" title="Aligned dimension (D)"><svg viewBox="0 0 24 24"><path d="M6 17L18 9M6 17l4-1M6 17l2-3M18 9l-4 1M18 9l-2 3"/><path d="M4 20l3-5M17 9l3-5"/></svg><span class="sr-only">Aligned dimension</span></button>
        <button id="btn-angle-dimension" class="icon-btn" title="Angle dimension (A)"><svg viewBox="0 0 24 24"><path d="M6 16a8 8 0 0 1 8-8"/><path d="M6 16l8-8"/><path d="M8 18h10v-2H8z"/><circle cx="6" cy="16" r="1.5"/><circle cx="14" cy="8" r="1.5"/></svg><span class="sr-only">Angle dimension</span></button>
        <button id="btn-copy-jpg" class="icon-btn" title="Copy selection as JPG"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="1"/><circle cx="9" cy="9" r="1.5"/><path d="M4 16l4-4 3 3 2-2 7 6"/></svg><span class="sr-only">Copy selection as JPG</span></button>
        <button id="btn-hatch" class="icon-btn" title="Hatch with offset (H)"><svg viewBox="0 0 24 24"><path d="M4 18L18 4M8 20L20 8M4 12L12 4"/><path d="M4 20h16V4H4z"/></svg><span class="sr-only">Hatch with offset</span></button>
    </div>

    <div class="btn-group">
        <select id="angleUnits" title="Angle Measurement Unit (Saved)">
            <option value="deg">Degrees (°)</option>
            <option value="grad">Grads (g)</option>
            <option value="rad">Radians (rad)</option>
        </select>
        <select id="paperSize" title="Paper size for the on-canvas frame">
            <option value="A4-P">A4 P</option>
            <option value="A4-L">A4 L</option>
            <option value="A3-P">A3 P</option>
            <option value="A3-L" selected>A3 L</option>
            <option value="A2-P">A2 P</option>
            <option value="A2-L">A2 L</option>
            <option value="A1-P">A1 P</option>
            <option value="A1-L">A1 L</option>
            <option value="A0-P">A0 P</option>
            <option value="A0-L">A0 L</option>
        </select>
        <select id="printScale" title="DXF print scale for text height">
            <option value="50">Scale 1:50</option>
            <option value="100" selected>Scale 1:100</option>
            <option value="200">Scale 1:200</option>
            <option value="500">Scale 1:500</option>
            <option value="1000">Scale 1:1000</option>
        </select>
        <input type="color" id="strokeColor" value="#ffffff" title="Entity Color">
        <select id="lineWidth" title="Line Width">
            <option value="1">1 px</option>
            <option value="2" selected>2 px</option>
            <option value="3">3 px</option>
            <option value="4">4 px</option>
        </select>
    </div>

    <div class="btn-group">
        <label><input type="checkbox" id="osnapToggle" checked> <b>OSNAP (F3)</b></label>
        <label><input type="checkbox" id="snapGrid"> Grid Snap</label>
        <label><input type="checkbox" id="orthoToggle"> Ortho (F8)</label>
    </div>

    <div class="btn-group" style="border: none;">
        <input type="text" id="drawing-file-name" value="<?= htmlspecialchars(basename($dataFile, '.json'), ENT_QUOTES, 'UTF-8') ?>" title="Drawing file name" aria-label="Drawing file name">
        <button id="btn-save" title="Save drawing">Save</button>
        <button id="btn-rename" title="Rename drawing">Rename</button>
        <select id="drawing-file-select" title="Select drawing to load" aria-label="Select drawing to load"><option>Loading...</option></select>
        <button id="btn-undo" title="Undo (Ctrl+Z)">Undo</button>
        <button id="btn-redo" title="Redo (Ctrl+Y)">Redo</button>
        <button id="btn-insert-board" class="tool-btn" data-tool="board" title="Insert title board as one object" style="background: #1b7f6d; border-color: #2ec4b6; font-weight: 700;">ΠΙΝΑΚΙΔΑ</button>
        <button id="btn-export-dxf" class="icon-btn" title="Export to DXF (2007)" style="background: #e65100; border-color: #f57c00; font-weight: 600;"><svg viewBox="0 0 24 24"><path d="M12 3v12M8 11l4 4 4-4"/><path d="M5 19h14"/><path d="M5 7V4h14v3"/></svg><span class="sr-only">Export to DXF (2007)</span></button>
        <span id="save-indicator" style="font-size: 11px; color: #4ec9b0; margin-left: 6px;">● Auto-saved</span>
    </div>
</div>

<div id="main-container">
    <div id="viewport">
        <canvas id="cadCanvas"></canvas>
    </div>

    <button id="toggle-properties-float" title="Show properties">+</button>
    <div id="properties-resizer" title="Resize properties panel"></div>
    <div id="properties-palette">
        <div class="panel-header">
            <span>PROPERTIES</span>
            <span><span id="prop-entity-count" style="color: var(--text-muted);">No selection</span> <button id="toggle-properties" title="Collapse properties">−</button></span>
        </div>
        <div class="panel-content" id="properties-container">
            <div style="color: var(--text-muted); text-align: center; margin-top: 40px;">
                Select an entity to view and edit its properties.
            </div>
        </div>
    </div>
</div>

<div id="statusbar">
    <div id="status-coords">X: 0.000 | Y: 0.000</div>
    <div>
        <span id="status-mode" class="osnap-badge">MODE: SELECT</span>
        <span id="status-angle" class="osnap-badge">AZI: 0.0000°</span>
        <span id="status-osnap" class="osnap-badge">OSNAP: ON</span>
        <span id="status-ortho" class="osnap-badge">ORTHO: OFF</span>
        <span id="status-zoom" class="osnap-badge">ZOOM: 100%</span>
    </div>
    <div id="active-users" class="osnap-badge" title="Active users">0000</div>
</div>

<div id="toast-container"></div>

<div id="point-import-modal" role="dialog" aria-modal="true" aria-labelledby="point-import-title">
    <div class="point-import-panel">
        <h3 id="point-import-title">Generate Contours</h3>
        <label class="contour-option"><input type="radio" name="contour-point-source" value="existing" checked> Use existing points</label>
        <label class="contour-option"><input type="radio" name="contour-point-source" value="new"> Import new points</label>
        <div class="contour-settings">
            <label for="contour-interval">Contour interval (m)</label>
            <input type="number" id="contour-interval" min="0.001" step="any" value="1" required>
        </div>
        <div id="point-import-fields" hidden>
            <textarea id="point-import-input" placeholder="One point per line: X,Y or X,Y,Z&#10;With point labels: P,X,Y or P,X,Y,Z&#10;Commas and tabs are accepted"></textarea>
            <label><input type="checkbox" id="point-import-has-labels"> Point labels</label>
        </div>
        <div class="point-import-actions">
            <button id="btn-cancel-point-import">Cancel</button>
            <button id="btn-apply-point-import" class="active">Generate Contours</button>
        </div>
    </div>
</div>

<script>
(() => {
    const canvas = document.getElementById('cadCanvas');
    const ctx = canvas.getContext('2d');
    const statusCoords = document.getElementById('status-coords');
    const statusAngle = document.getElementById('status-angle');
    const statusZoom = document.getElementById('status-zoom');
    const statusMode = document.getElementById('status-mode');
    const propContainer = document.getElementById('properties-container');
    const propCount = document.getElementById('prop-entity-count');
    const angleUnitsSelect = document.getElementById('angleUnits');
    const paperSizeSelect = document.getElementById('paperSize');
    const printScaleSelect = document.getElementById('printScale');
    const lineWidthSelect = document.getElementById('lineWidth');
    const toastContainer = document.getElementById('toast-container');
    const saveIndicator = document.getElementById('save-indicator');
    const drawingFileName = document.getElementById('drawing-file-name');
    const saveButton = document.getElementById('btn-save');
    const renameButton = document.getElementById('btn-rename');
    const undoButton = document.getElementById('btn-undo');
    const redoButton = document.getElementById('btn-redo');
    const drawingFileSelect = document.getElementById('drawing-file-select');
    const activeUsers = document.getElementById('active-users');
    const apiEndpoint = window.location.pathname;
    const propertiesResizer = document.getElementById('properties-resizer');
    const propertiesPalette = document.getElementById('properties-palette');
    const toggleProperties = document.getElementById('toggle-properties');
    const togglePropertiesFloat = document.getElementById('toggle-properties-float');

    function setPropertiesCollapsed(collapsed) {
        propertiesPalette.classList.toggle('collapsed', collapsed);
        togglePropertiesFloat.style.display = collapsed ? 'block' : 'none';
        toggleProperties.innerText = collapsed ? '+' : '−';
        toggleProperties.title = collapsed ? 'Show properties' : 'Collapse properties';
        togglePropertiesFloat.innerText = collapsed ? '+' : '−';
        localStorage.setItem('cad_properties_collapsed', collapsed ? '1' : '0');
        resize();
    }

    toggleProperties.addEventListener('click', () => setPropertiesCollapsed(true));
    togglePropertiesFloat.addEventListener('click', () => setPropertiesCollapsed(false));

    propertiesResizer.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        propertiesResizer.classList.add('dragging');
        propertiesResizer.setPointerCapture(event.pointerId);
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
    });

    propertiesResizer.addEventListener('pointermove', (event) => {
        if (!propertiesResizer.hasPointerCapture(event.pointerId)) return;
        const mainRect = document.getElementById('main-container').getBoundingClientRect();
        const width = Math.max(240, Math.min(600, mainRect.right - event.clientX));
        propertiesPalette.style.width = `${width}px`;
        propertiesPalette.style.flexBasis = `${width}px`;
        resize();
    });

    propertiesResizer.addEventListener('pointerup', (event) => {
        propertiesResizer.releasePointerCapture(event.pointerId);
        propertiesResizer.classList.remove('dragging');
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        triggerAutoSave();
    });

    const savedUnit = localStorage.getItem('cad_angle_unit') || 'deg';
    angleUnitsSelect.value = savedUnit;
    const savedPaperSize = localStorage.getItem('cad_paper_size') || 'A4-P';
    if ([...paperSizeSelect.options].some(option => option.value === savedPaperSize)) {
        paperSizeSelect.value = savedPaperSize;
    }
    const savedPrintScale = localStorage.getItem('cad_print_scale') || '100';
    if ([...printScaleSelect.options].some(option => option.value === savedPrintScale)) {
        printScaleSelect.value = savedPrintScale;
    }

    function showToast(message, type = 'info', duration = 2500) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        const msgSpan = document.createElement('span');
        msgSpan.innerText = message;
        toast.appendChild(msgSpan);

        toastContainer.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => { if (toast.parentElement) toast.parentElement.removeChild(toast); }, 300);
        }, duration);
    }

    function parseStrictFloat(val, fallback = 0) {
        if (typeof val === 'number') return Number.isFinite(val) ? val : fallback;
        if (!val) return fallback;
        const sanitized = String(val).trim().replace(',', '.');
        const num = parseFloat(sanitized);
        return isNaN(num) ? fallback : num;
    }

    function isValidUsername(value) {
        return /^[\p{L}\p{N} _-]{1,24}$/u.test(value);
    }

    function formatCoord(val) {
        return parseStrictFloat(val).toFixed(3);
    }

    function getDimensionDecimals(ent) {
        const decimals = Number(ent && ent.decimals);
        return Number.isInteger(decimals) ? Math.max(0, Math.min(6, decimals)) : 3;
    }

    function formatDimensionValue(ent, value) {
        return parseStrictFloat(value).toFixed(getDimensionDecimals(ent));
    }

    function calculateAzimuthRad(dx, dy) {
        let azi = Math.atan2(dx, dy);
        if (azi < 0) azi += 2 * Math.PI;
        return azi;
    }

    function azimuthRadToValue(aziRad) {
        if (angleUnitsSelect.value === 'grad') return (aziRad * 200) / Math.PI;
        if (angleUnitsSelect.value === 'rad') return aziRad;
        return (aziRad * 180) / Math.PI;
    }

    function azimuthValueToRad(val) {
        const num = parseStrictFloat(val);
        if (angleUnitsSelect.value === 'grad') return (num * Math.PI) / 200;
        if (angleUnitsSelect.value === 'rad') return num;
        return (num * Math.PI) / 180;
    }

    function formatAzimuth(dx, dy) {
        const aziRad = calculateAzimuthRad(dx, dy);
        const val = azimuthRadToValue(aziRad);
        const unit = angleUnitsSelect.value === 'grad' ? 'g' : (angleUnitsSelect.value === 'rad' ? 'rad' : '°');
        return { val: val.toFixed(4), unit, rad: aziRad };
    }

    function getAngleUnitLabel() {
        return angleUnitsSelect.value === 'grad' ? 'g' : (angleUnitsSelect.value === 'rad' ? 'rad' : '°');
    }

    function getFullAngleValue() {
        return angleUnitsSelect.value === 'grad' ? 400 : (angleUnitsSelect.value === 'rad' ? 2 * Math.PI : 360);
    }

    function normalizeAngle(rad) {
        return (rad % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI);
    }

    function isAzimuthBetween(target, start, end) {
        target = normalizeAngle(target);
        start = normalizeAngle(start);
        end = normalizeAngle(end);
        return start < end ? (target >= start && target <= end) : (target >= start || target <= end);
    }

    function getPolylineCentroid(pts, closed) {
        if (!pts || pts.length === 0) return { x: 0, y: 0 };
        if (pts.length === 1) return { x: pts[0].x, y: pts[0].y };
        if (pts.length === 2 || !closed) {
            let sx = 0, sy = 0;
            pts.forEach(p => { sx += p.x; sy += p.y; });
            return { x: sx / pts.length, y: sy / pts.length };
        }
        let area = 0, cx = 0, cy = 0;
        for (let i = 0; i < pts.length; i++) {
            const j = (i + 1) % pts.length;
            const cross = pts[i].x * pts[j].y - pts[j].x * pts[i].y;
            area += cross;
            cx += (pts[i].x + pts[j].x) * cross;
            cy += (pts[i].y + pts[j].y) * cross;
        }
        area = area / 2;
        if (Math.abs(area) < 1e-6) {
            let sx = 0, sy = 0;
            pts.forEach(p => { sx += p.x; sy += p.y; });
            return { x: sx / pts.length, y: sy / pts.length };
        }
        return { x: cx / (6 * area), y: cy / (6 * area) };
    }

    function getPolylineVertexDetails(ent) {
        const pts = (ent && Array.isArray(ent.points)) ? ent.points : [];
        const n = pts.length;
        const closed = !!ent.closed;
        const details = [];

        if (n === 0) return details;

        for (let i = 0; i < n; i++) {
            const hasPrev = closed || i > 0;
            const hasNext = closed || i < n - 1;
            const prevIdx = (i - 1 + n) % n;
            const nextIdx = (i + 1) % n;

            const pCurr = pts[i];
            const pPrev = pts[prevIdx];
            const pNext = pts[nextIdx];

            let aziBack = null, aziFwd = null, angleRight = null, angleInterior = null;

            if (hasPrev && hasNext && n >= 3 && pPrev && pNext && pCurr) {
                aziBack = calculateAzimuthRad(pPrev.x - pCurr.x, pPrev.y - pCurr.y);
                aziFwd = calculateAzimuthRad(pNext.x - pCurr.x, pNext.y - pCurr.y);
                let betaRight = normalizeAngle(aziBack - aziFwd);
                let betaLeft = normalizeAngle(aziFwd - aziBack);
                angleRight = betaRight;
                angleInterior = Math.min(betaRight, betaLeft);
            }

            details.push({
                index: i,
                x: pCurr.x,
                y: pCurr.y,
                hasAngle: (hasPrev && hasNext && n >= 3),
                aziBack,
                aziFwd,
                angleRight,
                angleInterior
            });
        }
        return details;
    }

    // Editor state, active commands, selection, clipboard, and undo/redo history.
    let entities = [];
    let selectedEntity = null;
    let selectedEntities = new Set();
    let selectedHatch = null;
    let selectedSegmentIndex = null;
    let selectedVertexIndex = 0;
    let currentTool = 'select';
    let isDrawing = false;
    let isPanning = false;
    let startPoint = null;
    let arcCenter = null;
    let arcStartPoint = null;
    let arcDrawingStep = 0;
    let plineVertices = [];
    let currentMouse = { x: 0, y: 0 };
    let panStart = { x: 0, y: 0 };
    let activeSnap = null;
    let activeGrip = null;
    let hoveredGrip = null;
    let selectionBoxStart = null;
    let selectionBoxCurrent = null;
    let isSelectingBox = false;
    let selectionBoxMoved = false;
    let lastSelectionBox = null;
    let imageCaptureSelection = null;
    let isImageCaptureMode = false;
    let imageCapturePreviousStatus = '';
    let clipboardEntities = [];
    let activeMove = null;
    let moveCommand = null;
    let scaleCommand = null;
    let offsetCommand = null;
    let trimCommand = null;
    let dimensionCommand = null;
    let angleDimensionCommand = null;
    let hatchCommand = null;
    let lastMiddleClickTime = 0;
    let pastePreview = null;
    const osnapTypes = [
        ['endpoint', 'Endpoint'],
        ['midpoint', 'Midpoint'],
        ['center', 'Center'],
        ['quadrant', 'Quadrant'],
        ['intersection', 'Intersection'],
        ['perpendicular', 'Perpendicular'],
        ['tangent', 'Tangent'],
        ['nearest', 'Nearest']
    ];
    function getStoredOsnapTypes() {
        const stored = localStorage.getItem('cad_osnap_types');
        if (!stored) return osnapTypes.map(([type]) => type);
        try {
            const parsed = JSON.parse(stored);
            return Array.isArray(parsed) ? parsed.filter(type => osnapTypes.some(([knownType]) => knownType === type)) : osnapTypes.map(([type]) => type);
        } catch (error) {
            console.warn('Ignoring invalid stored OSNAP modes.', error);
            localStorage.removeItem('cad_osnap_types');
            return osnapTypes.map(([type]) => type);
        }
    }
    const enabledOsnapTypes = new Set(getStoredOsnapTypes());

    function isOsnapTypeEnabled(type) {
        return enabledOsnapTypes.has(type);
    }

    function renderOsnapProperties() {
        const checked = type => isOsnapTypeEnabled(type) ? ' checked' : '';
        const isOpen = localStorage.getItem('cad_osnap_modes_open') === 'true';
        return `
            <details class="prop-group" id="osnap-modes-group"${isOpen ? ' open' : ''}>
                <summary class="prop-group-title" style="cursor: pointer;">OSNAP modes</summary>
                ${osnapTypes.map(([type, label]) => `
                    <label style="display:flex; align-items:center; gap:6px; margin:4px 0;">
                        <input type="checkbox" class="prop-osnap-type" data-osnap-type="${type}"${checked(type)}>
                        ${label}
                    </label>
                `).join('')}
            </details>
        `;
    }

    function bindOsnapProperties() {
        const modesGroup = document.getElementById('osnap-modes-group');
        if (modesGroup) {
            modesGroup.addEventListener('toggle', () => {
                localStorage.setItem('cad_osnap_modes_open', modesGroup.open ? 'true' : 'false');
            });
        }
        document.querySelectorAll('.prop-osnap-type').forEach(input => {
            input.addEventListener('change', event => {
                const type = event.target.dataset.osnapType;
                if (event.target.checked) enabledOsnapTypes.add(type);
                else enabledOsnapTypes.delete(type);
                localStorage.setItem('cad_osnap_types', JSON.stringify([...enabledOsnapTypes]));
                render();
            });
        });
    }

    let undoStack = [];
    let redoStack = [];
    const MAX_HISTORY = 1000;

    let autoSaveTimer = null;
    let lastKnownRevision = null;
    let lastKnownEntityRevision = null;
    let localChangesPending = false;
    let syncTimer = null;
    let paperFrameDrag = null;
    let northSymbolDrag = null;
    const northSymbolSize = 54;
    const northSymbolGripSize = 10;
    const northSymbolPadding = 18;
    const paperFrameBorderHitPx = 8;
    let northSymbolPosition = {
        x: parseFloat(localStorage.getItem('cad_north_x')),
        y: parseFloat(localStorage.getItem('cad_north_y'))
    };
    if (!Number.isFinite(northSymbolPosition.x)) northSymbolPosition.x = northSymbolPadding;
    if (!Number.isFinite(northSymbolPosition.y)) northSymbolPosition.y = northSymbolPadding;
    let paperFrameCenter = {
        x: parseFloat(localStorage.getItem('cad_paper_frame_cx')),
        y: parseFloat(localStorage.getItem('cad_paper_frame_cy'))
    };
    const tabId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
    const defaultUsername = tabId.slice(0, 4).toUpperCase();
    let username = localStorage.getItem('cad_username') || defaultUsername;
    if (!isValidUsername(username)) username = defaultUsername;

    function updatePresence(showErrors = false) {
        const formData = new FormData();
        formData.append('action', 'presence');
        formData.append('file', drawingFileName.value.trim());
        formData.append('clientId', tabId);
        formData.append('nickname', username);
        return fetch(apiEndpoint, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success') {
                    if (showErrors) showToast(res.message || 'Could not update username.', 'error');
                    return false;
                }
                activeUsers.textContent = res.users.join('  ');
                activeUsers.title = res.users.length ? `Active users: ${res.users.join(', ')}` : 'No active users';
                return true;
            })
            .catch(() => {
                if (showErrors) showToast('Could not update username.', 'error');
                return false;
            });
    }

    function getDrawingPayload() {
        return {
            entities: normalizeTextEntities(entities),
            viewCenterX: camera.zoom ? -camera.x / camera.zoom : 0,
            viewCenterY: camera.zoom ? camera.y / camera.zoom : 0,
            viewCenterVersion: 2,
            zoom: camera.zoom
        };
    }

    function normalizeTextEntities(items) {
        return items.map(entity => {
            if (entity.type !== 'text') return entity;
            const normalized = { ...entity };
            if (!Number.isFinite(Number(normalized.height)) && Number.isFinite(Number(normalized.size))) {
                normalized.height = Number(normalized.size);
            }
            delete normalized.size;
            const allowedFonts = ['Arial', 'Arial Narrow', 'Tahoma', 'Verdana', 'Consolas', 'Courier New'];
            normalized.fontFamily = allowedFonts.includes(normalized.fontFamily) ? normalized.fontFamily : 'Arial';
            return normalized;
        });
    }

    // Persist the shared drawing and debounce background saves after edits.
    function saveDrawing(manual = false) {
        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('file', drawingFileName.value.trim());
        formData.append('data', JSON.stringify(getDrawingPayload()));
        return fetch(apiEndpoint, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success') throw new Error(res.message || 'Save failed.');
                drawingFileName.value = res.fileName;
                lastKnownRevision = res.revision;
                lastKnownEntityRevision = res.entityRevision;
                localChangesPending = false;
                refreshDrawingList(res.fileName);
                updatePresence();
                if (saveIndicator) saveIndicator.innerText = manual ? '● Saved' : '● Auto-saved';
                return res;
            });
    }

    function triggerAutoSave() {
        localChangesPending = true;
        if (saveIndicator) saveIndicator.innerText = '● Saving...';
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(() => saveDrawing().catch(() => {
            if (saveIndicator) saveIndicator.innerText = '● Auto-save offline';
        }), 400);
    }

    function saveState() {
        localChangesPending = true;
        undoStack.push(JSON.stringify(entities));
        if (undoStack.length > MAX_HISTORY) undoStack.shift();
        redoStack = [];
        updateHistoryButtons();
        triggerAutoSave();
    }

    function executeUndo() {
        if (currentTool === 'pline' && isDrawing && plineVertices.length > 0) {
            plineVertices.pop();
            if (plineVertices.length === 0) isDrawing = false;
            showToast('Polyline vertex undone.', 'info', 1500);
            render();
            return;
        }

        if (undoStack.length === 0) {
            showToast('No more actions to undo.', 'warning', 1800);
            return;
        }

        redoStack.push(JSON.stringify(entities));
        const previousState = undoStack.pop();
        entities = JSON.parse(previousState);
        selectedEntity = null;
        selectedEntities.clear();
        selectedSegmentIndex = null;
        selectedVertexIndex = 0;
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        showToast('Undo completed.', 'info', 1500);
    }

    function executeRedo() {
        if (redoStack.length === 0) {
            showToast('No actions to redo.', 'warning', 1800);
            return;
        }

        undoStack.push(JSON.stringify(entities));
        const nextState = redoStack.pop();
        entities = JSON.parse(nextState);
        selectedEntity = null;
        selectedEntities.clear();
        selectedSegmentIndex = null;
        selectedVertexIndex = 0;
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        showToast('Redo completed.', 'info', 1500);
    }

    function updateHistoryButtons() {
        undoButton.disabled = undoStack.length === 0;
        redoButton.disabled = redoStack.length === 0;
    }

    function setActiveToolbarButton(buttonId = null) {
        document.querySelectorAll('.tool-btn, .icon-btn').forEach(button => {
            button.classList.toggle('active', button.id === buttonId);
        });
    }

    function getPaperSizeMm(paperSizeKey = 'A3-L') {
        const normalized = String(paperSizeKey || 'A3-L').trim().toUpperCase();
        const match = normalized.match(/^A([0-4])-(P|L)$/);
        if (!match) {
            return { widthMm: 297, heightMm: 420 };
        }
        const sizes = {
            4: { widthMm: 210, heightMm: 297 },
            3: { widthMm: 297, heightMm: 420 },
            2: { widthMm: 420, heightMm: 594 },
            1: { widthMm: 594, heightMm: 841 },
            0: { widthMm: 841, heightMm: 1189 }
        };
        const size = sizes[Number(match[1])] || sizes[3];
        const isPortrait = match[2] === 'P';
        return isPortrait
            ? { widthMm: size.widthMm, heightMm: size.heightMm }
            : { widthMm: size.heightMm, heightMm: size.widthMm };
    }

    function createTitleBoardTemplate() {
        const children = [
            { type: 'rect', x: 8.452539198744034, y: -0.0165407857651303, w: 9.600408342436816, h: 13.347727924689968, color: '#ffffff', width: 2 },
            { type: 'rect', x: 8.505844805338256, y: 12.27813136607977, w: 9.496571084226388, h: 1.053056772845149, color: '#ffffff', width: 2 },
            { type: 'rect', x: 8.505844805338256, y: 10.37968408912155, w: 9.496571084226388, h: 1.65380927823944, color: '#ffffff', width: 2 },
            { type: 'rect', x: 8.505844805338256, y: 7.193450996732792, w: 9.496571084226388, h: 1.686896341093026, color: '#ffffff', width: 2 },
            { type: 'rect', x: 8.505844805338256, y: 3.949152470783758, w: 9.496571084226388, h: 1.646, color: '#ffffff', width: 2 },
            { type: 'rect', x: 8.505844805338256, y: 0.0263981917037768, w: 9.496571084226388, h: 3.273759674354095, color: '#ffffff', width: 2 },
            { type: 'line', x1: 8.505844805338256, y1: 12.27813136607977, x2: 18.00241588956464, y2: 12.27813136607977, color: '#ffffff', width: 2 },
            { type: 'line', x1: 8.505844805338256, y1: 10.37968408912155, x2: 18.00241588956464, y2: 10.37968408912155, color: '#ffffff', width: 2 },
            { type: 'line', x1: 8.505844805338256, y1: 7.193450996732792, x2: 18.00241588956464, y2: 7.193450996732792, color: '#ffffff', width: 2 },
            { type: 'line', x1: 8.505844805338256, y1: 3.949152470783758, x2: 18.00241588956464, y2: 3.949152470783758, color: '#ffffff', width: 2 },
            { type: 'line', x1: 8.505844805338256, y1: 0.0263981917037768, x2: 18.00241588956464, y2: 0.0263981917037768, color: '#ffffff', width: 2 },
            { type: 'line', x1: 13.25534333662244, y1: 3.300157866057872, x2: 13.25534333662244, y2: 12.27813136607977, color: '#ffffff', width: 2 },
            { type: 'line', x1: 15.99895254504668, y1: 4.856547335825268, x2: 15.99895254504668, y2: 12.27813136607977, color: '#ffffff', width: 2 },
            { type: 'line', x1: 8.505844805338256, y1: 3.300157866057872, x2: 18.00241588956442, y2: 3.300157866057872, color: '#ffffff', width: 2 }
        ];

        return {
            type: 'dxf-import',
            name: 'Πινακίδα',
            color: '#ffffff',
            width: 2,
            children,
            labels: [
                { text: 'ergodotis', x: 13.25413034745134, y: 12.77784116131028, color: '#ffffff' },
                { text: 'ergo', x: 13.25413034745134, y: 11.20656903204116, color: '#ffffff' },
                { text: 'perioxi', x: 13.25413034745134, y: 9.629138784961185, color: '#ffffff' },
                { text: 'meletitis', x: 13.25413034745134, y: 8.036889672131394, color: '#ffffff' },
                { text: 'ΘΕΜΑ ΣΧΕΔΙΟΥ:', x: 9.677211262235459, y: 6.747679067399246, color: '#ffffff' },
                { text: 'titlos', x: 12.25239867519235, y: 5.72060342434878, color: '#ffffff' },
                { text: 'arithmos', x: 17.00068421730566, y: 5.906792297517483, color: '#ffffff' },
                { text: 'ΚΛΙΜΑΚΑ:  ', x: 8.576422853922849, y: 4.396241778399319, color: '#ffffff' },
                { text: 'ΧΡΟΝΟΣ ΜΕΛΕΤΗΣ:  ', x: 8.576422853922849, y: 3.75270415089667, color: '#ffffff' },
                { text: 'ΘΕΩΡΗΣΗ', x: 10.92730872052561, y: 3.090799674247534, color: '#ffffff' },
                { text: 'Ο ΣΥΝΤΑΞΑΣ', x: 15.62994392133805, y: 3.090799674247534, color: '#ffffff' },
                { text: 'xronos', x: 14.9882533609628, y: 3.752704150896647, color: '#ffffff' },
                { text: 'klimaka', x: 14.9882533609628, y: 4.396241778399319, color: '#ffffff' }
            ]
        };
    }

    function createBoardAtPoint(anchorPoint) {
        const template = createTitleBoardTemplate();
        const templateBounds = getEntityBounds(template);
        if (!templateBounds) return null;

        const templateWidth = Math.max(templateBounds.maxX - templateBounds.minX, 1e-6);
        const templateHeight = Math.max(templateBounds.maxY - templateBounds.minY, 1e-6);
        const paperSize = getPaperSizeMm(paperSizeSelect.value || 'A4-P');
        const targetWidthMm = paperSize.widthMm;
        const targetHeightMm = paperSize.heightMm;
        const scaleX = targetWidthMm / templateWidth;
        const scaleY = targetHeightMm / templateHeight;

        const remapX = x => (x - templateBounds.minX) * scaleX + anchorPoint.x;
        const remapY = y => (y - templateBounds.minY) * scaleY + anchorPoint.y;

        const board = JSON.parse(JSON.stringify(template));
        board.children = (board.children || []).map(child => {
            const transformed = JSON.parse(JSON.stringify(child));
            if (transformed.type === 'line') {
                transformed.x1 = remapX(transformed.x1);
                transformed.y1 = remapY(transformed.y1);
                transformed.x2 = remapX(transformed.x2);
                transformed.y2 = remapY(transformed.y2);
            } else if (transformed.type === 'rect') {
                transformed.x = remapX(transformed.x);
                transformed.y = remapY(transformed.y);
                transformed.w = transformed.w * scaleX;
                transformed.h = transformed.h * scaleY;
            } else if (transformed.type === 'pline' && Array.isArray(transformed.points)) {
                transformed.points = transformed.points.map(point => ({
                    x: remapX(point.x),
                    y: remapY(point.y)
                }));
            }
            return transformed;
        });
        board.labels = (board.labels || []).map(label => ({
            ...label,
            x: remapX(label.x),
            y: remapY(label.y)
        }));
        return board;
    }

    function startBoardInsertMode() {
        if (currentTool === 'board') {
            currentTool = 'select';
            setActiveToolbarButton('tool-select');
            statusMode.innerText = 'MODE: SELECT';
            canvas.style.cursor = 'default';
            render();
            return;
        }
        setActiveToolbarButton('btn-insert-board');
        currentTool = 'board';
        statusMode.innerText = 'BOARD: CLICK TO PLACE';
        canvas.style.cursor = 'crosshair';
        showToast('Click on the canvas to place the title board. Press Esc to cancel.', 'info', 2400);
        render();
    }

    function startTextInsertMode() {
        if (currentTool === 'text') {
            currentTool = 'select';
            setActiveToolbarButton('tool-select');
            statusMode.innerText = 'MODE: SELECT';
            canvas.style.cursor = 'default';
            render();
            return;
        }
        setActiveToolbarButton('tool-text');
        currentTool = 'text';
        statusMode.innerText = 'TEXT: CLICK TO PLACE';
        canvas.style.cursor = 'crosshair';
        showToast('Click on the canvas to place text. Press Esc to cancel.', 'info', 2400);
        render();
    }

    function createTextEntityAtPoint(point) {
        const text = 'TEXT';
        // Store the model-space height required to print text at 3 mm.
        const printScale = Math.max(1, Number(printScaleSelect.value) || 100);
        const height = 0.003 * printScale;
        const newEntity = {
            type: 'text',
            x: point.x,
            y: point.y,
            text,
            height,
            textMode: 'one-line',
            fontFamily: 'Arial',
            rotation: 0,
            justify: 'center',
            color: document.getElementById('strokeColor').value,
            width: parseInt(document.getElementById('lineWidth').value, 10) || 1
        };
        saveState();
        entities.push(newEntity);
        selectedEntity = newEntity;
        selectedEntities = new Set([newEntity]);
        selectedSegmentIndex = null;
        selectedVertexIndex = 0;
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        showToast('Text inserted.', 'success', 1800);
        return newEntity;
    }

    undoButton.addEventListener('click', executeUndo);
    redoButton.addEventListener('click', executeRedo);

    function switchToSelectMode(entityToSelect = null) {
        selectedHatch = null;
        setActiveToolbarButton('tool-select');
        currentTool = 'select';
        isDrawing = false;
        startPoint = null;
        arcCenter = null;
        arcStartPoint = null;
        arcDrawingStep = 0;
        plineVertices = [];
        activeGrip = null;
        statusMode.innerText = 'MODE: SELECT';
        
        if (entityToSelect) {
            selectedEntity = entityToSelect;
            selectedEntities = new Set([entityToSelect]);
            selectedSegmentIndex = entityToSelect.type === 'pline' ? 0 : null;
            selectedVertexIndex = 0;
            showToast(`${entityToSelect.type.toUpperCase()} created.`, 'success', 1500);
        } else {
            selectedEntity = null;
            selectedEntities.clear();
        }
        updatePropertiesPalette();
        render();
    }

    const savedZoom = parseFloat(localStorage.getItem('cad_zoom'));
    const savedCameraX = parseFloat(localStorage.getItem('cad_camera_x'));
    const savedCameraY = parseFloat(localStorage.getItem('cad_camera_y'));
    const hasSavedCameraView = Number.isFinite(savedCameraX) && Number.isFinite(savedCameraY);
    const MAX_ZOOM = 100000;
    let camera = {
        x: Number.isFinite(savedCameraX) ? savedCameraX : 0,
        y: Number.isFinite(savedCameraY) ? savedCameraY : 0,
        zoom: Number.isFinite(savedZoom) ? Math.max(0.05, Math.min(savedZoom, MAX_ZOOM)) : 1
    };
    const GRID_SIZE = 50;
    const SNAP_TOLERANCE_PX = 14;
    const SELECT_TOLERANCE_PX = 8;
    const GRIP_HIT_RADIUS_PX = 8;
    const MOVE_DRAG_THRESHOLD_PX = 4;
    if (localStorage.getItem('cad_properties_collapsed') === '1') setPropertiesCollapsed(true);

    function getGridSize() {
        const rawSize = GRID_SIZE / camera.zoom;
        const magnitude = Math.pow(10, Math.floor(Math.log10(rawSize)));
        const normalized = rawSize / magnitude;
        const step = normalized <= 1 ? 1 : (normalized <= 2 ? 2 : (normalized <= 5 ? 5 : 10));
        return Math.max(0.01, step * magnitude);
    }

    function screenToWorld(sx, sy) {
        return {
            x: (sx - canvas.width / 2 - camera.x) / camera.zoom,
            y: (canvas.height / 2 - sy + camera.y) / camera.zoom
        };
    }
        // Geometry primitives used by snapping, hit-testing, offsets, and intersections.

    function worldToScreen(wx, wy) {
        return {
            x: wx * camera.zoom + canvas.width / 2 + camera.x,
            y: canvas.height / 2 - wy * camera.zoom + camera.y
        };
    }

    function persistCameraView() {
        localStorage.setItem('cad_zoom', String(camera.zoom));
        localStorage.setItem('cad_camera_x', String(camera.x));
        localStorage.setItem('cad_camera_y', String(camera.y));
    }

    function resize() {
        canvas.width = canvas.parentElement.clientWidth;
        canvas.height = canvas.parentElement.clientHeight;
        northSymbolPosition.x = Math.max(0, Math.min(canvas.width - northSymbolSize, northSymbolPosition.x));
        northSymbolPosition.y = Math.max(0, Math.min(canvas.height - northSymbolSize, northSymbolPosition.y));
        if (!Number.isFinite(paperFrameCenter.x) || !Number.isFinite(paperFrameCenter.y)) {
            const center = screenToWorld(canvas.width / 2, canvas.height / 2);
            paperFrameCenter.x = center.x;
            paperFrameCenter.y = center.y;
        }
        render();
    }
    window.addEventListener('resize', resize);

    function pointToSegmentDistance(px, py, x1, y1, x2, y2) {
        const dx = x2 - x1, dy = y2 - y1;
        const lenSq = dx * dx + dy * dy;
        if (lenSq === 0) return { dist: Math.hypot(px - x1, py - y1), x: x1, y: y1, t: 0 };
        let t = Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / lenSq));
        const projX = x1 + t * dx;
        const projY = y1 + t * dy;
        return { dist: Math.hypot(px - projX, py - projY), x: projX, y: projY, t };
    }

    function getLineIntersection(p1, p2, p3, p4) {
        const d = (p1.x - p2.x) * (p3.y - p4.y) - (p1.y - p2.y) * (p3.x - p4.x);
        if (Math.abs(d) < 1e-9) return null;
        const t = ((p1.x - p3.x) * (p3.y - p4.y) - (p1.y - p3.y) * (p3.x - p4.x)) / d;
        const u = -((p1.x - p2.x) * (p1.y - p3.y) - (p1.y - p2.y) * (p1.x - p3.x)) / d;
        if (t >= 0 && t <= 1 && u >= 0 && u <= 1) {
            return { x: p1.x + t * (p2.x - p1.x), y: p1.y + t * (p2.y - p1.y) };
        }
        return null;
    }

    function isPointOnArc(point, arc) {
        const azi = calculateAzimuthRad(point.x - arc.cx, point.y - arc.cy);
        return isAzimuthBetween(azi, arc.startAzi, arc.endAzi);
    }

    function getSegmentCircleIntersections(segment, circle) {
        const dx = segment.p2.x - segment.p1.x;
        const dy = segment.p2.y - segment.p1.y;
        const fx = segment.p1.x - circle.cx;
        const fy = segment.p1.y - circle.cy;
        const a = dx * dx + dy * dy;
        if (a < 1e-12) return [];

        const b = 2 * (fx * dx + fy * dy);
        const c = fx * fx + fy * fy - circle.r * circle.r;
        const discriminant = b * b - 4 * a * c;
        if (discriminant < -1e-9) return [];

        const roots = [];
        const root = Math.sqrt(Math.max(0, discriminant));
        [(-b - root) / (2 * a), (-b + root) / (2 * a)].forEach(t => {
            if (t >= -1e-9 && t <= 1 + 1e-9) {
                const point = { x: segment.p1.x + t * dx, y: segment.p1.y + t * dy };
                if (!roots.some(existing => Math.hypot(existing.x - point.x, existing.y - point.y) < 1e-7)) {
                    roots.push(point);
                }
            }
        });
        return roots;
    }

    function getCircleCircleIntersections(first, second) {
        const dx = second.cx - first.cx;
        const dy = second.cy - first.cy;
        const distance = Math.hypot(dx, dy);
        if (distance < 1e-9 || distance > first.r + second.r + 1e-9 || distance < Math.abs(first.r - second.r) - 1e-9) {
            return [];
        }

        const a = (first.r * first.r - second.r * second.r + distance * distance) / (2 * distance);
        const heightSquared = first.r * first.r - a * a;
        if (heightSquared < -1e-9) return [];

        const height = Math.sqrt(Math.max(0, heightSquared));
        const baseX = first.cx + a * dx / distance;
        const baseY = first.cy + a * dy / distance;
        const offsetX = -dy * height / distance;
        const offsetY = dx * height / distance;
        const points = [{ x: baseX + offsetX, y: baseY + offsetY }];
        if (height > 1e-7) points.push({ x: baseX - offsetX, y: baseY - offsetY });
        return points;
    }

    function getIntersectionPoints() {
        const points = [];
        const addPoint = point => {
            if (!points.some(existing => Math.hypot(existing.x - point.x, existing.y - point.y) < 1e-7)) {
                points.push(point);
            }
        };

        for (let i = 0; i < entities.length; i++) {
            const first = entities[i];
            const firstSegments = getEntitySegments(first);
            for (let j = i + 1; j < entities.length; j++) {
                const second = entities[j];
                const secondSegments = getEntitySegments(second);

                firstSegments.forEach(firstSegment => {
                    secondSegments.forEach(secondSegment => {
                        const intersection = getLineIntersection(firstSegment.p1, firstSegment.p2, secondSegment.p1, secondSegment.p2);
                        if (intersection) addPoint(intersection);
                    });
                });

                const firstCircle = first.type === 'circle' || first.type === 'arc' ? first : null;
                const secondCircle = second.type === 'circle' || second.type === 'arc' ? second : null;
                if (firstCircle && secondSegments.length) {
                    secondSegments.forEach(segment => {
                        getSegmentCircleIntersections(segment, firstCircle).forEach(point => {
                            if (first.type === 'circle' || isPointOnArc(point, first)) addPoint(point);
                        });
                    });
                }
                if (secondCircle && firstSegments.length) {
                    firstSegments.forEach(segment => {
                        getSegmentCircleIntersections(segment, secondCircle).forEach(point => {
                            if (second.type === 'circle' || isPointOnArc(point, second)) addPoint(point);
                        });
                    });
                }
                if (firstCircle && secondCircle) {
                    getCircleCircleIntersections(firstCircle, secondCircle).forEach(point => {
                        if ((first.type === 'circle' || isPointOnArc(point, first)) &&
                            (second.type === 'circle' || isPointOnArc(point, second))) {
                            addPoint(point);
                        }
                    });
                }
            }
        }
        return points;
    }

    function getEntitySegments(ent) {
        if (ent.type === 'line') return [{ p1: { x: ent.x1, y: ent.y1 }, p2: { x: ent.x2, y: ent.y2 } }];
        if (ent.type === 'rect') {
            const x1 = ent.x, y1 = ent.y, x2 = ent.x + ent.w, y2 = ent.y + ent.h;
            return [
                { p1: { x: x1, y: y1 }, p2: { x: x2, y: y1 } },
                { p1: { x: x2, y: y1 }, p2: { x: x2, y: y2 } },
                { p1: { x: x2, y: y2 }, p2: { x: x1, y: y2 } },
                { p1: { x: x1, y: y2 }, p2: { x: x1, y: y1 } }
            ];
        }
        if (ent.type === 'pline' && ent.points && ent.points.length > 1) {
            const segs = [];
            for (let i = 0; i < ent.points.length - 1; i++) {
                segs.push({ p1: ent.points[i], p2: ent.points[i + 1], index: i });
            }
            if (ent.closed && ent.points.length > 2) {
                segs.push({ p1: ent.points[ent.points.length - 1], p2: ent.points[0], index: ent.points.length - 1 });
            }
            return segs;
        }
        return [];
    }

    // OSNAP candidates are computed from the complete current drawing.
    function getSnapCandidates(refPoint, excludeEntity) {
        const excludeSet = excludeEntity instanceof Set ? excludeEntity
            : (excludeEntity instanceof Map ? new Set(excludeEntity.keys())
            : (excludeEntity ? new Set([excludeEntity]) : null));
        const snaps = [];
        const allSegments = [];

        entities.forEach(ent => {
            if (excludeSet && excludeSet.has(ent)) return;

            const segs = getEntitySegments(ent);
            allSegments.push(...segs);

            if (ent.type === 'line') {
                snaps.push({ x: ent.x1, y: ent.y1, type: 'endpoint' });
                snaps.push({ x: ent.x2, y: ent.y2, type: 'endpoint' });
                snaps.push({ x: (ent.x1 + ent.x2) / 2, y: (ent.y1 + ent.y2) / 2, type: 'midpoint' });
            } else if (ent.type === 'rect') {
                const x1 = ent.x, y1 = ent.y, x2 = ent.x + ent.w, y2 = ent.y + ent.h;
                snaps.push({ x: x1, y: y1, type: 'endpoint' }, { x: x2, y: y1, type: 'endpoint' },
                           { x: x2, y: y2, type: 'endpoint' }, { x: x1, y: y2, type: 'endpoint' });
                snaps.push({ x: (x1 + x2) / 2, y: y1, type: 'midpoint' }, { x: x2, y: (y1 + y2) / 2, type: 'midpoint' },
                           { x: (x1 + x2) / 2, y: y2, type: 'midpoint' }, { x: x1, y: (y1 + y2) / 2, type: 'midpoint' });
                snaps.push({ x: x1 + ent.w / 2, y: y1 + ent.h / 2, type: 'center' });
            } else if (ent.type === 'pline' && ent.points) {
                const centroid = getPolylineCentroid(ent.points, ent.closed);
                snaps.push({ x: centroid.x, y: centroid.y, type: 'center' });
                ent.points.forEach(pt => snaps.push({ x: pt.x, y: pt.y, type: 'endpoint' }));
                for (let i = 0; i < ent.points.length - 1; i++) {
                    snaps.push({ x: (ent.points[i].x + ent.points[i + 1].x) / 2, y: (ent.points[i].y + ent.points[i + 1].y) / 2, type: 'midpoint' });
                }
                if (ent.closed && ent.points.length > 2) {
                    snaps.push({ x: (ent.points[ent.points.length - 1].x + ent.points[0].x) / 2, y: (ent.points[ent.points.length - 1].y + ent.points[0].y) / 2, type: 'midpoint' });
                }
            } else if (ent.type === 'circle') {
                snaps.push({ x: ent.cx, y: ent.cy, type: 'center' });
                snaps.push({ x: ent.cx + ent.r, y: ent.cy, type: 'quadrant' },
                           { x: ent.cx - ent.r, y: ent.cy, type: 'quadrant' },
                           { x: ent.cx, y: ent.cy + ent.r, type: 'quadrant' },
                           { x: ent.cx, y: ent.cy - ent.r, type: 'quadrant' });

                if (refPoint) {
                    const vx = refPoint.x - ent.cx;
                    const vy = refPoint.y - ent.cy;
                    const distanceSquared = vx * vx + vy * vy;
                    const radiusSquared = ent.r * ent.r;

                    if (distanceSquared >= radiusSquared && distanceSquared > 0) {
                        const distance = Math.sqrt(distanceSquared);
                        const radialX = vx / distance;
                        const radialY = vy / distance;

                        // Perpendicular snap: the two points on the circle along the radial line.
                        snaps.push(
                            { x: ent.cx + radialX * ent.r, y: ent.cy + radialY * ent.r, type: 'perpendicular' },
                            { x: ent.cx - radialX * ent.r, y: ent.cy - radialY * ent.r, type: 'perpendicular' }
                        );

                        // Tangent snap: points where the segment from refPoint is tangent to the circle.
                        const tangentBaseDistance = radiusSquared / distance;
                        const tangentOffsetDistance = ent.r * Math.sqrt(distanceSquared - radiusSquared) / distance;
                        const baseX = ent.cx + tangentBaseDistance * radialX;
                        const baseY = ent.cy + tangentBaseDistance * radialY;
                        snaps.push(
                            { x: baseX - tangentOffsetDistance * radialY, y: baseY + tangentOffsetDistance * radialX, type: 'tangent' },
                            { x: baseX + tangentOffsetDistance * radialY, y: baseY - tangentOffsetDistance * radialX, type: 'tangent' }
                        );
                    }
                }
            } else if (ent.type === 'ellipse') {
                snaps.push({ x: ent.cx, y: ent.cy, type: 'center' });
                snaps.push({ x: ent.cx + ent.rx, y: ent.cy, type: 'quadrant' },
                           { x: ent.cx - ent.rx, y: ent.cy, type: 'quadrant' },
                           { x: ent.cx, y: ent.cy + ent.ry, type: 'quadrant' },
                           { x: ent.cx, y: ent.cy - ent.ry, type: 'quadrant' });
            } else if (ent.type === 'arc') {
                snaps.push({ x: ent.cx, y: ent.cy, type: 'center' });
                const p1x = ent.cx + ent.r * Math.sin(ent.startAzi);
                const p1y = ent.cy + ent.r * Math.cos(ent.startAzi);
                const p2x = ent.cx + ent.r * Math.sin(ent.endAzi);
                const p2y = ent.cy + ent.r * Math.cos(ent.endAzi);
                snaps.push({ x: p1x, y: p1y, type: 'endpoint' });
                snaps.push({ x: p2x, y: p2y, type: 'endpoint' });

                let delta = normalizeAngle(ent.endAzi - ent.startAzi);
                if (delta === 0) delta = 2 * Math.PI;
                const midAzi = normalizeAngle(ent.startAzi + delta / 2);
                snaps.push({ x: ent.cx + ent.r * Math.sin(midAzi), y: ent.cy + ent.r * Math.cos(midAzi), type: 'midpoint' });
            } else if (ent.type === 'dimension' && ent.kind === 'angle') {
                const center = { x: ent.cx, y: ent.cy };
                const start = getAngleDimensionRayEnd(ent, 'start');
                const end = getAngleDimensionRayEnd(ent, 'end');
                const textPosition = getDimensionTextPosition(ent);
                snaps.push(
                    { x: center.x, y: center.y, type: 'center' },
                    { x: start.x, y: start.y, type: 'endpoint' },
                    { x: end.x, y: end.y, type: 'endpoint' },
                    { x: textPosition.x, y: textPosition.y, type: 'midpoint' }
                );
            } else if (ent.type === 'dimension') {
                const textPosition = getDimensionTextPosition(ent);
                snaps.push(
                    { x: ent.x1, y: ent.y1, type: 'endpoint' },
                    { x: ent.x2, y: ent.y2, type: 'endpoint' },
                    { x: textPosition.x, y: textPosition.y, type: 'center' }
                );
            } else if (ent.type === 'point') {
                snaps.push({ x: ent.x, y: ent.y, type: 'endpoint' });
            } else if (ent.type === 'text') {
                const bounds = getTextBoxBounds(ent);
                const corners = [
                    { x: bounds.minX, y: bounds.minY },
                    { x: bounds.maxX, y: bounds.minY },
                    { x: bounds.maxX, y: bounds.maxY },
                    { x: bounds.minX, y: bounds.maxY }
                ];
                corners.forEach(point => snaps.push({ ...point, type: 'endpoint' }));
                corners.forEach((point, index) => {
                    const next = corners[(index + 1) % corners.length];
                    snaps.push({ x: (point.x + next.x) / 2, y: (point.y + next.y) / 2, type: 'midpoint' });
                });
            }
        });

        getIntersectionPoints().forEach(intersection => {
            snaps.push({ x: intersection.x, y: intersection.y, type: 'intersection' });
        });

        const paperFrameRect = getPrintFrameRectWorld();
        const paperFrameCorners = [
            { x: paperFrameRect.left, y: paperFrameRect.bottom },
            { x: paperFrameRect.right, y: paperFrameRect.bottom },
            { x: paperFrameRect.right, y: paperFrameRect.top },
            { x: paperFrameRect.left, y: paperFrameRect.top }
        ];
        paperFrameCorners.forEach(point => snaps.push({ x: point.x, y: point.y, type: 'endpoint' }));
        snaps.push(
            { x: (paperFrameRect.left + paperFrameRect.right) / 2, y: paperFrameRect.bottom, type: 'midpoint' },
            { x: paperFrameRect.right, y: (paperFrameRect.bottom + paperFrameRect.top) / 2, type: 'midpoint' },
            { x: (paperFrameRect.left + paperFrameRect.right) / 2, y: paperFrameRect.top, type: 'midpoint' },
            { x: paperFrameRect.left, y: (paperFrameRect.bottom + paperFrameRect.top) / 2, type: 'midpoint' },
            { x: (paperFrameRect.left + paperFrameRect.right) / 2, y: (paperFrameRect.bottom + paperFrameRect.top) / 2, type: 'center' }
        );
        if (refPoint) {
            allSegments.forEach(seg => {
                const dx = seg.p2.x - seg.p1.x, dy = seg.p2.y - seg.p1.y;
                const lenSq = dx * dx + dy * dy;
                if (lenSq > 0) {
                    const t = ((refPoint.x - seg.p1.x) * dx + (refPoint.y - seg.p1.y) * dy) / lenSq;
                    if (t >= 0 && t <= 1) {
                        snaps.push({ x: seg.p1.x + t * dx, y: seg.p1.y + t * dy, type: 'perpendicular' });
                    }
                }
            });
        }

        return { discrete: snaps, segments: allSegments };
    }

    function findNearestLineIntersectionSnap(mouseScreenX, mouseScreenY, excludeEntity = null, maxScreenDistance = 24) {
        const lineLikeEntities = entities.filter(ent => ent !== excludeEntity && ['line', 'rect', 'pline'].includes(ent.type));
        const cursorWorld = screenToWorld(mouseScreenX, mouseScreenY);
        const candidateSegments = [];

        lineLikeEntities.forEach(ent => {
            getEntitySegments(ent).forEach(seg => {
                const projection = pointToSegmentDistance(cursorWorld.x, cursorWorld.y, seg.p1.x, seg.p1.y, seg.p2.x, seg.p2.y);
                const screenPoint = worldToScreen(projection.x, projection.y);
                candidateSegments.push({
                    seg,
                    screenDistance: Math.hypot(mouseScreenX - screenPoint.x, mouseScreenY - screenPoint.y)
                });
            });
        });

        candidateSegments.sort((a, b) => a.screenDistance - b.screenDistance);

        const segmentPool = candidateSegments.slice(0, 16);
        let bestSnap = null;
        let bestScore = Infinity;

        for (let i = 0; i < segmentPool.length; i++) {
            for (let j = i + 1; j < segmentPool.length; j++) {
                const firstSegment = segmentPool[i].seg;
                const secondSegment = segmentPool[j].seg;
                const intersection = getLineIntersection(firstSegment.p1, firstSegment.p2, secondSegment.p1, secondSegment.p2);
                if (!intersection) continue;

                const screenPoint = worldToScreen(intersection.x, intersection.y);
                const distance = Math.hypot(mouseScreenX - screenPoint.x, mouseScreenY - screenPoint.y);
                const proximityScore = distance + segmentPool[i].screenDistance + segmentPool[j].screenDistance;
                if (distance <= maxScreenDistance && proximityScore < bestScore) {
                    bestScore = proximityScore;
                    bestSnap = {
                        worldX: intersection.x,
                        worldY: intersection.y,
                        screenX: screenPoint.x,
                        screenY: screenPoint.y,
                        type: 'intersection'
                    };
                }
            }
        }
        return bestSnap;
    }

    function findBestSnap(mouseScreenX, mouseScreenY, excludeEntity = null, options = {}) {
        if (!document.getElementById('osnapToggle').checked) return null;
        const discreteOnly = !!options.discreteOnly;
        const segmentOnly = !!options.segmentOnly;
        const toleranceMultiplier = options.toleranceMultiplier ?? 1;
        const preferIntersections = !!options.preferIntersections;
        const snapPriority = type => ({
            intersection: 0,
            endpoint: 1,
            center: 2,
            quadrant: 3,
            midpoint: 4,
            perpendicular: 5,
            tangent: 6,
            nearest: 7
        }[type] ?? 99);

        const cursorWorld = screenToWorld(mouseScreenX, mouseScreenY);
        const refPt = isDrawing ? (currentTool === 'pline' && plineVertices.length > 0 ? plineVertices[plineVertices.length - 1] : startPoint) : (activeGrip ? activeGrip.startWorld : null);
        let { discrete, segments } = getSnapCandidates(refPt, excludeEntity);
        discrete = discrete.filter(point => isOsnapTypeEnabled(point.type));

        let bestSnap = null;
        let bestPriority = 99;
        // During drawing, increase snap tolerance to make snapping easier
        let minDistance = (isDrawing ? SNAP_TOLERANCE_PX * 1.5 : SNAP_TOLERANCE_PX) * toleranceMultiplier;

        if (!segmentOnly && preferIntersections) {
            discrete.forEach(pt => {
                if (pt.type !== 'intersection') return;
                const screenPt = worldToScreen(pt.x, pt.y);
                const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
                if (d < minDistance) {
                    minDistance = d;
                    bestPriority = snapPriority(pt.type);
                    bestSnap = { worldX: pt.x, worldY: pt.y, screenX: screenPt.x, screenY: screenPt.y, type: pt.type };
                }
            });
            if (bestSnap) return bestSnap;
        }

        if (!segmentOnly) {
            discrete.forEach(pt => {
                const screenPt = worldToScreen(pt.x, pt.y);
                const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
                const priority = snapPriority(pt.type);
                if (d < minDistance && (priority < bestPriority || (priority === bestPriority && d < Math.hypot(mouseScreenX - bestSnap.screenX, mouseScreenY - bestSnap.screenY)))) {
                    minDistance = d;
                    bestPriority = priority;
                    bestSnap = { worldX: pt.x, worldY: pt.y, screenX: screenPt.x, screenY: screenPt.y, type: pt.type };
                }
            });

            if (bestSnap) return bestSnap;
        }

        if (discreteOnly) return bestSnap;

        if (isOsnapTypeEnabled('nearest')) segments.forEach(seg => {
            const res = pointToSegmentDistance(cursorWorld.x, cursorWorld.y, seg.p1.x, seg.p1.y, seg.p2.x, seg.p2.y);
            const screenPt = worldToScreen(res.x, res.y);
            const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
            if (d < minDistance) {
                minDistance = d;
                bestSnap = { worldX: res.x, worldY: res.y, screenX: screenPt.x, screenY: screenPt.y, type: 'nearest' };
            }
        });

        const excludeSetForNearest = excludeEntity instanceof Set ? excludeEntity
            : (excludeEntity instanceof Map ? new Set(excludeEntity.keys())
            : (excludeEntity ? new Set([excludeEntity]) : null));
        if (!segmentOnly) entities.filter(e => !excludeSetForNearest || !excludeSetForNearest.has(e)).forEach(e => {
            if (!isOsnapTypeEnabled('nearest')) return;
            if (e.type === 'circle') {
                const angle = Math.atan2(cursorWorld.y - e.cy, cursorWorld.x - e.cx);
                const nx = e.cx + e.r * Math.cos(angle);
                const ny = e.cy + e.r * Math.sin(angle);
                const screenPt = worldToScreen(nx, ny);
                const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
                if (d < minDistance) {
                    minDistance = d;
                    bestSnap = { worldX: nx, worldY: ny, screenX: screenPt.x, screenY: screenPt.y, type: 'nearest' };
                }
            } else if (e.type === 'arc') {
                const azi = calculateAzimuthRad(cursorWorld.x - e.cx, cursorWorld.y - e.cy);
                if (isAzimuthBetween(azi, e.startAzi, e.endAzi)) {
                    const nx = e.cx + e.r * Math.sin(azi);
                    const ny = e.cy + e.r * Math.cos(azi);
                    const screenPt = worldToScreen(nx, ny);
                    const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
                    if (d < minDistance) {
                        minDistance = d;
                        bestSnap = { worldX: nx, worldY: ny, screenX: screenPt.x, screenY: screenPt.y, type: 'nearest' };
                    }
                }
            } else if (e.type === 'ellipse') {
                const angle = Math.atan2(cursorWorld.y - e.cy, cursorWorld.x - e.cx);
                const nx = e.cx + e.rx * Math.cos(angle);
                const ny = e.cy + e.ry * Math.sin(angle);
                const screenPt = worldToScreen(nx, ny);
                const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
                if (d < minDistance) {
                    minDistance = d;
                    bestSnap = { worldX: nx, worldY: ny, screenX: screenPt.x, screenY: screenPt.y, type: 'nearest' };
                }
            }
        });

        return bestSnap;
    }

    function applyOrtho(start, target) {
        if (!document.getElementById('orthoToggle').checked || !start) return target;
        const dx = Math.abs(target.x - start.x);
        const dy = Math.abs(target.y - start.y);
        return dx >= dy ? { x: target.x, y: start.y } : { x: start.x, y: target.y };
    }

    function getTextBoxBounds(ent) {
        const text = String(ent.text || '');
        const height = Math.max(0.001, Number(ent.height ?? 0.1));
        const lines = ent.textMode === 'multiline' ? text.split(/\r?\n/) : [text.replace(/[\r\n]+/g, ' ')];
        const defaultWidth = Math.max(...lines.map(line => line.length * height * 0.58), height * 2);
        const defaultHeight = height * 1.2 * lines.length;
        const { horizontal, vertical } = getTextJustification(ent);
        let minX = horizontal === 'left' ? ent.x : horizontal === 'right' ? ent.x - defaultWidth : ent.x - defaultWidth / 2;
        let maxX = horizontal === 'left' ? ent.x + defaultWidth : horizontal === 'right' ? ent.x : ent.x + defaultWidth / 2;
        let minY = vertical === 'top' ? ent.y - defaultHeight : vertical === 'bottom' ? ent.y : ent.y - defaultHeight / 2;
        let maxY = vertical === 'top' ? ent.y : vertical === 'bottom' ? ent.y + defaultHeight : ent.y + defaultHeight / 2;
        const box = ent.textMode === 'multiline' ? ent.textBox : null;
        if (box && [box.minX, box.minY, box.maxX, box.maxY].every(Number.isFinite)) {
            minX = ent.x + box.minX;
            minY = ent.y + box.minY;
            maxX = ent.x + box.maxX;
            maxY = ent.y + box.maxY;
        }
        return {
            minX: Math.min(minX, maxX),
            minY: Math.min(minY, maxY),
            maxX: Math.max(minX, maxX),
            maxY: Math.max(minY, maxY),
            defaultWidth,
            defaultHeight
        };
    }

    function getTextJustification(ent) {
        const value = ent.justify || 'center';
        const legacy = { left: 'middle-left', center: 'middle-center', right: 'middle-right' };
        const normalized = legacy[value] || value;
        const match = /^(top|middle|bottom)-(left|center|right)$/.exec(normalized);
        return match
            ? { vertical: match[1], horizontal: match[2] }
            : { vertical: 'middle', horizontal: 'center' };
    }

    function getTextAnchorPoint(ent, bounds = getTextBoxBounds(ent)) {
        const { horizontal, vertical } = getTextJustification(ent);
        return {
            x: horizontal === 'left' ? bounds.minX : horizontal === 'right' ? bounds.maxX : (bounds.minX + bounds.maxX) / 2,
            y: vertical === 'top' ? bounds.maxY : vertical === 'bottom' ? bounds.minY : (bounds.minY + bounds.maxY) / 2
        };
    }

    function getEntityGrips(ent) {
        if (!ent) return [];
        if (ent.type === 'line') {
            return [
                { id: 'start', type: 'stretch', label: 'P1 (Start)', color: '#4caf50', x: ent.x1, y: ent.y1 },
                { id: 'end', type: 'stretch', label: 'P2 (End)', color: '#ff9800', x: ent.x2, y: ent.y2 },
                { id: 'mid', type: 'move', label: 'Mid', color: '#007acc', x: (ent.x1 + ent.x2) / 2, y: (ent.y1 + ent.y2) / 2 }
            ];
        }
        if (ent.type === 'rect') {
            return [
                { id: 'c0', type: 'stretch', x: ent.x, y: ent.y },
                { id: 'c1', type: 'stretch', x: ent.x + ent.w, y: ent.y },
                { id: 'c2', type: 'stretch', x: ent.x + ent.w, y: ent.y + ent.h },
                { id: 'c3', type: 'stretch', x: ent.x, y: ent.y + ent.h },
                { id: 'center', type: 'move', x: ent.x + ent.w / 2, y: ent.y + ent.h / 2 }
            ];
        }
        if (ent.type === 'pline' && ent.points) {
            const grips = [];
            const centroid = getPolylineCentroid(ent.points, ent.closed);
            grips.push({ id: 'center', type: 'move_all', label: 'Center (Move)', color: '#00e5ff', x: centroid.x, y: centroid.y });

            ent.points.forEach((p, idx) => {
                let label = `K${idx + 1}`;
                let color = idx === selectedVertexIndex ? '#e040fb' : '#007acc';
                if (selectedSegmentIndex !== null) {
                    const segStartIdx = selectedSegmentIndex;
                    const segEndIdx = (selectedSegmentIndex + 1) % ent.points.length;
                    if (idx === segStartIdx) { label = `K${idx + 1} (P1)`; color = '#4caf50'; }
                    else if (idx === segEndIdx) { label = `K${idx + 1} (P2)`; color = '#ff9800'; }
                }
                grips.push({ id: `v_${idx}`, index: idx, type: 'vertex', label, color, x: p.x, y: p.y });
            });

            for (let i = 0; i < ent.points.length - 1; i++) {
                grips.push({
                    id: `e_${i}`, index: i, type: 'edge',
                    color: i === selectedSegmentIndex ? '#00e5ff' : '#007acc',
                    x: (ent.points[i].x + ent.points[i + 1].x) / 2,
                    y: (ent.points[i].y + ent.points[i + 1].y) / 2
                });
            }
            if (ent.closed && ent.points.length > 2) {
                const last = ent.points.length - 1;
                grips.push({
                    id: `e_${last}`, index: last, type: 'edge',
                    color: last === selectedSegmentIndex ? '#00e5ff' : '#007acc',
                    x: (ent.points[last].x + ent.points[0].x) / 2,
                    y: (ent.points[last].y + ent.points[0].y) / 2
                });
            }
            return grips;
        }
        if (ent.type === 'circle') {
            return [
                { id: 'center', type: 'move', x: ent.cx, y: ent.cy },
                { id: 'q0', type: 'radius', x: ent.cx + ent.r, y: ent.cy },
                { id: 'q1', type: 'radius', x: ent.cx, y: ent.cy + ent.r },
                { id: 'q2', type: 'radius', x: ent.cx - ent.r, y: ent.cy },
                { id: 'q3', type: 'radius', x: ent.cx, y: ent.cy - ent.r }
            ];
        }
        if (ent.type === 'ellipse') {
            return [
                { id: 'center', type: 'move', x: ent.cx, y: ent.cy },
                { id: 'qx1', type: 'rx', x: ent.cx + ent.rx, y: ent.cy },
                { id: 'qx2', type: 'rx', x: ent.cx - ent.rx, y: ent.cy },
                { id: 'qy1', type: 'ry', x: ent.cx, y: ent.cy + ent.ry },
                { id: 'qy2', type: 'ry', x: ent.cx, y: ent.cy - ent.ry }
            ];
        }
        if (ent.type === 'arc') {
            const p1x = ent.cx + ent.r * Math.sin(ent.startAzi);
            const p1y = ent.cy + ent.r * Math.cos(ent.startAzi);
            const p2x = ent.cx + ent.r * Math.sin(ent.endAzi);
            const p2y = ent.cy + ent.r * Math.cos(ent.endAzi);
            let delta = normalizeAngle(ent.endAzi - ent.startAzi);
            if (delta === 0) delta = 2 * Math.PI;
            const midAzi = normalizeAngle(ent.startAzi + delta / 2);
            const pmx = ent.cx + ent.r * Math.sin(midAzi);
            const pmy = ent.cy + ent.r * Math.cos(midAzi);

            return [
                { id: 'center', type: 'move', label: 'Center', color: '#007acc', x: ent.cx, y: ent.cy },
                { id: 'start', type: 'arc_start', label: 'P1 (Start)', color: '#4caf50', x: p1x, y: p1y },
                { id: 'end', type: 'arc_end', label: 'P2 (End)', color: '#ff9800', x: p2x, y: p2y },
                { id: 'mid', type: 'arc_mid', label: 'Mid', color: '#00e5ff', x: pmx, y: pmy }
            ];
        }
        if (ent.type === 'text') {
            if (ent.textMode !== 'multiline') {
                const bounds = getTextBoxBounds(ent);
                const anchor = getTextAnchorPoint(ent, bounds);
                return [{ id: 'anchor', type: 'move', color: '#00e5ff', x: anchor.x, y: anchor.y }];
            }
            const bounds = getTextBoxBounds(ent);
            const corners = [
                { x: bounds.minX, y: bounds.minY },
                { x: bounds.maxX, y: bounds.minY },
                { x: bounds.maxX, y: bounds.maxY },
                { x: bounds.minX, y: bounds.maxY }
            ];
            const grips = corners.map((point, index) => ({
                id: `text-c${index}`,
                type: 'text_box_edge',
                color: '#999999',
                x: point.x,
                y: point.y
            }));
            corners.forEach((point, index) => {
                const next = corners[(index + 1) % corners.length];
                grips.push({
                    id: `text-m${index}`,
                    type: 'text_box_edge',
                    color: '#999999',
                    x: (point.x + next.x) / 2,
                    y: (point.y + next.y) / 2
                });
            });
            grips.push({ id: 'anchor', type: 'move', color: '#00e5ff', x: ent.x, y: ent.y });
            return grips;
        }
        if (ent.type === 'point') {
            return [{ id: 'center', type: 'move', x: ent.x, y: ent.y }];
        }
        if (ent.type === 'dimension' && ent.kind === 'angle') {
            const start = getAngleDimensionRayEnd(ent, 'start');
            const end = getAngleDimensionRayEnd(ent, 'end');
            const textPosition = getDimensionTextPosition(ent);
            return [
                { id: 'center', type: 'dimension_center', label: 'Center', color: '#007acc', x: ent.cx, y: ent.cy },
                { id: 'start', type: 'dimension_start', label: 'Ray 1', color: '#4caf50', x: start.x, y: start.y },
                { id: 'end', type: 'dimension_end', label: 'Ray 2', color: '#ff9800', x: end.x, y: end.y },
                { id: 'position', type: 'dimension_position', color: '#00e5ff', x: textPosition.x, y: textPosition.y }
            ];
        }
        if (ent.type === 'dimension') {
            const dx = ent.x2 - ent.x1;
            const dy = ent.y2 - ent.y1;
            const length = Math.hypot(dx, dy);
            if (length < 1e-9) return [];
            const textPosition = getDimensionTextPosition(ent);
            return [
                { id: 'start', type: 'dimension_start', label: 'P1', color: '#4caf50', x: ent.x1, y: ent.y1 },
                { id: 'end', type: 'dimension_end', label: 'P2', color: '#ff9800', x: ent.x2, y: ent.y2 },
                { id: 'position', type: 'dimension_position', color: '#00e5ff', x: textPosition.x, y: textPosition.y }
            ];
        }
        return [];
    }

    function hitTestGrip(screenX, screenY, ent) {
        if (!ent) return null;
        const grips = getEntityGrips(ent);
        for (let i = 0; i < grips.length; i++) {
            const g = grips[i];
            const sp = worldToScreen(g.x, g.y);
            if (Math.hypot(screenX - sp.x, screenY - sp.y) <= GRIP_HIT_RADIUS_PX) {
                return { ...g, entity: ent };
            }
        }
        return null;
    }

    function hitTestAngleDimensionGrip(screenX, screenY) {
        for (const ent of entities) {
            if (ent.type !== 'dimension' || ent.kind !== 'angle') continue;
            const grip = hitTestGrip(screenX, screenY, ent);
            if (grip) return grip;
        }
        return null;
    }

    function applyGripModification(grip, targetPt) {
        const ent = grip.entity;
        const init = grip.initialState;
        const dx = targetPt.x - grip.startWorld.x;
        const dy = targetPt.y - grip.startWorld.y;

        if (ent.type === 'line') {
            if (grip.id === 'start') {
                ent.x1 = targetPt.x; ent.y1 = targetPt.y;
            } else if (grip.id === 'end') {
                ent.x2 = targetPt.x; ent.y2 = targetPt.y;
            } else if (grip.id === 'mid') {
                ent.x1 = init.x1 + dx; ent.y1 = init.y1 + dy;
                ent.x2 = init.x2 + dx; ent.y2 = init.y2 + dy;
            }
        } else if (ent.type === 'rect') {
            if (grip.id === 'center') {
                ent.x = init.x + dx; ent.y = init.y + dy;
            } else {
                let anchorX, anchorY;
                if (grip.id === 'c0') { anchorX = init.x + init.w; anchorY = init.y + init.h; }
                else if (grip.id === 'c1') { anchorX = init.x; anchorY = init.y + init.h; }
                else if (grip.id === 'c2') { anchorX = init.x; anchorY = init.y; }
                else if (grip.id === 'c3') { anchorX = init.x + init.w; anchorY = init.y; }

                ent.x = Math.min(anchorX, targetPt.x);
                ent.y = Math.min(anchorY, targetPt.y);
                ent.w = Math.max(anchorX, targetPt.x) - ent.x;
                ent.h = Math.max(anchorY, targetPt.y) - ent.y;
            }
        } else if (ent.type === 'pline') {
            if (grip.id === 'center') {
                for (let i = 0; i < ent.points.length; i++) {
                    ent.points[i].x = init.points[i].x + dx;
                    ent.points[i].y = init.points[i].y + dy;
                }
            } else if (grip.type === 'vertex') {
                ent.points[grip.index] = { x: targetPt.x, y: targetPt.y };
            } else if (grip.type === 'edge') {
                const i1 = grip.index;
                const i2 = (grip.index + 1) % init.points.length;
                ent.points[i1] = { x: init.points[i1].x + dx, y: init.points[i1].y + dy };
                ent.points[i2] = { x: init.points[i2].x + dx, y: init.points[i2].y + dy };
            }
        } else if (ent.type === 'circle') {
            if (grip.id === 'center') {
                ent.cx = init.cx + dx; ent.cy = init.cy + dy;
            } else {
                ent.r = Math.max(0.001, Math.hypot(targetPt.x - ent.cx, targetPt.y - ent.cy));
            }
        } else if (ent.type === 'ellipse') {
            if (grip.id === 'center') {
                ent.cx = init.cx + dx; ent.cy = init.cy + dy;
            } else if (grip.id === 'qx1' || grip.id === 'qx2') {
                ent.rx = Math.max(0.001, Math.abs(targetPt.x - ent.cx));
            } else if (grip.id === 'qy1' || grip.id === 'qy2') {
                ent.ry = Math.max(0.001, Math.abs(targetPt.y - ent.cy));
            }
        } else if (ent.type === 'arc') {
            if (grip.id === 'center') {
                ent.cx = init.cx + dx; ent.cy = init.cy + dy;
            } else if (grip.id === 'start') {
                ent.r = Math.max(0.001, Math.hypot(targetPt.x - ent.cx, targetPt.y - ent.cy));
                ent.startAzi = calculateAzimuthRad(targetPt.x - ent.cx, targetPt.y - ent.cy);
            } else if (grip.id === 'end') {
                ent.r = Math.max(0.001, Math.hypot(targetPt.x - ent.cx, targetPt.y - ent.cy));
                ent.endAzi = calculateAzimuthRad(targetPt.x - ent.cx, targetPt.y - ent.cy);
            } else if (grip.id === 'mid') {
                ent.r = Math.max(0.001, Math.hypot(targetPt.x - ent.cx, targetPt.y - ent.cy));
            }
        } else if (ent.type === 'text') {
            if (grip.type === 'text_box_edge') {
                const bounds = getTextBoxBounds(init);
                const nextBounds = { ...bounds };
                if (grip.id === 'text-c0' || grip.id === 'text-c3' || grip.id === 'text-m3') nextBounds.minX = targetPt.x;
                if (grip.id === 'text-c0' || grip.id === 'text-c1' || grip.id === 'text-m0') nextBounds.minY = targetPt.y;
                if (grip.id === 'text-c1' || grip.id === 'text-c2' || grip.id === 'text-m1') nextBounds.maxX = targetPt.x;
                if (grip.id === 'text-c2' || grip.id === 'text-c3' || grip.id === 'text-m2') nextBounds.maxY = targetPt.y;
                const minWidth = Math.max(0.001, bounds.defaultWidth);
                const minHeight = Math.max(0.001, bounds.defaultHeight);
                nextBounds.minX = Math.min(nextBounds.minX, nextBounds.maxX - minWidth);
                nextBounds.maxX = Math.max(nextBounds.maxX, nextBounds.minX + minWidth);
                nextBounds.minY = Math.min(nextBounds.minY, nextBounds.maxY - minHeight);
                nextBounds.maxY = Math.max(nextBounds.maxY, nextBounds.minY + minHeight);
                const { horizontal, vertical } = getTextJustification(ent);
                ent.x = horizontal === 'left'
                    ? nextBounds.minX
                    : horizontal === 'right'
                    ? nextBounds.maxX
                    : (nextBounds.minX + nextBounds.maxX) / 2;
                ent.y = vertical === 'top'
                    ? nextBounds.maxY
                    : vertical === 'bottom'
                    ? nextBounds.minY
                    : (nextBounds.minY + nextBounds.maxY) / 2;
                ent.textBox = {
                    minX: nextBounds.minX - ent.x,
                    minY: nextBounds.minY - ent.y,
                    maxX: nextBounds.maxX - ent.x,
                    maxY: nextBounds.maxY - ent.y
                };
            } else {
                ent.x = init.x + dx; ent.y = init.y + dy;
            }
        } else if (ent.type === 'point') {
            ent.x = targetPt.x; ent.y = targetPt.y;
        } else if (ent.type === 'dimension' && ent.kind === 'angle') {
            if (grip.id === 'center') {
                ent.cx = init.cx + dx; ent.cy = init.cy + dy;
                const ray1 = { x: init.ray1X ?? ent.ray1X, y: init.ray1Y ?? ent.ray1Y };
                const ray2 = { x: init.ray2X ?? ent.ray2X, y: init.ray2Y ?? ent.ray2Y };
                ent.startAzi = calculateAzimuthRad(ray1.x - ent.cx, ray1.y - ent.cy);
                ent.endAzi = calculateAzimuthRad(ray2.x - ent.cx, ray2.y - ent.cy);
            } else if (grip.id === 'start') {
                ent.ray1X = targetPt.x;
                ent.ray1Y = targetPt.y;
                ent.startAzi = calculateAzimuthRad(targetPt.x - ent.cx, targetPt.y - ent.cy);
            } else if (grip.id === 'end') {
                ent.ray2X = targetPt.x;
                ent.ray2Y = targetPt.y;
                ent.endAzi = calculateAzimuthRad(targetPt.x - ent.cx, targetPt.y - ent.cy);
            } else if (grip.id === 'position') {
                ent.textX = targetPt.x;
                ent.textY = targetPt.y;
            }
        } else if (ent.type === 'dimension') {
            if (grip.id === 'start') {
                ent.x1 = targetPt.x; ent.y1 = targetPt.y;
            } else if (grip.id === 'end') {
                ent.x2 = targetPt.x; ent.y2 = targetPt.y;
            } else if (grip.id === 'position') {
                ent.textX = targetPt.x;
                ent.textY = targetPt.y;
                const dimensionDx = ent.x2 - ent.x1;
                const dimensionDy = ent.y2 - ent.y1;
                const dimensionLength = Math.hypot(dimensionDx, dimensionDy);
                if (dimensionLength > 1e-9) {
                    ent.offset = (targetPt.x - ent.x1) * (-dimensionDy / dimensionLength) +
                        (targetPt.y - ent.y1) * (dimensionDx / dimensionLength);
                }
            }
        }

        updatePropertiesPalette();
    }

    function hitTestEntity(worldPt, screenPt) {
        for (let i = entities.length - 1; i >= 0; i--) {
            const ent = entities[i];
            if (ent.hatch && isPointInsideHatch(worldPt, ent)) {
                return { entity: ent, hatch: ent, segmentIndex: null };
            }
            if (ent.type === 'dxf-import') {
                const bounds = getEntityBounds(ent);
                if (!bounds) continue;
                const inside = worldPt.x >= bounds.minX && worldPt.x <= bounds.maxX && worldPt.y >= bounds.minY && worldPt.y <= bounds.maxY;
                if (inside) return { entity: ent, segmentIndex: null };
                continue;
            }
            if (ent.type === 'line') {
                const res = pointToSegmentDistance(worldPt.x, worldPt.y, ent.x1, ent.y1, ent.x2, ent.y2);
                if (res.dist * camera.zoom <= SELECT_TOLERANCE_PX) return { entity: ent, segmentIndex: null };
            } else if (ent.type === 'pline') {
                const segs = getEntitySegments(ent);
                for (const seg of segs) {
                    const res = pointToSegmentDistance(worldPt.x, worldPt.y, seg.p1.x, seg.p1.y, seg.p2.x, seg.p2.y);
                    if (res.dist * camera.zoom <= SELECT_TOLERANCE_PX) {
                        return { entity: ent, segmentIndex: seg.index };
                    }
                }
            } else if (ent.type === 'rect') {
                const segs = getEntitySegments(ent);
                for (const seg of segs) {
                    const res = pointToSegmentDistance(worldPt.x, worldPt.y, seg.p1.x, seg.p1.y, seg.p2.x, seg.p2.y);
                    if (res.dist * camera.zoom <= SELECT_TOLERANCE_PX) return { entity: ent, segmentIndex: null };
                }
            } else if (ent.type === 'circle') {
                const distToCenter = Math.hypot(worldPt.x - ent.cx, worldPt.y - ent.cy);
                if (Math.abs(distToCenter - ent.r) * camera.zoom <= SELECT_TOLERANCE_PX) return { entity: ent, segmentIndex: null };
            } else if (ent.type === 'arc') {
                const distToCenter = Math.hypot(worldPt.x - ent.cx, worldPt.y - ent.cy);
                if (Math.abs(distToCenter - ent.r) * camera.zoom <= SELECT_TOLERANCE_PX) {
                    const azi = calculateAzimuthRad(worldPt.x - ent.cx, worldPt.y - ent.cy);
                    if (isAzimuthBetween(azi, ent.startAzi, ent.endAzi)) {
                        return { entity: ent, segmentIndex: null };
                    }
                }
            } else if (ent.type === 'ellipse') {
                const normDist = Math.hypot((worldPt.x - ent.cx) / ent.rx, (worldPt.y - ent.cy) / ent.ry);
                const approxDistPx = Math.abs(normDist - 1) * Math.min(ent.rx, ent.ry) * camera.zoom;
                if (approxDistPx <= SELECT_TOLERANCE_PX) return { entity: ent, segmentIndex: null };
            } else if (ent.type === 'text') {
                const text = String(ent.text || '');
                const height = Math.max(0.001, Number(ent.height ?? 0.1));
                const fontPx = Math.max(8, height * Math.max(0.5, camera.zoom || 1));
                const bounds = getTextBoxBounds(ent);
                const anchorScreen = worldToScreen(ent.x, ent.y);
                const screenX = screenPt.x;
                const screenY = screenPt.y;
                const localX = screenX - anchorScreen.x;
                const localY = screenY - anchorScreen.y;
                const rotation = ((Number(ent.rotation) || 0) * Math.PI) / 180;
                const cos = Math.cos(-rotation);
                const sin = Math.sin(-rotation);
                const rx = localX * cos - localY * sin;
                const ry = localX * sin + localY * cos;
                const minX = (bounds.minX - ent.x) * camera.zoom;
                const maxX = (bounds.maxX - ent.x) * camera.zoom;
                // Canvas Y grows downward, while model-space Y grows upward.
                const minY = (ent.y - bounds.maxY) * camera.zoom;
                const maxY = (ent.y - bounds.minY) * camera.zoom;
                if (rx >= minX && rx <= maxX && ry >= minY && ry <= maxY) {
                    return { entity: ent, segmentIndex: null };
                }
            } else if (ent.type === 'point') {
                const dist = Math.hypot(worldPt.x - ent.x, worldPt.y - ent.y);
                if (dist * camera.zoom <= SELECT_TOLERANCE_PX + 4) return { entity: ent, segmentIndex: null };
            } else if (ent.type === 'dimension' && ent.kind === 'angle') {
                const textPosition = getDimensionTextPosition(ent);
                const centerDist = Math.hypot(worldPt.x - ent.cx, worldPt.y - ent.cy);
                const textDist = Math.hypot(worldPt.x - textPosition.x, worldPt.y - textPosition.y);
                const ray1 = getAngleDimensionRayEnd(ent, 'start');
                const ray2 = getAngleDimensionRayEnd(ent, 'end');
                const ray1Distance = pointToSegmentDistance(worldPt.x, worldPt.y, ent.cx, ent.cy, ray1.x, ray1.y);
                const ray2Distance = pointToSegmentDistance(worldPt.x, worldPt.y, ent.cx, ent.cy, ray2.x, ray2.y);
                const azi = calculateAzimuthRad(worldPt.x - ent.cx, worldPt.y - ent.cy);
                if ((Math.abs(centerDist - ent.r) * camera.zoom <= SELECT_TOLERANCE_PX + 6 && isAngleDimensionArcPoint(ent, azi)) ||
                    textDist * camera.zoom <= 18 ||
                    ray1Distance.dist * camera.zoom <= SELECT_TOLERANCE_PX + 4 ||
                    ray2Distance.dist * camera.zoom <= SELECT_TOLERANCE_PX + 4) {
                    return { entity: ent, segmentIndex: null };
                }
            } else if (ent.type === 'dimension') {
                const dx = ent.x2 - ent.x1;
                const dy = ent.y2 - ent.y1;
                const length = Math.hypot(dx, dy);
                if (length > 1e-9) {
                    const nx = -dy / length;
                    const ny = dx / length;
                    const d1 = { x: ent.x1 + nx * ent.offset, y: ent.y1 + ny * ent.offset };
                    const d2 = { x: ent.x2 + nx * ent.offset, y: ent.y2 + ny * ent.offset };
                    const distance = pointToSegmentDistance(worldPt.x, worldPt.y, d1.x, d1.y, d2.x, d2.y);
                    const textPosition = getDimensionTextPosition(ent);
                    if (distance.dist * camera.zoom <= SELECT_TOLERANCE_PX ||
                        Math.hypot(worldPt.x - textPosition.x, worldPt.y - textPosition.y) * camera.zoom <= 18) {
                        return { entity: ent, segmentIndex: null };
                    }
                }
            }
        }
        return null;
    }

    function getEntityBounds(ent) {
        const points = [];
        if (ent.type === 'dxf-import') {
            const childBounds = (ent.children || []).map(getEntityBounds).filter(Boolean);
            if (!childBounds.length) return null;
            return {
                minX: Math.min(...childBounds.map(bound => bound.minX)),
                minY: Math.min(...childBounds.map(bound => bound.minY)),
                maxX: Math.max(...childBounds.map(bound => bound.maxX)),
                maxY: Math.max(...childBounds.map(bound => bound.maxY))
            };
        }
        if (ent.type === 'text') {
            const bounds = getTextBoxBounds(ent);
            points.push(
                { x: bounds.minX, y: bounds.minY },
                { x: bounds.maxX, y: bounds.maxY }
            );
        } else if (ent.type === 'line') {
            points.push({ x: ent.x1, y: ent.y1 }, { x: ent.x2, y: ent.y2 });
        } else if (ent.type === 'rect') {
            points.push(
                { x: ent.x, y: ent.y },
                { x: ent.x + ent.w, y: ent.y + ent.h }
            );
        } else if (ent.type === 'pline' || ent.type === 'polygon' || ent.type === 'hatch-strips') {
            points.push(...(ent.points || []));
        } else if (ent.type === 'circle' || ent.type === 'arc') {
            points.push(
                { x: ent.cx - ent.r, y: ent.cy - ent.r },
                { x: ent.cx + ent.r, y: ent.cy + ent.r }
            );
        } else if (ent.type === 'ellipse') {
            points.push(
                { x: ent.cx - ent.rx, y: ent.cy - ent.ry },
                { x: ent.cx + ent.rx, y: ent.cy + ent.ry }
            );
        } else if (ent.type === 'point') {
            points.push({ x: ent.x, y: ent.y });
        } else if (ent.type === 'dimension' && ent.kind === 'angle') {
            points.push(
                { x: ent.cx, y: ent.cy },
                getAngleDimensionRayEnd(ent, 'start'),
                getAngleDimensionRayEnd(ent, 'end'),
                getDimensionTextPosition(ent)
            );
        } else if (ent.type === 'dimension') {
            const dx = ent.x2 - ent.x1;
            const dy = ent.y2 - ent.y1;
            const length = Math.hypot(dx, dy);
            if (length > 1e-9) {
                const nx = -dy / length;
                const ny = dx / length;
                points.push(
                    { x: ent.x1, y: ent.y1 },
                    { x: ent.x2, y: ent.y2 },
                    { x: ent.x1 + nx * ent.offset, y: ent.y1 + ny * ent.offset },
                    { x: ent.x2 + nx * ent.offset, y: ent.y2 + ny * ent.offset },
                    getDimensionTextPosition(ent)
                );
            }
        }
        if (!points.length) return null;
        return {
            minX: Math.min(...points.map(point => point.x)),
            minY: Math.min(...points.map(point => point.y)),
            maxX: Math.max(...points.map(point => point.x)),
            maxY: Math.max(...points.map(point => point.y))
        };
    }

    function zoomToExtents() {
        const bounds = entities.map(getEntityBounds).filter(Boolean);
        if (!bounds.length) {
            camera.x = 0;
            camera.y = 0;
            camera.zoom = 1;
        } else {
            const minX = Math.min(...bounds.map(bound => bound.minX));
            const minY = Math.min(...bounds.map(bound => bound.minY));
            const maxX = Math.max(...bounds.map(bound => bound.maxX));
            const maxY = Math.max(...bounds.map(bound => bound.maxY));
            const centerX = (minX + maxX) / 2;
            const centerY = (minY + maxY) / 2;
            const width = Math.max(maxX - minX, 1);
            const height = Math.max(maxY - minY, 1);
            const availableWidth = Math.max(canvas.width, 1);
            const availableHeight = Math.max(canvas.height, 1);

            camera.zoom = Math.max(0.05, Math.min(availableWidth / width, availableHeight / height, MAX_ZOOM));
            camera.x = -centerX * camera.zoom;
            camera.y = centerY * camera.zoom;
        }
        localStorage.setItem('cad_zoom', String(camera.zoom));
        statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
        render();
        showToast('Zoom extents applied.', 'info', 1500);
    }

    function isEntityInSelectionBox(ent, start, end) {
        const bounds = getEntityBounds(ent);
        if (!bounds) return false;

        const boxMinX = Math.min(start.x, end.x);
        const boxMinY = Math.min(start.y, end.y);
        const boxMaxX = Math.max(start.x, end.x);
        const boxMaxY = Math.max(start.y, end.y);

        return bounds.minX >= boxMinX && bounds.maxX <= boxMaxX &&
            bounds.minY >= boxMinY && bounds.maxY <= boxMaxY;
    }

    function translateEntity(ent, offsetX, offsetY) {
        if (ent.type === 'line') {
            ent.x1 += offsetX; ent.y1 += offsetY;
            ent.x2 += offsetX; ent.y2 += offsetY;
        } else if (ent.type === 'rect') {
            ent.x += offsetX; ent.y += offsetY;
        } else if (ent.type === 'pline') {
            ent.points.forEach(point => { point.x += offsetX; point.y += offsetY; });
        } else if (ent.type === 'dxf-import') {
            if (Array.isArray(ent.children)) {
                ent.children.forEach(child => translateEntity(child, offsetX, offsetY));
            }
            if (Array.isArray(ent.labels)) {
                ent.labels.forEach(label => {
                    label.x += offsetX;
                    label.y += offsetY;
                });
            }
        } else if (['circle', 'ellipse', 'arc'].includes(ent.type)) {
            ent.cx += offsetX; ent.cy += offsetY;
        } else if (ent.type === 'text') {
            ent.x += offsetX; ent.y += offsetY;
        } else if (ent.type === 'point') {
            ent.x += offsetX; ent.y += offsetY;
        } else if (ent.type === 'dimension') {
            if (ent.kind === 'angle') {
                const ray1End = getAngleDimensionRayEnd(ent, 'start');
                const ray2End = getAngleDimensionRayEnd(ent, 'end');
                const textPosition = getDimensionTextPosition(ent);
                ent.cx += offsetX; ent.cy += offsetY;
                ent.ray1X = ray1End.x + offsetX;
                ent.ray1Y = ray1End.y + offsetY;
                ent.ray2X = ray2End.x + offsetX;
                ent.ray2Y = ray2End.y + offsetY;
                ent.textX = textPosition.x + offsetX;
                ent.textY = textPosition.y + offsetY;
            } else {
                ent.x1 += offsetX; ent.y1 += offsetY;
                ent.x2 += offsetX; ent.y2 += offsetY;
                if (Number.isFinite(ent.textX) && Number.isFinite(ent.textY)) {
                    ent.textX += offsetX;
                    ent.textY += offsetY;
                }
            }
        }
        return ent;
    }

    function applyObjectMove(move, targetPoint) {
        const offsetX = targetPoint.x - move.startWorld.x;
        const offsetY = targetPoint.y - move.startWorld.y;
        move.initialStates.forEach((initialState, entity) => {
            const movedEntity = translateEntity(JSON.parse(JSON.stringify(initialState)), offsetX, offsetY);
            Object.keys(entity).forEach(key => delete entity[key]);
            Object.assign(entity, movedEntity);
        });
        move.changed = Math.abs(offsetX) > 1e-9 || Math.abs(offsetY) > 1e-9;
    }

    function getEntitiesCenter(entityList) {
        const bounds = entityList.map(getEntityBounds).filter(Boolean);
        if (!bounds.length) return { x: 0, y: 0 };
        return {
            x: (Math.min(...bounds.map(bound => bound.minX)) + Math.max(...bounds.map(bound => bound.maxX))) / 2,
            y: (Math.min(...bounds.map(bound => bound.minY)) + Math.max(...bounds.map(bound => bound.maxY))) / 2
        };
    }

    function getPastePreviewEntities() {
        if (!pastePreview) return [];
        const offsetX = pastePreview.target.x - pastePreview.anchor.x;
        const offsetY = pastePreview.target.y - pastePreview.anchor.y;
        return pastePreview.source.map(entity => translateEntity(
            JSON.parse(JSON.stringify(entity)), offsetX, offsetY
        ));
    }

    function startMoveCommand() {
        if (!selectedEntities.size) {
            showToast('Select one or more objects to move.', 'warning', 1800);
            return;
        }
        moveCommand = {
            source: [...selectedEntities].map(entity => ({
                entity,
                state: JSON.parse(JSON.stringify(entity))
            })),
            basePoint: null,
            targetPoint: null
        };
        setActiveToolbarButton('btn-move');
        statusMode.innerText = 'MOVE: BASE POINT';
        showToast('Specify the base point.', 'info', 2200);
        primeActiveSnap();
        render();
    }

    function getMovePreviewEntities() {
        if (!moveCommand || !moveCommand.basePoint || !moveCommand.targetPoint) return [];
        const offsetX = moveCommand.targetPoint.x - moveCommand.basePoint.x;
        const offsetY = moveCommand.targetPoint.y - moveCommand.basePoint.y;
        return moveCommand.source.map(item => translateEntity(
            JSON.parse(JSON.stringify(item.state)), offsetX, offsetY
        ));
    }

    function scaleEntity(ent, basePoint, factor) {
        const scalePoint = point => ({
            x: basePoint.x + (point.x - basePoint.x) * factor,
            y: basePoint.y + (point.y - basePoint.y) * factor
        });
        if (ent.type === 'line') {
            const p1 = scalePoint({ x: ent.x1, y: ent.y1 });
            const p2 = scalePoint({ x: ent.x2, y: ent.y2 });
            ent.x1 = p1.x; ent.y1 = p1.y; ent.x2 = p2.x; ent.y2 = p2.y;
        } else if (ent.type === 'rect') {
            const p = scalePoint({ x: ent.x, y: ent.y });
            ent.x = p.x; ent.y = p.y; ent.w *= factor; ent.h *= factor;
        } else if (ent.type === 'pline' || ent.type === 'polygon') {
            ent.points.forEach(point => Object.assign(point, scalePoint(point)));
        } else if (['circle', 'ellipse', 'arc'].includes(ent.type)) {
            const p = scalePoint({ x: ent.cx, y: ent.cy });
            ent.cx = p.x; ent.cy = p.y;
            ent.r = ent.r !== undefined ? Math.abs(ent.r * factor) : ent.r;
            if (ent.rx !== undefined) ent.rx = Math.abs(ent.rx * factor);
            if (ent.ry !== undefined) ent.ry = Math.abs(ent.ry * factor);
        } else if (ent.type === 'text') {
            const p = scalePoint({ x: ent.x, y: ent.y });
            ent.x = p.x; ent.y = p.y;
            ent.height = Math.max(0.001, ent.height * Math.abs(factor));
            if (ent.textBox) {
                ent.textBox.minX *= factor; ent.textBox.minY *= factor;
                ent.textBox.maxX *= factor; ent.textBox.maxY *= factor;
            }
        } else if (ent.type === 'point') {
            const p = scalePoint({ x: ent.x, y: ent.y });
            ent.x = p.x; ent.y = p.y;
        } else if (ent.type === 'dimension') {
            if (ent.kind === 'angle') {
                const center = scalePoint({ x: ent.cx, y: ent.cy });
                const ray1 = scalePoint(getAngleDimensionRayEnd(ent, 'start'));
                const ray2 = scalePoint(getAngleDimensionRayEnd(ent, 'end'));
                const text = scalePoint(getDimensionTextPosition(ent));
                ent.cx = center.x; ent.cy = center.y;
                ent.r *= Math.abs(factor);
                ent.ray1X = ray1.x; ent.ray1Y = ray1.y;
                ent.ray2X = ray2.x; ent.ray2Y = ray2.y;
                ent.textX = text.x; ent.textY = text.y;
            } else {
                const p1 = scalePoint({ x: ent.x1, y: ent.y1 });
                const p2 = scalePoint({ x: ent.x2, y: ent.y2 });
                ent.x1 = p1.x; ent.y1 = p1.y; ent.x2 = p2.x; ent.y2 = p2.y;
                if (Number.isFinite(ent.textX) && Number.isFinite(ent.textY)) {
                    const text = scalePoint({ x: ent.textX, y: ent.textY });
                    ent.textX = text.x; ent.textY = text.y;
                }
                ent.offset *= factor;
            }
        } else if (ent.type === 'dxf-import') {
            (ent.children || []).forEach(child => scaleEntity(child, basePoint, factor));
            (ent.labels || []).forEach(label => {
                const p = scalePoint(label);
                label.x = p.x; label.y = p.y;
            });
        }
        if (ent.hatch) {
            ent.hatch.distance = Math.abs((ent.hatch.distance || 0) * factor);
            ent.hatch.spacing = Math.abs((ent.hatch.spacing || 0) * factor);
        }
        return ent;
    }

    function startScaleCommand() {
        if (!selectedEntities.size) {
            showToast('Select one or more objects to scale.', 'warning', 1800);
            return;
        }
        scaleCommand = {
            source: [...selectedEntities].map(entity => ({ entity, state: JSON.parse(JSON.stringify(entity)) })),
            basePoint: null,
            referencePoint: null,
            factor: null
        };
        setActiveToolbarButton('btn-scale');
        statusMode.innerText = 'SCALE: BASE POINT';
        showToast('Specify a snap base point.', 'info', 2200);
        primeActiveSnap();
        render();
    }

    function getScalePreviewEntities() {
        if (!scaleCommand || !scaleCommand.basePoint || !Number.isFinite(scaleCommand.factor)) return [];
        return scaleCommand.source.map(item => scaleEntity(JSON.parse(JSON.stringify(item.state)), scaleCommand.basePoint, scaleCommand.factor));
    }

    // Offset geometry preserves shared miter joins between consecutive polyline segments.
    function getOffsetEntity(entity, distance, sidePoint) {
        const offset = Math.abs(distance);
        const result = JSON.parse(JSON.stringify(entity));
        const style = { color: entity.color, width: entity.width };

        if (entity.type === 'line') {
            const dx = entity.x2 - entity.x1;
            const dy = entity.y2 - entity.y1;
            const length = Math.hypot(dx, dy);
            if (length < 1e-9) return null;
            const normal = { x: -dy / length, y: dx / length };
            const midpoint = { x: (entity.x1 + entity.x2) / 2, y: (entity.y1 + entity.y2) / 2 };
            const sign = ((sidePoint.x - midpoint.x) * normal.x + (sidePoint.y - midpoint.y) * normal.y) < 0 ? -1 : 1;
            const shiftX = normal.x * offset * sign;
            const shiftY = normal.y * offset * sign;
            result.x1 += shiftX; result.y1 += shiftY;
            result.x2 += shiftX; result.y2 += shiftY;
        } else if (entity.type === 'rect') {
            const minX = Math.min(entity.x, entity.x + entity.w);
            const maxX = Math.max(entity.x, entity.x + entity.w);
            const minY = Math.min(entity.y, entity.y + entity.h);
            const maxY = Math.max(entity.y, entity.y + entity.h);
            const inside = sidePoint.x >= minX && sidePoint.x <= maxX && sidePoint.y >= minY && sidePoint.y <= maxY;
            const sign = inside ? -1 : 1;
            const x1 = minX - offset * sign;
            const y1 = minY - offset * sign;
            const x2 = maxX + offset * sign;
            const y2 = maxY + offset * sign;
            if (x2 <= x1 || y2 <= y1) return null;
            result.x = x1; result.y = y1; result.w = x2 - x1; result.h = y2 - y1;
        } else if (entity.type === 'pline') {
            if (!entity.points || entity.points.length < 2) return null;
            const sourcePoints = entity.points;
            const segmentCount = entity.closed ? sourcePoints.length : sourcePoints.length - 1;
            const segments = [];
            let closestSide = null;
            let closestDistance = Infinity;
            for (let index = 0; index < segmentCount; index++) {
                const start = sourcePoints[index];
                const end = sourcePoints[(index + 1) % sourcePoints.length];
                const dx = end.x - start.x;
                const dy = end.y - start.y;
                const length = Math.hypot(dx, dy);
                if (length < 1e-9) continue;
                const normal = { x: -dy / length, y: dx / length };
                const nearest = pointToSegmentDistance(sidePoint.x, sidePoint.y, start.x, start.y, end.x, end.y);
                if (nearest.dist < closestDistance) {
                    closestDistance = nearest.dist;
                    closestSide = (sidePoint.x - nearest.x) * normal.x + (sidePoint.y - nearest.y) * normal.y;
                }
                segments.push({ start, end, normal });
            }
            if (!segments.length || closestSide === null || Math.abs(closestSide) < 1e-9) return null;
            const sign = closestSide < 0 ? -1 : 1;
            const offsetLines = segments.map(segment => ({
                start: {
                    x: segment.start.x + segment.normal.x * offset * sign,
                    y: segment.start.y + segment.normal.y * offset * sign
                },
                end: {
                    x: segment.end.x + segment.normal.x * offset * sign,
                    y: segment.end.y + segment.normal.y * offset * sign
                }
            }));
            const maxMiterLength = Math.max(offset * 4, offset + 1);
            const intersectLines = (first, second) => {
                const firstDx = first.end.x - first.start.x;
                const firstDy = first.end.y - first.start.y;
                const secondDx = second.end.x - second.start.x;
                const secondDy = second.end.y - second.start.y;
                const denominator = firstDx * secondDy - firstDy * secondDx;
                if (Math.abs(denominator) < 1e-9) return null;
                const t = ((second.start.x - first.start.x) * secondDy - (second.start.y - first.start.y) * secondDx) / denominator;
                const intersection = { x: first.start.x + t * firstDx, y: first.start.y + t * firstDy };
                const distanceFromFirst = Math.hypot(intersection.x - first.end.x, intersection.y - first.end.y);
                const distanceFromSecond = Math.hypot(intersection.x - second.start.x, intersection.y - second.start.y);
                return Math.max(distanceFromFirst, distanceFromSecond) <= maxMiterLength ? intersection : null;
            };
            result.points = sourcePoints.map((point, index) => {
                if (!entity.closed && index === 0) return { ...offsetLines[0].start };
                if (!entity.closed && index === sourcePoints.length - 1) return { ...offsetLines[segmentCount - 1].end };
                const previousIndex = (index - 1 + segmentCount) % segmentCount;
                const intersection = intersectLines(offsetLines[previousIndex], offsetLines[index % segmentCount]);
                if (intersection) return intersection;
                const firstNormal = segments[previousIndex].normal;
                const secondNormal = segments[index % segmentCount].normal;
                return {
                    x: point.x + (firstNormal.x + secondNormal.x) * offset * sign / 2,
                    y: point.y + (firstNormal.y + secondNormal.y) * offset * sign / 2
                };
            });
        } else if (entity.type === 'circle' || entity.type === 'arc') {
            const outward = Math.hypot(sidePoint.x - entity.cx, sidePoint.y - entity.cy) >= entity.r;
            result.r = entity.r + (outward ? offset : -offset);
            if (result.r <= 0) return null;
        } else if (entity.type === 'ellipse') {
            const outward = Math.hypot(sidePoint.x - entity.cx, sidePoint.y - entity.cy) >= Math.min(entity.rx, entity.ry);
            const sign = outward ? 1 : -1;
            result.rx += sign * offset;
            result.ry += sign * offset;
            if (result.rx <= 0 || result.ry <= 0) return null;
        } else {
            return null;
        }
        return Object.assign(result, style);
    }

    function getRememberedOffsetDistance(defaultValue = 10) {
        const rawValue = localStorage.getItem('cad_offset_distance');
        if (rawValue === null || rawValue === undefined || rawValue === '') {
            return defaultValue;
        }
        const parsed = parseStrictFloat(rawValue, NaN);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : defaultValue;
    }

    function rememberOffsetDistance(distance) {
        if (Number.isFinite(distance) && distance > 0) {
            localStorage.setItem('cad_offset_distance', String(distance));
        }
    }

    function startOffsetCommand() {
        if (!selectedEntity || selectedEntities.size !== 1) {
            showToast('Select one object to offset.', 'warning', 1800);
            return;
        }
        const rawDistance = window.prompt('Offset distance:', String(getRememberedOffsetDistance(10)));
        if (rawDistance === null) return;
        const distance = parseStrictFloat(rawDistance, NaN);
        if (!Number.isFinite(distance) || distance <= 0) {
            showToast('Offset distance must be greater than zero.', 'error', 1800);
            return;
        }
        rememberOffsetDistance(distance);
        offsetCommand = { source: selectedEntity, distance };
        setActiveToolbarButton('btn-offset');
        statusMode.innerText = 'OFFSET: SIDE';
        showToast('Click the side for the offset.', 'info', 2200);
        render();
    }

    function trimEntityAtPoint(entity, point) {
        if (!entity || !point) return null;
        const trimTolerance = Math.max(1e-4, 1.5 / Math.max(camera.zoom, 0.05));

        if (entity.type === 'line') {
            const start = { x: entity.x1, y: entity.y1 };
            const end = { x: entity.x2, y: entity.y2 };
            const projection = pointToSegmentDistance(point.x, point.y, start.x, start.y, end.x, end.y);
            if (projection.dist > trimTolerance) return null;
            const keepStart = Math.hypot(point.x - start.x, point.y - start.y) <= Math.hypot(point.x - end.x, point.y - end.y);
            if (keepStart) {
                return { ...entity, x2: projection.x, y2: projection.y };
            }
            return { ...entity, x1: projection.x, y1: projection.y };
        }

        if (entity.type === 'pline' && entity.points && entity.points.length >= 2) {
            let bestSegmentIndex = -1;
            let bestProjection = null;
            let bestDistance = Infinity;
            for (let index = 0; index < entity.points.length - 1; index++) {
                const start = entity.points[index];
                const end = entity.points[index + 1];
                const projection = pointToSegmentDistance(point.x, point.y, start.x, start.y, end.x, end.y);
                if (projection.dist < bestDistance) {
                    bestDistance = projection.dist;
                    bestSegmentIndex = index;
                    bestProjection = projection;
                }
            }
            if (bestSegmentIndex === -1 || !bestProjection || bestDistance > trimTolerance) return null;

            const cutPoint = { x: bestProjection.x, y: bestProjection.y };
            const keptPoints = entity.points.slice(0, bestSegmentIndex + 1);
            if (keptPoints.length === 0) return null;
            keptPoints.push(cutPoint);
            return { ...entity, points: keptPoints, closed: false };
        }

        return null;
    }

    function startTrimCommand() {
        if (!selectedEntity || selectedEntities.size !== 1) {
            showToast('Select one line or polyline to trim.', 'warning', 1800);
            return;
        }
        if (!['line', 'pline'].includes(selectedEntity.type)) {
            showToast('Trim works on lines and polylines only.', 'warning', 1800);
            return;
        }
        trimCommand = { source: selectedEntity };
        setActiveToolbarButton('btn-trim');
        statusMode.innerText = 'TRIM: PICK POINT';
        showToast('Click the trim point along the selected object.', 'info', 2200);
        render();
    }

    function createDistanceDimension(firstPoint, secondPoint, dimensionPoint) {
        const dx = secondPoint.x - firstPoint.x;
        const dy = secondPoint.y - firstPoint.y;
        const length = Math.hypot(dx, dy);
        if (length < 1e-9) return null;
        const normal = { x: -dy / length, y: dx / length };
        const offset = (dimensionPoint.x - firstPoint.x) * normal.x + (dimensionPoint.y - firstPoint.y) * normal.y;
        return {
            type: 'dimension',
            kind: 'distance',
            x1: firstPoint.x,
            y1: firstPoint.y,
            x2: secondPoint.x,
            y2: secondPoint.y,
            offset,
            textX: dimensionPoint.x,
            textY: dimensionPoint.y,
            decimals: 2,
            color: document.getElementById('strokeColor').value,
            width: Math.max(1, parseInt(document.getElementById('lineWidth').value))
        };
    }

    function createAngleDimension(vertex, firstPoint, secondPoint, textPoint) {
        const dx1 = firstPoint.x - vertex.x;
        const dy1 = firstPoint.y - vertex.y;
        const dx2 = secondPoint.x - vertex.x;
        const dy2 = secondPoint.y - vertex.y;
        const length1 = Math.hypot(dx1, dy1);
        const length2 = Math.hypot(dx2, dy2);
        if (length1 < 1e-9 || length2 < 1e-9) return null;

        let startAzi = calculateAzimuthRad(dx1, dy1);
        let endAzi = calculateAzimuthRad(dx2, dy2);
        let delta = normalizeAngle(endAzi - startAzi);
        if (delta > Math.PI) delta = 2 * Math.PI - delta;
        endAzi = startAzi + delta;

        const radius = Math.max(6, Math.min(length1, length2) * 0.8);
        const midAzi = startAzi + delta / 2;
        const position = textPoint || {
            x: vertex.x + Math.sin(midAzi) * (radius + 1.5),
            y: vertex.y + Math.cos(midAzi) * (radius + 1.5)
        };

        return {
            type: 'dimension',
            kind: 'angle',
            cx: vertex.x,
            cy: vertex.y,
            r: radius,
            startAzi,
            endAzi,
            ray1X: firstPoint.x,
            ray1Y: firstPoint.y,
            ray2X: secondPoint.x,
            ray2Y: secondPoint.y,
            angleMode: 'interior',
            textX: position.x,
            textY: position.y,
            decimals: 3,
            color: document.getElementById('strokeColor').value,
            width: Math.max(1, parseInt(document.getElementById('lineWidth').value))
        };
    }

    function getAngleDimensionSweep(ent) {
        const forwardSweep = normalizeAngle(ent.endAzi - ent.startAzi);
        const interiorSweep = forwardSweep <= Math.PI ? forwardSweep : 2 * Math.PI - forwardSweep;
        return ent.angleMode === 'exterior' ? 2 * Math.PI - interiorSweep : interiorSweep;
    }

    function getAngleDimensionArc(ent) {
        const forwardSweep = normalizeAngle(ent.endAzi - ent.startAzi);
        const interiorSweep = Math.min(forwardSweep, 2 * Math.PI - forwardSweep);
        const interiorDirection = forwardSweep <= Math.PI ? 1 : -1;
        const direction = ent.angleMode === 'exterior' ? -interiorDirection : interiorDirection;
        return { direction, sweep: ent.angleMode === 'exterior' ? 2 * Math.PI - interiorSweep : interiorSweep };
    }

    function isAngleDimensionArcPoint(ent, azi) {
        const arc = getAngleDimensionArc(ent);
        const travelled = arc.direction > 0
            ? normalizeAngle(azi - ent.startAzi)
            : normalizeAngle(ent.startAzi - azi);
        return travelled <= arc.sweep + 1e-9;
    }

    function setAngleDimensionMode(ent, mode) {
        const textPosition = getDimensionTextPosition(ent);
        const textRadius = Math.max(ent.r + 1.5, Math.hypot(textPosition.x - ent.cx, textPosition.y - ent.cy));
        ent.angleMode = mode === 'exterior' ? 'exterior' : 'interior';
        const arc = getAngleDimensionArc(ent);
        const midAzi = ent.startAzi + arc.direction * arc.sweep / 2;
        ent.textX = ent.cx + textRadius * Math.sin(midAzi);
        ent.textY = ent.cy + textRadius * Math.cos(midAzi);
    }

    function setAngleDimensionValue(ent, value) {
        const requestedSweep = azimuthValueToRad(value);
        if (!Number.isFinite(requestedSweep) || requestedSweep <= 0 || requestedSweep >= 2 * Math.PI) return false;

        const interiorSweep = ent.angleMode === 'exterior' ? 2 * Math.PI - requestedSweep : requestedSweep;
        if (interiorSweep <= 0 || interiorSweep > Math.PI + 1e-9) return false;

        const arc = getAngleDimensionArc(ent);
        const ray2 = getAngleDimensionRayEnd(ent, 'end');
        const ray2Length = Math.hypot(ray2.x - ent.cx, ray2.y - ent.cy);
        ent.endAzi = ent.startAzi + arc.direction * interiorSweep;
        ent.ray2X = ent.cx + ray2Length * Math.sin(ent.endAzi);
        ent.ray2Y = ent.cy + ray2Length * Math.cos(ent.endAzi);

        const textPosition = getDimensionTextPosition(ent);
        const textRadius = Math.max(ent.r + 1.5, Math.hypot(textPosition.x - ent.cx, textPosition.y - ent.cy));
        const updatedArc = getAngleDimensionArc(ent);
        const midAzi = ent.startAzi + updatedArc.direction * updatedArc.sweep / 2;
        ent.textX = ent.cx + textRadius * Math.sin(midAzi);
        ent.textY = ent.cy + textRadius * Math.cos(midAzi);
        return true;
    }

    function drawCurvedAngleDimensionText(center, textPosition, label, color) {
        const radius = Math.hypot(textPosition.x - center.x, textPosition.y - center.y);
        if (radius < 1) {
            ctx.fillText(label, textPosition.x, textPosition.y);
            return;
        }

        const centerAngle = Math.atan2(textPosition.y - center.y, textPosition.x - center.x);
        const glyphWidths = [...label].map(character => ctx.measureText(character).width);
        const totalAngle = glyphWidths.reduce((sum, width) => sum + width, 0) / radius;
        let angle = centerAngle - totalAngle / 2;

        glyphWidths.forEach((width, index) => {
            const glyphAngle = angle + width / (2 * radius);
            ctx.save();
            ctx.translate(center.x + radius * Math.cos(glyphAngle), center.y + radius * Math.sin(glyphAngle));
            ctx.rotate(glyphAngle + Math.PI / 2);
            ctx.fillText(label[index], 0, 0);
            ctx.restore();
            angle += width / radius;
        });
    }

    function getAngleDimensionRayEnd(ent, ray) {
        const isStart = ray === 'start';
        const x = isStart ? ent.ray1X : ent.ray2X;
        const y = isStart ? ent.ray1Y : ent.ray2Y;
        if (Number.isFinite(x) && Number.isFinite(y)) return { x, y };
        const azi = isStart ? ent.startAzi : ent.endAzi;
        return { x: ent.cx + ent.r * Math.sin(azi), y: ent.cy + ent.r * Math.cos(azi) };
    }

    function getDimensionTextPosition(ent) {
        if (ent && ent.kind === 'angle') {
            if (Number.isFinite(ent.textX) && Number.isFinite(ent.textY)) {
                return { x: ent.textX, y: ent.textY };
            }
            const arc = getAngleDimensionArc(ent);
            const midAzi = ent.startAzi + arc.direction * arc.sweep / 2;
            return {
                x: ent.cx + ent.r * Math.sin(midAzi),
                y: ent.cy + ent.r * Math.cos(midAzi)
            };
        }
        if (Number.isFinite(ent.textX) && Number.isFinite(ent.textY)) {
            return { x: ent.textX, y: ent.textY };
        }
        const dx = ent.x2 - ent.x1;
        const dy = ent.y2 - ent.y1;
        const length = Math.hypot(dx, dy);
        if (length < 1e-9) return { x: ent.x1, y: ent.y1 };
        return {
            x: (ent.x1 + ent.x2) / 2 - dy / length * ent.offset,
            y: (ent.y1 + ent.y2) / 2 + dx / length * ent.offset
        };
    }

    function getDimensionPreview() {
        if (!dimensionCommand || !dimensionCommand.firstPoint || !dimensionCommand.secondPoint) return null;
        const dimensionPoint = dimensionCommand.dimensionPoint || currentMouse;
        return createDistanceDimension(dimensionCommand.firstPoint, dimensionCommand.secondPoint, dimensionPoint);
    }

    function startDimensionCommand() {
        dimensionCommand = { firstPoint: null, secondPoint: null, dimensionPoint: null };
        setActiveToolbarButton('btn-dimension');
        statusMode.innerText = 'DIMENSION: FIRST POINT';
        showToast('Select the first point.', 'info', 2200);
        primeActiveSnap();
        render();
    }

    function startAngleDimensionCommand() {
        angleDimensionCommand = { vertex: null, firstPoint: null, secondPoint: null, textPoint: null };
        setActiveToolbarButton('btn-angle-dimension');
        statusMode.innerText = 'ANGLE DIMENSION: VERTEX';
        showToast('Select the angle vertex.', 'info', 2200);
        primeActiveSnap();
        render();
    }

    function startHatchCommand() {
        if (!selectedEntity || selectedEntities.size !== 1) {
            showToast('Select one object to hatch.', 'warning', 1800);
            return;
        }
        const supported = selectedEntity.type === 'line' || selectedEntity.type === 'rect' ||
            selectedEntity.type === 'circle' || selectedEntity.type === 'ellipse' || selectedEntity.type === 'pline';
        if (!supported) {
            showToast('Hatch supports lines, polylines, rectangles, circles and ellipses.', 'warning', 2200);
            return;
        }
        const rawDistance = window.prompt('Hatch offset distance:', String(getRememberedOffsetDistance(10)));
        if (rawDistance === null) return;
        const distance = parseStrictFloat(rawDistance, NaN);
        if (!Number.isFinite(distance) || distance <= 0) {
            showToast('Hatch offset distance must be greater than zero.', 'error', 1800);
            return;
        }
        rememberOffsetDistance(distance);
        hatchCommand = { entity: selectedEntity, distance };
        setActiveToolbarButton('btn-hatch');
        statusMode.innerText = 'HATCH: SIDE';
        showToast('Click the side for the hatch.', 'info', 2200);
        render();
    }

    function getHatchSideSign(entity, sidePoint) {
        if (entity.type === 'rect') {
            const minX = Math.min(entity.x, entity.x + entity.w);
            const maxX = Math.max(entity.x, entity.x + entity.w);
            const minY = Math.min(entity.y, entity.y + entity.h);
            const maxY = Math.max(entity.y, entity.y + entity.h);
            return sidePoint.x >= minX && sidePoint.x <= maxX && sidePoint.y >= minY && sidePoint.y <= maxY ? -1 : 1;
        }
        if (entity.type === 'circle') {
            return Math.hypot(sidePoint.x - entity.cx, sidePoint.y - entity.cy) < Math.abs(entity.r) ? -1 : 1;
        }
        if (entity.type === 'ellipse') {
            const normalizedDistance = Math.hypot((sidePoint.x - entity.cx) / entity.rx, (sidePoint.y - entity.cy) / entity.ry);
            return normalizedDistance < 1 ? -1 : 1;
        }
        if (entity.type === 'line') {
            const dx = entity.x2 - entity.x1;
            const dy = entity.y2 - entity.y1;
            const length = Math.hypot(dx, dy);
            if (length < 1e-9) return null;
            const midpoint = { x: (entity.x1 + entity.x2) / 2, y: (entity.y1 + entity.y2) / 2 };
            return ((sidePoint.x - midpoint.x) * -dy + (sidePoint.y - midpoint.y) * dx) < 0 ? -1 : 1;
        }
        if (entity.type === 'pline' && entity.points.length > 1) {
            const segmentCount = entity.closed ? entity.points.length : entity.points.length - 1;
            let nearestSide = null;
            let nearestDistance = Infinity;
            for (let index = 0; index < segmentCount; index++) {
                const start = entity.points[index];
                const end = entity.points[(index + 1) % entity.points.length];
                const dx = end.x - start.x;
                const dy = end.y - start.y;
                const length = Math.hypot(dx, dy);
                if (length < 1e-9) continue;
                const nearest = pointToSegmentDistance(sidePoint.x, sidePoint.y, start.x, start.y, end.x, end.y);
                if (nearest.dist < nearestDistance) {
                    nearestDistance = nearest.dist;
                    nearestSide = (sidePoint.x - nearest.x) * -dy / length + (sidePoint.y - nearest.y) * dx / length;
                }
            }
            return nearestSide === null || Math.abs(nearestSide) < 1e-9 ? null : (nearestSide < 0 ? -1 : 1);
        }
        return sidePoint.x >= entity.cx ? 1 : -1;
    }

    function getHatchReferencePoint(entity, sideSign) {
        if (entity.type === 'line') {
            const dx = entity.x2 - entity.x1;
            const dy = entity.y2 - entity.y1;
            const length = Math.hypot(dx, dy) || 1;
            return { x: (entity.x1 + entity.x2) / 2 - dy / length * sideSign, y: (entity.y1 + entity.y2) / 2 + dx / length * sideSign };
        }
        if (entity.type === 'pline' && entity.points.length > 1) {
            const start = entity.points[0];
            const end = entity.points[1];
            const dx = end.x - start.x;
            const dy = end.y - start.y;
            const length = Math.hypot(dx, dy) || 1;
            return { x: (start.x + end.x) / 2 - dy / length * sideSign, y: (start.y + end.y) / 2 + dx / length * sideSign };
        }
        if (entity.type === 'rect') {
            return {
                x: entity.x + entity.w / 2 + (sideSign > 0 ? sideSign * (Math.abs(entity.w) + 1) : 0),
                y: entity.y + entity.h / 2
            };
        }
        if (entity.type === 'circle') {
            return { x: entity.cx + (sideSign > 0 ? sideSign * (Math.abs(entity.r) + 1) : 0), y: entity.cy };
        }
        if (entity.type === 'ellipse') {
            return { x: entity.cx + (sideSign > 0 ? sideSign * (Math.min(Math.abs(entity.rx), Math.abs(entity.ry)) + 1) : 0), y: entity.cy };
        }
        return { x: entity.cx + sideSign, y: entity.cy };
    }

    // Hatch boundaries use the mitered offset polyline so adjacent segment strips never overlap.
    function getHatchBoundary(entity) {
        const hatch = entity.hatch;
        if (!hatch) return null;
        const sideSign = Number(hatch.sideSign) || 1;
        const distance = Math.abs(Number(hatch.distance) || 0);
        if ((entity.type === 'line' || entity.type === 'pline') && distance > 0) {
            const sourcePoints = entity.type === 'line'
                ? [{ x: entity.x1, y: entity.y1 }, { x: entity.x2, y: entity.y2 }]
                : entity.points;
            const segmentCount = entity.type === 'pline' && entity.closed
                ? sourcePoints.length
                : sourcePoints.length - 1;
            const offsetBoundary = entity.type === 'line'
                ? null
                : getOffsetEntity(entity, distance, getHatchReferencePoint(entity, sideSign));
            if (entity.type === 'pline' && (!offsetBoundary || !offsetBoundary.points || offsetBoundary.points.length !== sourcePoints.length)) {
                return null;
            }
            const strips = [];
            for (let index = 0; index < segmentCount; index++) {
                const start = sourcePoints[index];
                const end = sourcePoints[(index + 1) % sourcePoints.length];
                const dx = end.x - start.x;
                const dy = end.y - start.y;
                const length = Math.hypot(dx, dy);
                if (length < 1e-9) continue;
                const normal = { x: -dy / length, y: dx / length };
                const offsetStart = entity.type === 'line'
                    ? { x: start.x + normal.x * distance * sideSign, y: start.y + normal.y * distance * sideSign }
                    : offsetBoundary.points[index];
                const offsetEnd = entity.type === 'line'
                    ? { x: end.x + normal.x * distance * sideSign, y: end.y + normal.y * distance * sideSign }
                    : offsetBoundary.points[(index + 1) % sourcePoints.length];
                strips.push([start, end, offsetEnd, offsetStart]);
            }
            return { type: 'hatch-strips', strips, points: strips.flat() };
        }
        const boundary = distance > 0 ? getOffsetEntity(entity, distance, getHatchReferencePoint(entity, sideSign)) : entity;
        if (!boundary) return null;
        const closedTypes = entity.type === 'rect' || entity.type === 'circle' || entity.type === 'ellipse' ||
            (entity.type === 'pline' && entity.closed);
        if (closedTypes && distance > 0) {
            const sourceBounds = getEntityBounds(entity);
            const offsetBounds = getEntityBounds(boundary);
            const sourceArea = sourceBounds ? (sourceBounds.maxX - sourceBounds.minX) * (sourceBounds.maxY - sourceBounds.minY) : 0;
            const offsetArea = offsetBounds ? (offsetBounds.maxX - offsetBounds.minX) * (offsetBounds.maxY - offsetBounds.minY) : 0;
            const outer = offsetArea >= sourceArea ? boundary : entity;
            const inner = outer === boundary ? entity : boundary;
            return { type: 'hatch-band', outer, inner };
        }
        return boundary;
    }

    function isPointInsideBoundary(point, boundary) {
        if (boundary.type === 'circle') {
            return Math.hypot(point.x - boundary.cx, point.y - boundary.cy) <= Math.abs(boundary.r);
        }
        if (boundary.type === 'ellipse') {
            return ((point.x - boundary.cx) / boundary.rx) ** 2 + ((point.y - boundary.cy) / boundary.ry) ** 2 <= 1;
        }
        if (boundary.type === 'rect') {
            const minX = Math.min(boundary.x, boundary.x + boundary.w);
            const maxX = Math.max(boundary.x, boundary.x + boundary.w);
            const minY = Math.min(boundary.y, boundary.y + boundary.h);
            const maxY = Math.max(boundary.y, boundary.y + boundary.h);
            return point.x >= minX && point.x <= maxX && point.y >= minY && point.y <= maxY;
        }
        const points = boundary.points || [];
        let inside = false;
        for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
            const intersects = ((points[i].y > point.y) !== (points[j].y > point.y)) &&
                point.x < (points[j].x - points[i].x) * (point.y - points[i].y) / (points[j].y - points[i].y) + points[i].x;
            if (intersects) inside = !inside;
        }
        return inside;
    }

    function isPointInsideHatch(point, entity) {
        if (!entity.hatch) return false;
        const hatchBoundary = getHatchBoundary(entity);
        if (!hatchBoundary) return false;
        if (hatchBoundary.type === 'hatch-strips') {
            return hatchBoundary.strips.some(strip => isPointInsideBoundary(point, { type: 'polygon', points: strip }));
        }
        if (hatchBoundary.type === 'hatch-band') {
            return isPointInsideBoundary(point, hatchBoundary.outer) && !isPointInsideBoundary(point, hatchBoundary.inner);
        }
        return isPointInsideBoundary(point, hatchBoundary);
    }

    function getCommandPoint(screenX, screenY, options = {}) {
        activeSnap = findBestSnap(screenX, screenY, null, options);
        if (activeSnap) return { x: activeSnap.worldX, y: activeSnap.worldY };
        const raw = screenToWorld(screenX, screenY);
        if (document.getElementById('snapGrid').checked) {
            const gridSize = getGridSize();
            return {
                x: Math.round(raw.x / gridSize) * gridSize,
                y: Math.round(raw.y / gridSize) * gridSize
            };
        }
        return raw;
    }

    function primeActiveSnap() {
        const screenPoint = worldToScreen(currentMouse.x, currentMouse.y);
        activeSnap = findBestSnap(screenPoint.x, screenPoint.y, null, {});
    }

    function drawGrid() {
        const topLeft = screenToWorld(0, 0);
        const bottomRight = screenToWorld(canvas.width, canvas.height);
        const gridSize = getGridSize();

        const startX = Math.floor(topLeft.x / gridSize) * gridSize;
        const endX = Math.ceil(bottomRight.x / gridSize) * gridSize;
        const startY = Math.floor(bottomRight.y / gridSize) * gridSize;
        const endY = Math.ceil(topLeft.y / gridSize) * gridSize;

        ctx.lineWidth = 1;
        ctx.strokeStyle = '#222';
        ctx.beginPath();
        for (let x = startX; x <= endX; x += gridSize) {
            const p1 = worldToScreen(x, startY);
            const p2 = worldToScreen(x, endY);
            ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
        }
        for (let y = startY; y <= endY; y += gridSize) {
            const p1 = worldToScreen(startX, y);
            const p2 = worldToScreen(endX, y);
            ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
        }
        ctx.stroke();

        const gridSpacingPx = gridSize * camera.zoom;
        if (gridSpacingPx >= 30) {
            const gridLabelDecimals = gridSize < 0.1 ? 2 : (gridSize < 1 ? 1 : 0);
            ctx.save();
            ctx.fillStyle = '#777';
            ctx.font = '10px Consolas, monospace';
            ctx.textBaseline = 'top';
            for (let x = startX; x <= endX; x += gridSize) {
                const screenX = worldToScreen(x, 0).x;
                if (screenX >= 2 && screenX <= canvas.width - 2) {
                    ctx.textAlign = 'center';
                    ctx.fillText(x.toFixed(gridLabelDecimals), screenX, canvas.height - 14);
                }
            }
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            for (let y = startY; y <= endY; y += gridSize) {
                const screenY = worldToScreen(0, y).y;
                if (screenY >= 2 && screenY <= canvas.height - 2) {
                    ctx.fillText(y.toFixed(gridLabelDecimals), 4, screenY);
                }
            }
            ctx.restore();
        }

        const orig = worldToScreen(0, 0);
        ctx.lineWidth = 1.2;
        ctx.strokeStyle = 'rgba(239, 68, 68, 0.4)';
        ctx.beginPath(); ctx.moveTo(0, orig.y); ctx.lineTo(canvas.width, orig.y); ctx.stroke();
        ctx.strokeStyle = 'rgba(34, 197, 94, 0.4)';
        ctx.beginPath(); ctx.moveTo(orig.x, 0); ctx.lineTo(orig.x, canvas.height); ctx.stroke();
    }

    function getPrintFrameSpec() {
        const map = {
            A4: { widthMm: 210, heightMm: 297 },
            A3: { widthMm: 297, heightMm: 420 },
            A2: { widthMm: 420, heightMm: 594 },
            A1: { widthMm: 594, heightMm: 841 },
            A0: { widthMm: 841, heightMm: 1189 }
        };
        const value = String(paperSizeSelect.value || 'A3-L');
        const [base = 'A3', orientation = 'L'] = value.split('-');
        const spec = map[base] || map.A3;
        const portrait = orientation === 'P';
        return {
            name: `${base} ${portrait ? 'Portrait' : 'Landscape'}`,
            widthMm: portrait ? spec.widthMm : spec.heightMm,
            heightMm: portrait ? spec.heightMm : spec.widthMm
        };
    }

    function getPrintFrameGeometry() {
        const spec = getPrintFrameSpec();
        const scale = Number(printScaleSelect.value) || 100;
        const frameWidth = (spec.widthMm / 1000) * scale;
        const frameHeight = (spec.heightMm / 1000) * scale;
        if (!Number.isFinite(paperFrameCenter.x) || !Number.isFinite(paperFrameCenter.y)) {
            const center = screenToWorld(canvas.width / 2, canvas.height / 2);
            paperFrameCenter.x = center.x;
            paperFrameCenter.y = center.y;
        }
        return { spec, frameWidth, frameHeight, centerX: paperFrameCenter.x, centerY: paperFrameCenter.y };
    }

    function drawPrintFrame() {
        const { spec, frameWidth, frameHeight, centerX, centerY } = getPrintFrameGeometry();
        const scale = Number(printScaleSelect.value) || 100;
        const zoom = Math.max(0.05, camera.zoom);
        const realWidth = frameWidth;
        const realHeight = frameHeight;
        const x = centerX - frameWidth / 2;
        const y = centerY - frameHeight / 2;
        const topLeft = worldToScreen(x, y + frameHeight);

        ctx.save();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.55)';
        ctx.lineWidth = Math.max(0.8, 1.4 / zoom);
        ctx.setLineDash([10 / zoom, 6 / zoom]);
        ctx.strokeRect(topLeft.x, topLeft.y, frameWidth * camera.zoom, frameHeight * camera.zoom);
        ctx.setLineDash([]);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.55)';
        ctx.font = '10px Consolas, monospace';
        ctx.textBaseline = 'top';
        ctx.fillText(
            `${spec.name} (${spec.widthMm} x ${spec.heightMm} mm / ${realWidth.toFixed(1)} x ${realHeight.toFixed(1)} units)`,
            topLeft.x + frameWidth * camera.zoom - 268,
            topLeft.y + 6
        );
        ctx.restore();
    }

    function getPrintFrameRectWorld() {
        const { frameWidth, frameHeight, centerX, centerY } = getPrintFrameGeometry();
        return {
            left: centerX - frameWidth / 2,
            right: centerX + frameWidth / 2,
            top: centerY + frameHeight / 2,
            bottom: centerY - frameHeight / 2,
            width: frameWidth,
            height: frameHeight
        };
    }

    function hitTestPrintFrame(screenX, screenY) {
        const rect = getPrintFrameRectWorld();
        const tl = worldToScreen(rect.left, rect.top);
        const br = worldToScreen(rect.right, rect.bottom);
        const left = Math.min(tl.x, br.x);
        const right = Math.max(tl.x, br.x);
        const top = Math.min(tl.y, br.y);
        const bottom = Math.max(tl.y, br.y);
        const onBorder =
            (screenX >= left - paperFrameBorderHitPx && screenX <= right + paperFrameBorderHitPx &&
                Math.abs(screenY - top) <= paperFrameBorderHitPx) ||
            (screenX >= left - paperFrameBorderHitPx && screenX <= right + paperFrameBorderHitPx &&
                Math.abs(screenY - bottom) <= paperFrameBorderHitPx) ||
            (screenY >= top - paperFrameBorderHitPx && screenY <= bottom + paperFrameBorderHitPx &&
                Math.abs(screenX - left) <= paperFrameBorderHitPx) ||
            (screenY >= top - paperFrameBorderHitPx && screenY <= bottom + paperFrameBorderHitPx &&
                Math.abs(screenX - right) <= paperFrameBorderHitPx);
        const inside = screenX >= left && screenX <= right && screenY >= top && screenY <= bottom;
        return { onBorder, inside, rectScreen: { left, right, top, bottom } };
    }

    function getNorthSymbolRect() {
        const x = Math.max(0, Math.min(canvas.width - northSymbolSize, northSymbolPosition.x));
        const y = Math.max(0, Math.min(canvas.height - northSymbolSize, northSymbolPosition.y));
        return { x, y, w: northSymbolSize, h: northSymbolSize };
    }

    function hitTestNorthSymbol(screenX, screenY) {
        const rect = getNorthSymbolRect();
        const centerX = rect.x + rect.w / 2;
        const centerY = rect.y + rect.h / 2;
        const ringRadius = rect.w * 0.28;
        const ringDistance = Math.hypot(screenX - centerX, screenY - centerY);
        const gripX = rect.x + rect.w - northSymbolGripSize * 0.9;
        const gripY = rect.y + rect.h - northSymbolGripSize * 0.9;
        const gripHit = screenX >= gripX - 6 && screenX <= gripX + northSymbolGripSize + 6 &&
            screenY >= gripY - 6 && screenY <= gripY + northSymbolGripSize + 6;
        return ringDistance <= ringRadius + 10 || gripHit;
    }

    function drawNorthSymbol() {
        const rect = getNorthSymbolRect();
        const cx = rect.x + rect.w / 2;
        const cy = rect.y + rect.h / 2;
        const size = rect.w;
        const ringR = size * 0.22;
        const arrowTop = rect.y + 6;
        const arrowBottom = rect.y + size - 11;
        const arrowX = cx;
        const isHover = northSymbolDrag !== null;
        const accent = isHover ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,0.78)';

        ctx.save();
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = accent;
        ctx.fillStyle = 'rgba(12, 12, 12, 0.42)';
        ctx.lineWidth = 1.2;

        ctx.beginPath();
        ctx.arc(cx, cy, ringR + 6, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(arrowX, arrowTop);
        ctx.lineTo(arrowX, arrowBottom);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(arrowX, arrowTop);
        ctx.lineTo(arrowX - 6, arrowTop + 9);
        ctx.lineTo(arrowX + 6, arrowTop + 9);
        ctx.closePath();
        ctx.fillStyle = accent;
        ctx.fill();

        ctx.beginPath();
        ctx.arc(cx, cy, ringR, 0, Math.PI * 2);
        ctx.stroke();

        ctx.font = 'bold 11px Consolas, monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = accent;
        ctx.fillText('Β', cx, cy + 0.5);

        const gripX = rect.x + rect.w - northSymbolGripSize;
        const gripY = rect.y + rect.h - northSymbolGripSize;
        ctx.fillStyle = 'rgba(120, 120, 120, 0.9)';
        ctx.strokeStyle = 'rgba(20, 20, 20, 0.95)';
        ctx.fillRect(gripX, gripY, northSymbolGripSize, northSymbolGripSize);
        ctx.strokeRect(gripX, gripY, northSymbolGripSize, northSymbolGripSize);
        ctx.restore();
    }

    function drawGrips(ent, isSelected = false) {
        if (ent.type === 'text' && isSelected) {
            const bounds = getEntityBounds(ent);
            if (bounds) {
                const corners = [
                    { x: bounds.minX, y: bounds.minY },
                    { x: bounds.maxX, y: bounds.minY },
                    { x: bounds.maxX, y: bounds.maxY },
                    { x: bounds.minX, y: bounds.maxY }
                ];
                const screenCorners = corners.map(point => worldToScreen(point.x, point.y));
                ctx.save();
                ctx.setLineDash([4, 4]);
                ctx.strokeStyle = 'rgba(170, 170, 170, 0.65)';
                ctx.lineWidth = 1;
                ctx.beginPath();
                screenCorners.forEach((point, index) => {
                    if (index === 0) ctx.moveTo(point.x, point.y);
                    else ctx.lineTo(point.x, point.y);
                });
                ctx.closePath();
                ctx.stroke();
                ctx.restore();
            }
        }
        const grips = getEntityGrips(ent);
        const isAngleDimension = ent.type === 'dimension' && ent.kind === 'angle';
        const showFullGripInfo = !isAngleDimension || isSelected;
        grips.forEach(g => {
            if (isAngleDimension && !isSelected && g.id === 'position') return;
            const sp = worldToScreen(g.x, g.y);
            const isHot = (activeGrip && activeGrip.id === g.id);
            const isHover = (hoveredGrip && hoveredGrip.id === g.id);

            ctx.save();
            ctx.beginPath();
            ctx.fillStyle = isHot ? '#ff2222' : (isHover ? '#33bbff' : (g.color || '#007acc'));
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1.2;
            const size = isHot ? 9 : 7;
            if (ent.type === 'dimension' && (g.id === 'start' || g.id === 'end' || (ent.kind === 'angle' && g.id === 'center'))) {
                ctx.beginPath();
                ctx.arc(sp.x, sp.y, size / 2, 0, Math.PI * 2);
                ctx.fill();
                ctx.stroke();
            } else {
                ctx.fillRect(sp.x - size / 2, sp.y - size / 2, size, size);
                ctx.strokeRect(sp.x - size / 2, sp.y - size / 2, size, size);
            }

            if (showFullGripInfo && g.label) {
                ctx.font = 'bold 11px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                const textWidth = ctx.measureText(g.label).width;
                const badgeX = sp.x + 8;
                const badgeY = sp.y - 18;
                
                ctx.fillStyle = 'rgba(20, 20, 20, 0.85)';
                ctx.strokeStyle = g.color || '#fff';
                ctx.lineWidth = 1;
                ctx.fillRect(badgeX - 3, badgeY - 1, textWidth + 6, 14);
                ctx.strokeRect(badgeX - 3, badgeY - 1, textWidth + 6, 14);

                ctx.fillStyle = g.color || '#fff';
                ctx.fillText(g.label, badgeX, badgeY + 10);
            }
            ctx.restore();
        });
    }

    // Hatch rendering clips each polyline segment independently at its local pattern angle.
    function drawHatch(ent) {
        const hatchBoundary = getHatchBoundary(ent);
        if (!hatchBoundary) return;
        const bounds = getEntityBounds(hatchBoundary.type === 'hatch-band' ? hatchBoundary.outer : hatchBoundary);
        if (!bounds) return;
        const hatch = ent.hatch === true ? {} : ent.hatch;
        const requestedSpacing = Number(hatch.spacing);
        const spacing = Number.isFinite(requestedSpacing) && requestedSpacing > 0 ? requestedSpacing : 10;
        const angle = (Number(hatch.angle) || 45) * Math.PI / 180;
        const centerX = (bounds.minX + bounds.maxX) / 2;
        const centerY = (bounds.minY + bounds.maxY) / 2;
        const extent = Math.hypot(bounds.maxX - bounds.minX, bounds.maxY - bounds.minY) + 4;
        const center = worldToScreen(centerX, centerY);

        ctx.save();
        if (hatchBoundary.type === 'hatch-strips') {
            hatchBoundary.strips.forEach(strip => {
                const screenStrip = strip.map(point => worldToScreen(point.x, point.y));
                const center = screenStrip.reduce((result, point) => ({
                    x: result.x + point.x / screenStrip.length,
                    y: result.y + point.y / screenStrip.length
                }), { x: 0, y: 0 });
                const extent = Math.max(...screenStrip.map(point => Math.hypot(point.x - center.x, point.y - center.y))) + 4;
                const segmentAngle = Math.atan2(
                    screenStrip[1].y - screenStrip[0].y,
                    screenStrip[1].x - screenStrip[0].x
                );

                ctx.save();
                ctx.beginPath();
                ctx.moveTo(screenStrip[0].x, screenStrip[0].y);
                screenStrip.slice(1).forEach(screenPoint => ctx.lineTo(screenPoint.x, screenPoint.y));
                ctx.closePath();
                ctx.clip();
                ctx.translate(center.x, center.y);
                ctx.rotate(segmentAngle + angle);
                ctx.strokeStyle = selectedHatch === ent ? '#ffd166' : (hatch.color || ent.color || '#ffffff');
                ctx.globalAlpha = 0.35;
                ctx.lineWidth = Math.max(0.5, (hatch.width || ent.width || 1) * 0.6);
                for (let y = -extent; y <= extent; y += spacing * camera.zoom) {
                    ctx.beginPath();
                    ctx.moveTo(-extent, y);
                    ctx.lineTo(extent, y);
                    ctx.stroke();
                }
                ctx.restore();
            });
            ctx.restore();
            return;
        }
        const appendPath = boundary => {
            if (boundary.type === 'rect') {
                const first = worldToScreen(boundary.x, boundary.y);
                const second = worldToScreen(boundary.x + boundary.w, boundary.y + boundary.h);
                ctx.rect(Math.min(first.x, second.x), Math.min(first.y, second.y), Math.abs(second.x - first.x), Math.abs(second.y - first.y));
            } else if (boundary.type === 'pline' || boundary.type === 'polygon') {
                const points = boundary.type === 'pline' ? boundary.points : boundary.points;
                const first = worldToScreen(points[0].x, points[0].y);
                ctx.moveTo(first.x, first.y);
                points.slice(1).forEach(point => {
                    const screenPoint = worldToScreen(point.x, point.y);
                    ctx.lineTo(screenPoint.x, screenPoint.y);
                });
                ctx.closePath();
            } else if (boundary.type === 'circle') {
                const boundaryCenter = worldToScreen(boundary.cx, boundary.cy);
                ctx.moveTo(boundaryCenter.x + boundary.r * camera.zoom, boundaryCenter.y);
                ctx.arc(boundaryCenter.x, boundaryCenter.y, boundary.r * camera.zoom, 0, Math.PI * 2);
            } else if (boundary.type === 'ellipse') {
                const boundaryCenter = worldToScreen(boundary.cx, boundary.cy);
                ctx.moveTo(boundaryCenter.x + Math.abs(boundary.rx) * camera.zoom, boundaryCenter.y);
                ctx.ellipse(boundaryCenter.x, boundaryCenter.y, Math.abs(boundary.rx) * camera.zoom, Math.abs(boundary.ry) * camera.zoom, 0, 0, Math.PI * 2);
            } else {
                return false;
            }
            return true;
        };
        ctx.beginPath();
        if (hatchBoundary.type === 'hatch-band') {
            if (!appendPath(hatchBoundary.outer) || !appendPath(hatchBoundary.inner)) {
                ctx.restore();
                return;
            }
        } else if (!appendPath(hatchBoundary)) {
            ctx.restore();
            return;
        }
        ctx.clip('evenodd');
        ctx.translate(center.x, center.y);
        ctx.rotate(angle);
        ctx.strokeStyle = selectedHatch === ent ? '#ffd166' : (hatch.color || ent.color || '#ffffff');
        ctx.globalAlpha = 0.35;
        ctx.lineWidth = Math.max(0.5, (hatch.width || ent.width || 1) * 0.6);
        for (let y = -extent * camera.zoom; y <= extent * camera.zoom; y += spacing * camera.zoom) {
            ctx.beginPath();
            ctx.moveTo(-extent * camera.zoom, y);
            ctx.lineTo(extent * camera.zoom, y);
            ctx.stroke();
        }
        ctx.restore();
    }

    function drawEntity(ent, isTemp = false) {
        const isSelected = !selectedHatch && (selectedEntities.has(ent) || selectedEntity === ent);

        if (ent.type === 'dxf-import') {
            const children = Array.isArray(ent.children) ? ent.children : [];
            children.forEach(child => {
                const childEntity = { ...child, color: child.color || ent.color || '#fff', width: child.width || ent.width || 2 };
                drawEntity(childEntity, isTemp);
            });
            if (Array.isArray(ent.labels)) {
                ctx.save();
                ctx.font = '11px Arial';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = ent.color || '#ffffff';
                ent.labels.forEach(label => {
                    const p = worldToScreen(label.x, label.y);
                    ctx.fillText(label.text, p.x, p.y);
                });
                ctx.restore();
            }
            if (isSelected && !isTemp) {
                const bounds = getEntityBounds(ent);
                if (bounds) {
                    const min = worldToScreen(bounds.minX, bounds.minY);
                    const max = worldToScreen(bounds.maxX, bounds.maxY);
                    ctx.save();
                    ctx.strokeStyle = '#00bfff';
                    ctx.setLineDash([6, 3]);
                    ctx.lineWidth = 1;
                    ctx.strokeRect(min.x, min.y, max.x - min.x, max.y - min.y);
                    ctx.restore();
                }
            }
            return;
        }

        ctx.save();
        ctx.beginPath();
        ctx.strokeStyle = isSelected ? '#00bfff' : (ent.color || '#fff');
        ctx.fillStyle = isSelected ? '#00bfff' : (ent.color || '#fff');
        ctx.lineWidth = (ent.width || 2);
        ctx.setLineDash(isTemp ? [4, 4] : (isSelected ? [6, 3] : []));
        if (!isTemp && ent.hatch) {
            drawHatch(ent);
            ctx.beginPath();
        }

        if (ent.type === 'line') {
            const p1 = worldToScreen(ent.x1, ent.y1);
            const p2 = worldToScreen(ent.x2, ent.y2);
            ctx.moveTo(p1.x, p1.y);
            ctx.lineTo(p2.x, p2.y);
            ctx.stroke();

            if (isSelected && !isTemp) {
                ctx.save();
                ctx.setLineDash([]);
                const midX = (p1.x + p2.x) / 2;
                const midY = (p1.y + p2.y) / 2;
                const ang = Math.atan2(p2.y - p1.y, p2.x - p1.x);
                const arrowLen = 12;

                ctx.fillStyle = '#00bfff';
                ctx.beginPath();
                ctx.moveTo(midX + Math.cos(ang) * 6, midY + Math.sin(ang) * 6);
                ctx.lineTo(midX - Math.cos(ang - Math.PI / 6) * arrowLen, midY - Math.sin(ang - Math.PI / 6) * arrowLen);
                ctx.lineTo(midX - Math.cos(ang + Math.PI / 6) * arrowLen, midY - Math.sin(ang + Math.PI / 6) * arrowLen);
                ctx.closePath();
                ctx.fill();
                ctx.restore();
            }
        } 
        else if (ent.type === 'rect') {
            const p1 = worldToScreen(ent.x, ent.y);
            const p2 = worldToScreen(ent.x + ent.w, ent.y + ent.h);
            ctx.strokeRect(Math.min(p1.x, p2.x), Math.min(p1.y, p2.y), Math.abs(p2.x - p1.x), Math.abs(p2.y - p1.y));
        } 
        else if (ent.type === 'pline' && ent.points && ent.points.length > 0) {
            const first = worldToScreen(ent.points[0].x, ent.points[0].y);
            ctx.moveTo(first.x, first.y);
            for (let i = 1; i < ent.points.length; i++) {
                const pt = worldToScreen(ent.points[i].x, ent.points[i].y);
                ctx.lineTo(pt.x, pt.y);
            }
            if (ent.closed) ctx.closePath();
            ctx.stroke();

            if (isSelected && !isTemp && selectedSegmentIndex !== null && ent.points.length > 1) {
                const i1 = selectedSegmentIndex;
                const i2 = (selectedSegmentIndex + 1) % ent.points.length;
                if (ent.points[i1] && ent.points[i2]) {
                    const sp1 = worldToScreen(ent.points[i1].x, ent.points[i1].y);
                    const sp2 = worldToScreen(ent.points[i2].x, ent.points[i2].y);

                    ctx.save();
                    ctx.strokeStyle = '#00e5ff';
                    ctx.lineWidth = (ent.width || 2) + 2;
                    ctx.setLineDash([]);
                    ctx.beginPath();
                    ctx.moveTo(sp1.x, sp1.y);
                    ctx.lineTo(sp2.x, sp2.y);
                    ctx.stroke();

                    const midX = (sp1.x + sp2.x) / 2;
                    const midY = (sp1.y + sp2.y) / 2;
                    const ang = Math.atan2(sp2.y - sp1.y, sp2.x - sp1.x);
                    const arrowLen = 12;

                    ctx.fillStyle = '#00e5ff';
                    ctx.beginPath();
                    ctx.moveTo(midX + Math.cos(ang) * 6, midY + Math.sin(ang) * 6);
                    ctx.lineTo(midX - Math.cos(ang - Math.PI / 6) * arrowLen, midY - Math.sin(ang - Math.PI / 6) * arrowLen);
                    ctx.lineTo(midX - Math.cos(ang + Math.PI / 6) * arrowLen, midY - Math.sin(ang + Math.PI / 6) * arrowLen);
                    ctx.closePath();
                    ctx.fill();
                    ctx.restore();
                }
            }
        } 
        else if (ent.type === 'circle') {
            const c = worldToScreen(ent.cx, ent.cy);
            const r = ent.r * camera.zoom;
            ctx.arc(c.x, c.y, r, 0, Math.PI * 2);
            ctx.stroke();
        } 
        else if (ent.type === 'ellipse') {
            const c = worldToScreen(ent.cx, ent.cy);
            const rx = ent.rx * camera.zoom;
            const ry = ent.ry * camera.zoom;
            ctx.ellipse(c.x, c.y, rx, ry, 0, 0, Math.PI * 2);
            ctx.stroke();
        } 
        else if (ent.type === 'arc') {
            const c = worldToScreen(ent.cx, ent.cy);
            const r = ent.r * camera.zoom;
            const p1 = worldToScreen(ent.cx + ent.r * Math.sin(ent.startAzi), ent.cy + ent.r * Math.cos(ent.startAzi));
            const p2 = worldToScreen(ent.cx + ent.r * Math.sin(ent.endAzi), ent.cy + ent.r * Math.cos(ent.endAzi));
            const startAngleScreen = Math.atan2(p1.y - c.y, p1.x - c.x);
            const endAngleScreen = Math.atan2(p2.y - c.y, p2.x - c.x);

            ctx.arc(c.x, c.y, r, startAngleScreen, endAngleScreen, false);
            ctx.stroke();
        } 
        else if (ent.type === 'text') {
            const p = worldToScreen(ent.x, ent.y);
            const height = Math.max(0.001, Number(ent.height ?? 0.1));
            const fontSize = Math.max(8, height * Math.max(0.5, camera.zoom || 1));
            const { horizontal, vertical } = getTextJustification(ent);
            const lines = ent.textMode === 'multiline' ? String(ent.text || '').split(/\r?\n/) : [String(ent.text || '').replace(/[\r\n]+/g, ' ')];
            ctx.save();
            ctx.translate(p.x, p.y);
            ctx.rotate(((Number(ent.rotation) || 0) * Math.PI) / 180);
            const fontFamily = ['Arial', 'Arial Narrow', 'Tahoma', 'Verdana', 'Consolas', 'Courier New'].includes(ent.fontFamily)
                ? ent.fontFamily
                : 'Arial';
            ctx.font = `${fontSize}px "${fontFamily}"`;
            ctx.textAlign = horizontal;
            ctx.textBaseline = 'middle';
            ctx.fillStyle = isSelected ? '#00bfff' : (ent.color || '#fff');
            const lineHeight = height * 1.2 * Math.max(0.5, camera.zoom || 1);
            const blockHeight = lineHeight * lines.length;
            const firstLineY = vertical === 'top'
                ? lineHeight / 2
                : vertical === 'bottom'
                ? -blockHeight + lineHeight / 2
                : -(blockHeight - lineHeight) / 2;
            lines.forEach((line, index) => ctx.fillText(line, 0, firstLineY + index * lineHeight));
            ctx.restore();
        }
        else if (ent.type === 'point') {
            const p = worldToScreen(ent.x, ent.y);
            ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
            ctx.fill();

            if (ent.showText !== false) {
                ctx.save();
                ctx.font = '11px Consolas, monospace';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = isSelected ? '#00bfff' : (ent.color || '#fff');
                ctx.fillText(`${ent.name || ''}:${formatCoord(ent.z || 0)}`, p.x + 8, p.y - 8);
                ctx.restore();
            }
        }
        else if (ent.type === 'dimension' && ent.kind === 'angle') {
            const center = worldToScreen(ent.cx, ent.cy);
            const ray1End = getAngleDimensionRayEnd(ent, 'start');
            const ray2End = getAngleDimensionRayEnd(ent, 'end');
            const ray1 = worldToScreen(ray1End.x, ray1End.y);
            const ray2 = worldToScreen(ray2End.x, ray2End.y);
            const start = worldToScreen(ent.cx + ent.r * Math.sin(ent.startAzi), ent.cy + ent.r * Math.cos(ent.startAzi));
            const arc = getAngleDimensionArc(ent);
            const endAzi = ent.startAzi + arc.direction * arc.sweep;
            const end = worldToScreen(ent.cx + ent.r * Math.sin(endAzi), ent.cy + ent.r * Math.cos(endAzi));
            const startAngle = Math.atan2(start.y - center.y, start.x - center.x);
            const endAngle = Math.atan2(end.y - center.y, end.x - center.x);
            const angleValue = getAngleDimensionSweep(ent);
            const label = `${azimuthRadToValue(angleValue).toFixed(getDimensionDecimals(ent))}${getAngleUnitLabel()}`;
            const textPosition = worldToScreen(getDimensionTextPosition(ent).x, getDimensionTextPosition(ent).y);
            const labelPosition = { x: textPosition.x, y: textPosition.y + 12 };
            if (isSelected) {
                [
                    { point: ray1, end: ray1End },
                    { point: ray2, end: ray2End }
                ].forEach(({ point, end: rayEnd }) => {
                    const rayLength = Math.hypot(rayEnd.x - ent.cx, rayEnd.y - ent.cy);
                    const rayLabel = formatDimensionValue(ent, rayLength);
                    const rayMidpoint = {
                        x: (center.x + point.x) / 2,
                        y: (center.y + point.y) / 2
                    };
                    const rayAngle = Math.atan2(point.y - center.y, point.x - center.x);

                    ctx.save();
                    ctx.strokeStyle = ent.color || '#ffd166';
                    ctx.lineWidth = 1;
                    ctx.setLineDash([4, 4]);
                    ctx.beginPath();
                    ctx.moveTo(center.x, center.y);
                    ctx.lineTo(point.x, point.y);
                    ctx.stroke();
                    ctx.restore();

                    ctx.save();
                    ctx.font = '11px Consolas, monospace';
                    ctx.translate(rayMidpoint.x, rayMidpoint.y);
                    let rayLabelAngle = rayAngle;
                    if (rayLabelAngle > Math.PI / 2 || rayLabelAngle < -Math.PI / 2) rayLabelAngle += Math.PI;
                    ctx.rotate(rayLabelAngle);
                    ctx.fillStyle = ent.color || '#ffd166';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    ctx.fillText(rayLabel, 0, -3);
                    ctx.restore();
                });
            }

            ctx.save();
            ctx.font = '11px Consolas, monospace';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = ent.color || '#ffd166';
            drawCurvedAngleDimensionText(center, labelPosition, label, ent.color || '#ffd166');
            ctx.restore();
        }
        else if (ent.type === 'dimension') {
            const dx = ent.x2 - ent.x1;
            const dy = ent.y2 - ent.y1;
            const length = Math.hypot(dx, dy);
            if (length > 1e-9) {
                const startPoint = worldToScreen(ent.x1, ent.y1);
                const endPoint = worldToScreen(ent.x2, ent.y2);
                const textWorldPosition = getDimensionTextPosition(ent);
                const textPosition = worldToScreen(textWorldPosition.x, textWorldPosition.y);
                const label = formatDimensionValue(ent, length);
                ctx.font = '11px Consolas, monospace';
                let labelAngle = Math.atan2(-dy, dx);
                if (labelAngle > Math.PI / 2 || labelAngle < -Math.PI / 2) labelAngle += Math.PI;
                ctx.save();
                ctx.translate(textPosition.x, textPosition.y);
                ctx.rotate(labelAngle);
                ctx.fillStyle = ent.color || '#ffd166';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText(label, 0, -3);
                ctx.restore();

                ctx.save();
                ctx.strokeStyle = ent.color || '#ffd166';
                ctx.lineWidth = 1.2;
                ctx.beginPath();
                ctx.arc(startPoint.x, startPoint.y, 3.5, 0, Math.PI * 2);
                ctx.moveTo(endPoint.x + 3.5, endPoint.y);
                ctx.arc(endPoint.x, endPoint.y, 3.5, 0, Math.PI * 2);
                ctx.stroke();
                ctx.restore();
            }
        }

        ctx.restore();

        if (!isTemp && (isSelected || (ent.type === 'dimension' && ent.kind === 'angle'))) {
            try {
                drawGrips(ent, isSelected);
            } catch (err) {
                console.error("Grip render error:", err);
            }
        }
    }

    function drawSnapMarker(snap) {
        if (!snap) return;
        ctx.save();
        ctx.strokeStyle = '#00ff66';
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        const { screenX: x, screenY: y, type } = snap;
        const s = 6;

        switch (type) {
            case 'endpoint': ctx.strokeRect(x - s, y - s, s * 2, s * 2); break;
            case 'midpoint':
                ctx.moveTo(x, y - s); ctx.lineTo(x + s, y + s); ctx.lineTo(x - s, y + s); ctx.closePath();
                ctx.stroke();
                break;
            case 'center':
                ctx.arc(x, y, s, 0, Math.PI * 2);
                ctx.moveTo(x - s - 2, y); ctx.lineTo(x + s + 2, y);
                ctx.moveTo(x, y - s - 2); ctx.lineTo(x + s + 2);
                ctx.stroke();
                break;
            case 'quadrant':
                ctx.moveTo(x, y - s); ctx.lineTo(x + s, y); ctx.lineTo(x, y + s); ctx.lineTo(x - s, y); ctx.closePath();
                ctx.stroke();
                break;
            case 'intersection':
                ctx.moveTo(x - s, y - s); ctx.lineTo(x + s, y + s);
                ctx.moveTo(x + s, y - s); ctx.lineTo(x - s, y + s);
                ctx.stroke();
                break;
            case 'perpendicular':
                ctx.strokeRect(x - s, y - s, s, s);
                ctx.moveTo(x - s, y); ctx.lineTo(x, y); ctx.lineTo(x, y - s);
                ctx.stroke();
                break;
            case 'tangent':
                ctx.arc(x, y, s, 0, Math.PI * 2);
                ctx.moveTo(x - s, y + s); ctx.lineTo(x + s, y - s);
                ctx.stroke();
                break;
            case 'nearest':
                ctx.moveTo(x - s, y - s); ctx.lineTo(x + s, y + s);
                ctx.lineTo(x - s, y + s); ctx.lineTo(x + s, y - s); ctx.closePath();
                ctx.stroke();
                break;
        }

        const snapLabels = {
            endpoint: 'Endpoint',
            midpoint: 'Midpoint',
            center: 'Center',
            quadrant: 'Quadrant',
            intersection: 'Intersection',
            perpendicular: 'Perpendicular',
            tangent: 'Tangent',
            nearest: 'Nearest'
        };
        const label = snapLabels[type];
        if (label) {
            ctx.font = '10px Segoe UI, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'alphabetic';
            const baselineY = y - s - 8;
            const textWidth = ctx.measureText(label).width;
            ctx.fillStyle = 'rgba(18, 18, 18, 0.85)';
            ctx.fillRect(x - textWidth / 2 - 3, baselineY - 11, textWidth + 6, 14);
            ctx.fillStyle = '#b7ffcf';
            ctx.fillText(label, x, baselineY);
        }
        ctx.restore();
    }

    function drawIntersectionMarkers() {
        ctx.save();
        ctx.strokeStyle = '#ffd166';
        ctx.lineWidth = 1.5;
        getIntersectionPoints().forEach(point => {
            const screenPoint = worldToScreen(point.x, point.y);
            const size = 5;
            ctx.beginPath();
            ctx.moveTo(screenPoint.x - size, screenPoint.y - size);
            ctx.lineTo(screenPoint.x + size, screenPoint.y + size);
            ctx.moveTo(screenPoint.x + size, screenPoint.y - size);
            ctx.lineTo(screenPoint.x - size, screenPoint.y + size);
            ctx.stroke();
        });
        ctx.restore();
    }

    function finishPline(close = false) {
        if (plineVertices.length > 1) {
            saveState();
            const newEntity = {
                type: 'pline',
                points: [...plineVertices],
                closed: close,
                elevation: 0.000,
                color: document.getElementById('strokeColor').value,
                width: parseInt(document.getElementById('lineWidth').value)
            };
            entities.push(newEntity);
            switchToSelectMode(newEntity);
        } else {
            switchToSelectMode(null);
        }
    }

    // Contours are generated from point elevations using Delaunay triangles and level intersections.
    function getDelaunayTriangles(pointList) {
        const bounds = pointList.reduce((result, point) => ({
            minX: Math.min(result.minX, point.x),
            minY: Math.min(result.minY, point.y),
            maxX: Math.max(result.maxX, point.x),
            maxY: Math.max(result.maxY, point.y)
        }), { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity });
        const span = Math.max(bounds.maxX - bounds.minX, bounds.maxY - bounds.minY, 1);
        const centerX = (bounds.minX + bounds.maxX) / 2;
        const centerY = (bounds.minY + bounds.maxY) / 2;
        const points = [...pointList,
            { x: centerX - 20 * span, y: centerY - span },
            { x: centerX, y: centerY + 20 * span },
            { x: centerX + 20 * span, y: centerY - span }
        ];
        const superStart = pointList.length;
        let triangles = [{ a: superStart, b: superStart + 1, c: superStart + 2 }];

        const containsPoint = (triangle, point) => {
            const a = points[triangle.a];
            const b = points[triangle.b];
            const c = points[triangle.c];
            const denominator = 2 * (a.x * (b.y - c.y) + b.x * (c.y - a.y) + c.x * (a.y - b.y));
            if (Math.abs(denominator) < 1e-12) return false;
            const a2 = a.x * a.x + a.y * a.y;
            const b2 = b.x * b.x + b.y * b.y;
            const c2 = c.x * c.x + c.y * c.y;
            const center = {
                x: (a2 * (b.y - c.y) + b2 * (c.y - a.y) + c2 * (a.y - b.y)) / denominator,
                y: (a2 * (c.x - b.x) + b2 * (a.x - c.x) + c2 * (b.x - a.x)) / denominator
            };
            const radiusSquared = (center.x - a.x) ** 2 + (center.y - a.y) ** 2;
            return (point.x - center.x) ** 2 + (point.y - center.y) ** 2 <= radiusSquared + 1e-9;
        };

        for (let pointIndex = 0; pointIndex < pointList.length; pointIndex++) {
            const point = points[pointIndex];
            const badTriangles = triangles.filter(triangle => containsPoint(triangle, point));
            const edgeCounts = new Map();
            badTriangles.forEach(triangle => {
                [[triangle.a, triangle.b], [triangle.b, triangle.c], [triangle.c, triangle.a]].forEach(([start, end]) => {
                    const key = start < end ? `${start}:${end}` : `${end}:${start}`;
                    const edge = edgeCounts.get(key) || { start, end, count: 0 };
                    edge.count++;
                    edgeCounts.set(key, edge);
                });
            });
            triangles = triangles.filter(triangle => !badTriangles.includes(triangle));
            edgeCounts.forEach(edge => {
                if (edge.count === 1) triangles.push({ a: edge.start, b: edge.end, c: pointIndex });
            });
        }

        return triangles.filter(triangle => triangle.a < superStart && triangle.b < superStart && triangle.c < superStart);
    }

    function generateContours(interval = 1) {
        if (!Number.isFinite(interval) || interval <= 0) {
            showToast('Enter a contour interval greater than zero.', 'warning', 2200);
            return;
        }
        const pointEntities = entities.filter(entity => entity.type === 'point')
            .filter(point => Number.isFinite(Number(point.x)) && Number.isFinite(Number(point.y)) && Number.isFinite(Number(point.z)));
        if (pointEntities.length < 3) {
            showToast('At least 3 points with X, Y and Z are required.', 'warning', 2200);
            return;
        }

        const pointList = pointEntities.map(point => ({ x: Number(point.x), y: Number(point.y), z: Number(point.z) }));
        const minZ = Math.min(...pointList.map(point => point.z));
        const maxZ = Math.max(...pointList.map(point => point.z));
        const firstLevel = Math.ceil(minZ / interval - 1e-9) * interval;
        const lastLevel = Math.floor(maxZ / interval + 1e-9) * interval;
        if (firstLevel > lastLevel) {
            showToast(`The point elevations do not span a full ${interval} m contour interval.`, 'warning', 2200);
            return;
        }

        const triangles = getDelaunayTriangles(pointList);
        const segmentsByLevel = new Map();
        const pointKey = point => `${Math.round(point.x * 1e6)}:${Math.round(point.y * 1e6)}`;
        for (let level = firstLevel; level <= lastLevel + interval * 1e-9; level += interval) {
            level = Number(level.toFixed(9));
            const segments = [];
            triangles.forEach(triangle => {
                const trianglePoints = [pointList[triangle.a], pointList[triangle.b], pointList[triangle.c]];
                const intersections = [];
                [[0, 1], [1, 2], [2, 0]].forEach(([startIndex, endIndex]) => {
                    const start = trianglePoints[startIndex];
                    const end = trianglePoints[endIndex];
                    const startDelta = start.z - level;
                    const endDelta = end.z - level;
                    if (Math.abs(startDelta) < 1e-9 && Math.abs(endDelta) < 1e-9) return;
                    if (startDelta * endDelta < -1e-12) {
                        const ratio = startDelta / (startDelta - endDelta);
                        intersections.push({ x: start.x + ratio * (end.x - start.x), y: start.y + ratio * (end.y - start.y) });
                    } else if (Math.abs(startDelta) < 1e-9) {
                        intersections.push({ x: start.x, y: start.y });
                    } else if (Math.abs(endDelta) < 1e-9) {
                        intersections.push({ x: end.x, y: end.y });
                    }
                });
                const uniqueIntersections = [...new Map(intersections.map(point => [pointKey(point), point])).values()];
                if (uniqueIntersections.length === 2) segments.push(uniqueIntersections);
            });
            segmentsByLevel.set(level, segments);
        }

        saveState();
        entities = entities.filter(entity => entity.generatedBy !== 'contours');
        let contourCount = 0;
        segmentsByLevel.forEach((segments, level) => {
            const adjacency = new Map();
            const addConnection = (key, segmentIndex) => {
                const connections = adjacency.get(key) || [];
                connections.push(segmentIndex);
                adjacency.set(key, connections);
            };
            segments.forEach((segment, index) => {
                addConnection(pointKey(segment[0]), index);
                addConnection(pointKey(segment[1]), index);
            });

            const used = new Set();
            segments.forEach((segment, segmentIndex) => {
                if (used.has(segmentIndex)) return;
                const firstKey = pointKey(segment[0]);
                const secondKey = pointKey(segment[1]);
                const startAt = (adjacency.get(firstKey).length !== 2 || adjacency.get(secondKey).length === 2) ? 0 : 1;
                const startKey = pointKey(segment[startAt]);
                const contourPoints = [segment[startAt]];
                let currentKey = startKey;
                let currentSegmentIndex = segmentIndex;
                while (currentSegmentIndex !== null && !used.has(currentSegmentIndex)) {
                    used.add(currentSegmentIndex);
                    const currentSegment = segments[currentSegmentIndex];
                    const nextPoint = pointKey(currentSegment[0]) === currentKey ? currentSegment[1] : currentSegment[0];
                    contourPoints.push(nextPoint);
                    currentKey = pointKey(nextPoint);
                    if (currentKey === startKey) break;
                    const nextSegment = (adjacency.get(currentKey) || []).find(index => !used.has(index));
                    currentSegmentIndex = nextSegment === undefined ? null : nextSegment;
                }
                if (contourPoints.length > 1) {
                    const isClosed = pointKey(contourPoints[0]) === pointKey(contourPoints[contourPoints.length - 1]);
                    if (isClosed) contourPoints.pop();
                    entities.push({
                        type: 'pline',
                        points: contourPoints,
                        closed: isClosed,
                        elevation: level,
                        color: '#00e5ff',
                        width: 1,
                        generatedBy: 'contours'
                    });
                    contourCount++;
                }
            });
        });

        selectedEntity = null;
        selectedEntities.clear();
        selectedSegmentIndex = null;
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        showToast(`${contourCount} contour polylines generated at ${interval} m intervals.`, 'success', 2500);
    }

    function render() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawGrid();
        drawPrintFrame();

        entities.forEach(ent => {
            try {
                drawEntity(ent);
            } catch (err) {
                console.error("Entity render error:", err, ent);
            }
        });

        if (pastePreview) {
            ctx.save();
            ctx.globalAlpha = 0.35;
            getPastePreviewEntities().forEach(entity => drawEntity(entity, true));
            ctx.restore();
        }

        if (moveCommand && moveCommand.basePoint && moveCommand.targetPoint) {
            ctx.save();
            ctx.globalAlpha = 0.35;
            getMovePreviewEntities().forEach(entity => drawEntity(entity, true));
            ctx.restore();
        }
        if (scaleCommand && scaleCommand.basePoint && Number.isFinite(scaleCommand.factor)) {
            ctx.save();
            ctx.globalAlpha = 0.35;
            getScalePreviewEntities().forEach(entity => drawEntity(entity, true));
            ctx.restore();
        }

        const dimensionPreview = getDimensionPreview();
        if (dimensionPreview) drawEntity(dimensionPreview, true);

        drawIntersectionMarkers();
        drawNorthSymbol();

        if (isDrawing) {
            const color = document.getElementById('strokeColor').value;
            const width = parseInt(document.getElementById('lineWidth').value);

            if (currentTool === 'pline' && plineVertices.length > 0) {
                const lastPt = plineVertices[plineVertices.length - 1];
                const p2 = applyOrtho(lastPt, currentMouse);
                drawEntity({ type: 'pline', points: [...plineVertices, p2], closed: false, elevation: 0.000, color, width }, true);
            } else if (currentTool === 'arc') {
                if (arcDrawingStep === 1 && arcCenter) {
                    const r = Math.hypot(currentMouse.x - arcCenter.x, currentMouse.y - arcCenter.y);
                    drawEntity({ type: 'circle', cx: arcCenter.x, cy: arcCenter.y, r, color: '#555', width: 1 }, true);
                    drawEntity({ type: 'line', x1: arcCenter.x, y1: arcCenter.y, x2: currentMouse.x, y2: currentMouse.y, color, width: 1 }, true);
                } else if (arcDrawingStep === 2 && arcCenter && arcStartPoint) {
                    const r = Math.hypot(arcStartPoint.x - arcCenter.x, arcStartPoint.y - arcCenter.y);
                    const startAzi = calculateAzimuthRad(arcStartPoint.x - arcCenter.x, arcStartPoint.y - arcCenter.y);
                    const endAzi = calculateAzimuthRad(currentMouse.x - arcCenter.x, currentMouse.y - arcCenter.y);
                    drawEntity({ type: 'arc', cx: arcCenter.x, cy: arcCenter.y, r, startAzi, endAzi, color, width }, true);
                }
            } else if (startPoint) {
                const p2 = applyOrtho(startPoint, currentMouse);
                if (currentTool === 'line') {
                    drawEntity({ type: 'line', x1: startPoint.x, y1: startPoint.y, x2: p2.x, y2: p2.y, color, width }, true);
                } else if (currentTool === 'rect') {
                    drawEntity({ type: 'rect', x: startPoint.x, y: startPoint.y, w: p2.x - startPoint.x, h: p2.y - startPoint.y, color, width }, true);
                } else if (currentTool === 'circle') {
                    const r = Math.hypot(p2.x - startPoint.x, p2.y - startPoint.y);
                    drawEntity({ type: 'circle', cx: startPoint.x, cy: startPoint.y, r, color, width }, true);
                } else if (currentTool === 'ellipse') {
                    const rx = Math.max(0.001, Math.abs(p2.x - startPoint.x));
                    const ry = Math.max(0.001, Math.abs(p2.y - startPoint.y));
                    drawEntity({ type: 'ellipse', cx: startPoint.x, cy: startPoint.y, rx, ry, color, width }, true);
                }
            }
        }

        // Draw snap marker while constructing or manipulating geometry.
        if (activeSnap) {
            // Only exclude an entity if we have an active grip
            const snapToShow = activeSnap;
            if (currentTool !== 'select' || isDrawing || activeGrip || moveCommand || dimensionCommand || angleDimensionCommand) {
                drawSnapMarker(snapToShow);
            }
        }

        if (isSelectingBox && selectionBoxStart && selectionBoxCurrent) {
            const start = worldToScreen(selectionBoxStart.x, selectionBoxStart.y);
            const current = worldToScreen(selectionBoxCurrent.x, selectionBoxCurrent.y);
            ctx.save();
            ctx.fillStyle = 'rgba(0, 122, 204, 0.15)';
            ctx.strokeStyle = '#0098ff';
            ctx.setLineDash([5, 4]);
            ctx.fillRect(start.x, start.y, current.x - start.x, current.y - start.y);
            ctx.strokeRect(start.x, start.y, current.x - start.x, current.y - start.y);
            ctx.restore();
        }

        if (imageCaptureSelection) {
            const { start, current } = imageCaptureSelection;
            ctx.save();
            ctx.strokeStyle = '#00e5ff';
            ctx.lineWidth = 1;
            ctx.setLineDash([6, 4]);
            ctx.strokeRect(start.x, start.y, current.x - start.x, current.y - start.y);
            ctx.restore();
        }
    }

    function bindUsernameInput() {
        const usernameInput = document.getElementById('prop-username');
        if (!usernameInput) return;
        usernameInput.value = username;
        usernameInput.addEventListener('change', (event) => {
            const value = event.target.value.trim();
            if (!isValidUsername(value)) {
                usernameInput.value = username;
                showToast('Username must be 1-24 letters, numbers, spaces, underscores or hyphens.', 'error');
                return;
            }
            const previousUsername = username;
            username = value;
            localStorage.setItem('cad_username', username);
            updatePresence(true).then(updated => {
                if (!updated) {
                    username = previousUsername;
                    localStorage.setItem('cad_username', username);
                    updatePropertiesPalette();
                    return;
                }
                showToast('Username updated.', 'success', 1500);
            });
        });
    }

    function renderHatchProperties(entity) {
        const hatch = entity.hatch === true ? { pattern: 'diagonal', spacing: 10, angle: 45, distance: 10, sideSign: 1 } : entity.hatch;
        const sideOptions = entity.type === 'line' || (entity.type === 'pline' && !entity.closed)
            ? `<option value="-1" ${Number(hatch.sideSign) < 0 ? 'selected' : ''}>Left</option><option value="1" ${Number(hatch.sideSign) >= 0 ? 'selected' : ''}>Right</option>`
            : `<option value="-1" ${Number(hatch.sideSign) < 0 ? 'selected' : ''}>Inside</option><option value="1" ${Number(hatch.sideSign) >= 0 ? 'selected' : ''}>Outside</option>`;
        propCount.innerText = 'HATCH';
        propContainer.innerHTML = `
            <div class="prop-group">
                <div class="prop-group-title">Hatch</div>
                <div class="prop-row"><label>Pattern</label><input type="text" readonly value="Diagonal"></div>
                <div class="prop-row"><label>Enabled</label><select id="prop-hatch-enabled"><option value="true" selected>Yes</option><option value="false">No</option></select></div>
                <div class="prop-row"><label>Color</label><input type="color" id="prop-hatch-color" value="${hatch.color || entity.color || '#ffffff'}"></div>
                <div class="prop-row"><label>Line Weight</label><select id="prop-hatch-width">${[1, 2, 3, 4, 5].map(value => `<option value="${value}" ${Number(hatch.width || entity.width || 1) === value ? 'selected' : ''}>${value} px</option>`).join('')}</select></div>
                <div class="prop-row"><label>Offset Distance</label><input type="text" id="prop-hatch-distance" value="${formatCoord(hatch.distance)}"></div>
                <div class="prop-row"><label>Line Spacing</label><input type="text" id="prop-hatch-spacing" value="${formatCoord(hatch.spacing)}"></div>
                <div class="prop-row"><label>Pattern Angle</label><input type="text" id="prop-hatch-angle" value="${formatCoord(hatch.angle)}"></div>
                <div class="prop-row"><label>Side</label><select id="prop-hatch-side">${sideOptions}</select></div>
            </div>
        `;
        const updateHatch = (property, value) => {
            saveState();
            entity.hatch = hatch;
            hatch[property] = value;
            updatePropertiesPalette();
            render();
        };
        document.getElementById('prop-hatch-enabled').addEventListener('change', event => {
            if (event.target.value === 'false') {
                saveState();
                entity.hatch = null;
                selectedHatch = null;
                updatePropertiesPalette();
                render();
            }
        });
        document.getElementById('prop-hatch-color').addEventListener('change', event => updateHatch('color', event.target.value));
        document.getElementById('prop-hatch-width').addEventListener('change', event => updateHatch('width', Number(event.target.value)));
        document.getElementById('prop-hatch-distance').addEventListener('change', event => {
            const value = parseStrictFloat(event.target.value, NaN);
            if (Number.isFinite(value) && value > 0) updateHatch('distance', value);
            else updatePropertiesPalette();
        });
        document.getElementById('prop-hatch-spacing').addEventListener('change', event => {
            const value = parseStrictFloat(event.target.value, NaN);
            if (Number.isFinite(value) && value > 0) updateHatch('spacing', value);
            else updatePropertiesPalette();
        });
        document.getElementById('prop-hatch-angle').addEventListener('change', event => {
            const value = parseStrictFloat(event.target.value, NaN);
            if (Number.isFinite(value)) updateHatch('angle', value);
            else updatePropertiesPalette();
        });
        document.getElementById('prop-hatch-side').addEventListener('change', event => updateHatch('sideSign', Number(event.target.value) < 0 ? -1 : 1));
    }

    // The properties panel is rebuilt from the current entity selection and its editable fields.
    function updatePropertiesPalette() {
        let html = `
            <div class="prop-group">
                <div class="prop-group-title">User</div>
                <div class="prop-row">
                    <label for="prop-username">Username</label>
                    <input type="text" id="prop-username" maxlength="24" value="">
                </div>
            </div>
            ${renderOsnapProperties()}
        `;

        if (!selectedEntity) {
            propCount.innerText = 'No selection';
            propContainer.innerHTML = html + `<div style="color: var(--text-muted); text-align: center; margin-top: 40px;">Select an entity to view and edit its properties.</div>`;
            bindUsernameInput();
            bindOsnapProperties();
            return;
        }

        if (selectedHatch) {
            renderHatchProperties(selectedHatch);
            return;
        }

        propCount.innerText = selectedHatch
            ? 'HATCH'
            : selectedEntities.size > 1
            ? `${selectedEntities.size} SELECTED`
            : selectedEntity.type.toUpperCase();
        html += `
            <div class="prop-group">
                <div class="prop-group-title">General</div>
                <div class="prop-row">
                    <label>Color</label>
                    <input type="color" id="prop-color" value="${selectedEntity.color || '#ffffff'}">
                </div>
                <div class="prop-row">
                    <label>Line Weight</label>
                    <select id="prop-width">
                        ${[1, 2, 3, 4, 5].map(w => `<option value="${w}" ${selectedEntity.width == w ? 'selected' : ''}>${w} px</option>`).join('')}
                    </select>
                </div>
            </div>
        `;
        if (selectedEntity.hatch) {
            const hatch = selectedEntity.hatch === true ? { distance: 10, spacing: 10, angle: 45, sideSign: 1 } : selectedEntity.hatch;
            const sideOptions = selectedEntity.type === 'line' || (selectedEntity.type === 'pline' && !selectedEntity.closed)
                ? `<option value="-1" ${Number(hatch.sideSign) < 0 ? 'selected' : ''}>Left</option><option value="1" ${Number(hatch.sideSign) >= 0 ? 'selected' : ''}>Right</option>`
                : `<option value="-1" ${Number(hatch.sideSign) < 0 ? 'selected' : ''}>Inside</option><option value="1" ${Number(hatch.sideSign) >= 0 ? 'selected' : ''}>Outside</option>`;
            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Hatch</div>
                    <div class="prop-row"><label>Pattern</label><input type="text" readonly value="Diagonal"></div>
                    <div class="prop-row"><label>Enabled</label><select id="prop-hatch-enabled"><option value="true" selected>Yes</option><option value="false">No</option></select></div>
                    <div class="prop-row"><label>Offset Distance</label><input type="text" id="prop-hatch-distance" value="${formatCoord(hatch.distance)}"></div>
                    <div class="prop-row"><label>Line Spacing</label><input type="text" id="prop-hatch-spacing" value="${formatCoord(hatch.spacing)}"></div>
                    <div class="prop-row"><label>Pattern Angle</label><input type="text" id="prop-hatch-angle" value="${formatCoord(hatch.angle)}"></div>
                    <div class="prop-row"><label>Side</label><select id="prop-hatch-side">${sideOptions}</select></div>
                </div>
            `;
        }

        if (selectedEntity.type === 'line') {
            const dx = selectedEntity.x2 - selectedEntity.x1;
            const dy = selectedEntity.y2 - selectedEntity.y1;
            const len = Math.hypot(dx, dy);
            const azi = formatAzimuth(dx, dy);

            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Line Geometry</div>
                    <div style="margin-bottom: 8px;">
                        <button id="btn-reverse-line" style="width: 100%; background: #333; padding: 4px; font-size: 11px;">⇄ Reverse Direction (P1 ↔ P2)</button>
                    </div>
                    <div class="prop-row"><label style="color:#4caf50; font-weight:bold;">Start X (P1)</label><input type="text" id="prop-x1" value="${formatCoord(selectedEntity.x1)}"></div>
                    <div class="prop-row"><label style="color:#4caf50; font-weight:bold;">Start Y (P1)</label><input type="text" id="prop-y1" value="${formatCoord(selectedEntity.y1)}"></div>
                    <div class="prop-row"><label style="color:#ff9800; font-weight:bold;">End X (P2)</label><input type="text" id="prop-x2" value="${formatCoord(selectedEntity.x2)}"></div>
                    <div class="prop-row"><label style="color:#ff9800; font-weight:bold;">End Y (P2)</label><input type="text" id="prop-y2" value="${formatCoord(selectedEntity.y2)}"></div>
                    <div class="prop-row"><label>Delta X (dx)</label><input type="text" readonly value="${formatCoord(dx)}"></div>
                    <div class="prop-row"><label>Delta Y (dy)</label><input type="text" readonly value="${formatCoord(dy)}"></div>
                    <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Length (S)</label><input type="text" id="prop-len" value="${formatCoord(len)}"></div>
                    <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Azimuth (${azi.unit})</label><input type="text" id="prop-azi" value="${azi.val}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'pline') {
            const pts = selectedEntity.points || [];
            const numSegs = selectedEntity.closed ? pts.length : Math.max(0, pts.length - 1);
            if (selectedSegmentIndex === null && numSegs > 0) selectedSegmentIndex = 0;
            if (selectedVertexIndex >= pts.length) selectedVertexIndex = 0;

            const centroid = getPolylineCentroid(pts, selectedEntity.closed);
            const unit = getAngleUnitLabel();

            const vDetails = getPolylineVertexDetails(selectedEntity);
            const activeV = vDetails[selectedVertexIndex] || vDetails[0];

            let totalLength = 0;
            for (let i = 0; i < pts.length - 1; i++) {
                totalLength += Math.hypot(pts[i + 1].x - pts[i].x, pts[i + 1].y - pts[i].y);
            }
            if (selectedEntity.closed && pts.length > 2) {
                totalLength += Math.hypot(pts[0].x - pts[pts.length - 1].x, pts[0].y - pts[pts.length - 1].y);
            }

            let area = 0;
            if (pts.length > 2) {
                for (let i = 0; i < pts.length; i++) {
                    const j = (i + 1) % pts.length;
                    area += pts[i].x * pts[j].y;
                    area -= pts[j].x * pts[i].y;
                }
                area = Math.abs(area) / 2;
            }

            let segHtml = '';
            if (selectedSegmentIndex !== null && numSegs > 0) {
                const sIdx = selectedSegmentIndex;
                const eIdx = (sIdx + 1) % pts.length;
                const p1 = pts[sIdx];
                const p2 = pts[eIdx];
                const dx = p2.x - p1.x;
                const dy = p2.y - p1.y;
                const sLen = Math.hypot(dx, dy);
                const sAzi = formatAzimuth(dx, dy);

                let segOptions = '';
                for (let i = 0; i < numSegs; i++) {
                    segOptions += `<option value="${i}" ${i === sIdx ? 'selected' : ''}>Segment ${i + 1} (V${i+1} → V${(i+1)%pts.length + 1})</option>`;
                }

                segHtml = `
                    <div class="prop-group">
                        <div class="prop-group-title" style="color: #00e5ff;">Selected Segment</div>
                        <div class="prop-row">
                            <label>Active Segment</label>
                            <select id="prop-seg-select">${segOptions}</select>
                        </div>
                        <div class="prop-row"><label style="color:#4caf50; font-weight:bold;">Start X (P1)</label><input type="text" id="prop-seg-x1" value="${formatCoord(p1.x)}"></div>
                        <div class="prop-row"><label style="color:#4caf50; font-weight:bold;">Start Y (P1)</label><input type="text" id="prop-seg-y1" value="${formatCoord(p1.y)}"></div>
                        <div class="prop-row"><label style="color:#ff9800; font-weight:bold;">End X (P2)</label><input type="text" id="prop-seg-x2" value="${formatCoord(p2.x)}"></div>
                        <div class="prop-row"><label style="color:#ff9800; font-weight:bold;">End Y (P2)</label><input type="text" id="prop-seg-y2" value="${formatCoord(p2.y)}"></div>
                        <div class="prop-row"><label>Delta X (dx)</label><input type="text" readonly value="${formatCoord(dx)}"></div>
                        <div class="prop-row"><label>Delta Y (dy)</label><input type="text" readonly value="${formatCoord(dy)}"></div>
                        <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Length (S)</label><input type="text" id="prop-seg-len" value="${formatCoord(sLen)}"></div>
                        <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Azimuth (${sAzi.unit})</label><input type="text" id="prop-seg-azi" value="${sAzi.val}"></div>
                    </div>
                `;
            }

            let vertexOptions = '';
            pts.forEach((_, idx) => {
                vertexOptions += `<option value="${idx}" ${idx === selectedVertexIndex ? 'selected' : ''}>Vertex V${idx + 1}</option>`;
            });

            let activeVHtml = '';
            if (activeV) {
                const backAziVal = activeV.aziBack !== null ? azimuthRadToValue(activeV.aziBack).toFixed(4) : '-';
                const fwdAziVal = activeV.aziFwd !== null ? azimuthRadToValue(activeV.aziFwd).toFixed(4) : '-';
                const rightAngleVal = activeV.angleRight !== null ? azimuthRadToValue(activeV.angleRight).toFixed(4) : '-';
                const interiorAngleVal = activeV.angleInterior !== null ? azimuthRadToValue(activeV.angleInterior).toFixed(4) : '-';

                activeVHtml = `
                    <div class="prop-row"><label>Position X</label><input type="text" id="prop-v-x" value="${formatCoord(activeV.x)}"></div>
                    <div class="prop-row"><label>Position Y</label><input type="text" id="prop-v-y" value="${formatCoord(activeV.y)}"></div>
                    <div class="prop-row"><label>Back Azimuth</label><input type="text" readonly value="${backAziVal} ${activeV.aziBack !== null ? unit : ''}"></div>
                    <div class="prop-row"><label>Fwd Azimuth</label><input type="text" readonly value="${fwdAziVal} ${activeV.aziFwd !== null ? unit : ''}"></div>
                    <div class="prop-row"><label style="color:#e040fb; font-weight:bold;">Vertex Angle</label><input type="text" readonly value="${rightAngleVal} ${activeV.angleRight !== null ? unit : ''}"></div>
                    <div class="prop-row"><label style="color:#4caf50;">Interior Angle</label><input type="text" readonly value="${interiorAngleVal} ${activeV.angleInterior !== null ? unit : ''}"></div>
                `;
            }

            let tableRows = '';
            vDetails.forEach(v => {
                const angleText = v.hasAngle ? `${azimuthRadToValue(v.angleRight).toFixed(4)}${unit}` : '-';
                tableRows += `
                    <tr class="${v.index === selectedVertexIndex ? 'active-row' : ''}" data-vidx="${v.index}">
                        <td style="text-align:center;">K${v.index + 1}</td>
                        <td>${formatCoord(v.x)}</td>
                        <td>${formatCoord(v.y)}</td>
                        <td style="color:#e040fb;">${angleText}</td>
                    </tr>
                `;
            });

            const theoreticalSum = selectedEntity.closed && pts.length >= 3 
                ? (pts.length - 2) * (angleUnitsSelect.value === 'grad' ? 200 : (angleUnitsSelect.value === 'rad' ? Math.PI : 180))
                : null;

            html += `
                ${segHtml}
                <div class="prop-group">
                    <div class="prop-group-title" style="color: #e040fb;">Vertex Angles</div>
                    <div class="prop-row">
                        <label>Active Vertex</label>
                        <select id="prop-vertex-select">${vertexOptions}</select>
                    </div>
                    ${activeVHtml}
                    
                    <div style="max-height: 140px; overflow-y: auto; margin-top: 8px; border: 1px solid #3f3f46;">
                        <table class="cad-table">
                            <thead>
                                <tr>
                                    <th>Vertex</th>
                                    <th>X</th>
                                    <th>Y</th>
                                    <th>Angle</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows}
                            </tbody>
                        </table>
                    </div>
                    ${theoreticalSum !== null ? `
                        <div style="font-size: 10px; color: #aaa; margin-top: 4px; display: flex; justify-content: space-between;">
                            <span>Theoretical angle sum: <b>${theoreticalSum.toFixed(4)}${unit}</b></span>
                        </div>
                    ` : ''}
                </div>

                <div class="prop-group">
                    <div class="prop-group-title">Polyline Global</div>
                    <div style="margin-bottom: 8px;">
                        <button id="btn-reverse-pline" style="width: 100%; background: #333; padding: 4px; font-size: 11px;">⇄ Reverse Polyline Direction</button>
                    </div>
                    <div class="prop-row"><label style="color:#00e5ff; font-weight:bold;">Elevation (Z)</label><input type="text" id="prop-elevation" value="${formatCoord(selectedEntity.elevation || 0)}"></div>
                    <div class="prop-row"><label>Centroid X</label><input type="text" readonly value="${formatCoord(centroid.x)}"></div>
                    <div class="prop-row"><label>Centroid Y</label><input type="text" readonly value="${formatCoord(centroid.y)}"></div>
                    <div class="prop-row"><label>Closed</label>
                        <select id="prop-closed">
                            <option value="false" ${!selectedEntity.closed ? 'selected' : ''}>No</option>
                            <option value="true" ${selectedEntity.closed ? 'selected' : ''}>Yes</option>
                        </select>
                    </div>
                    <div class="prop-row"><label>Vertices</label><input type="text" readonly value="${pts.length}"></div>
                    <div class="prop-row"><label>Total Length</label><input type="text" readonly value="${formatCoord(totalLength)}"></div>
                    <div class="prop-row"><label>Area</label><input type="text" readonly value="${selectedEntity.closed ? formatCoord(area) : '0.000 (Open)'}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'rect') {
            const area = Math.abs(selectedEntity.w * selectedEntity.h);
            const perimeter = 2 * (Math.abs(selectedEntity.w) + Math.abs(selectedEntity.h));
            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Rectangle Geometry</div>
                    <div class="prop-row"><label>Corner X</label><input type="text" id="prop-rx" value="${formatCoord(selectedEntity.x)}"></div>
                    <div class="prop-row"><label>Corner Y</label><input type="text" id="prop-ry" value="${formatCoord(selectedEntity.y)}"></div>
                    <div class="prop-row"><label>Width</label><input type="text" id="prop-rw" value="${formatCoord(selectedEntity.w)}"></div>
                    <div class="prop-row"><label>Height</label><input type="text" id="prop-rh" value="${formatCoord(selectedEntity.h)}"></div>
                    <div class="prop-row"><label>Area</label><input type="text" readonly value="${formatCoord(area)}"></div>
                    <div class="prop-row"><label>Perimeter</label><input type="text" readonly value="${formatCoord(perimeter)}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'circle') {
            const area = Math.PI * selectedEntity.r * selectedEntity.r;
            const circ = 2 * Math.PI * selectedEntity.r;
            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Circle Geometry</div>
                    <div class="prop-row"><label>Center X</label><input type="text" id="prop-cx" value="${formatCoord(selectedEntity.cx)}"></div>
                    <div class="prop-row"><label>Center Y</label><input type="text" id="prop-cy" value="${formatCoord(selectedEntity.cy)}"></div>
                    <div class="prop-row"><label>Radius</label><input type="text" id="prop-cr" value="${formatCoord(selectedEntity.r)}"></div>
                    <div class="prop-row"><label>Diameter</label><input type="text" readonly value="${formatCoord(selectedEntity.r * 2)}"></div>
                    <div class="prop-row"><label>Circumference</label><input type="text" readonly value="${formatCoord(circ)}"></div>
                    <div class="prop-row"><label>Area</label><input type="text" readonly value="${formatCoord(area)}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'ellipse') {
            const rx = selectedEntity.rx, ry = selectedEntity.ry;
            const area = Math.PI * rx * ry;
            const h = Math.pow(rx - ry, 2) / Math.pow(rx + ry, 2);
            const perimeter = Math.PI * (rx + ry) * (1 + (3 * h) / (10 + Math.sqrt(4 - 3 * h)));

            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Ellipse Geometry</div>
                    <div class="prop-row"><label>Center X</label><input type="text" id="prop-el-cx" value="${formatCoord(selectedEntity.cx)}"></div>
                    <div class="prop-row"><label>Center Y</label><input type="text" id="prop-el-cy" value="${formatCoord(selectedEntity.cy)}"></div>
                    <div class="prop-row"><label>Radius X (Rx)</label><input type="text" id="prop-el-rx" value="${formatCoord(rx)}"></div>
                    <div class="prop-row"><label>Radius Y (Ry)</label><input type="text" id="prop-el-ry" value="${formatCoord(ry)}"></div>
                    <div class="prop-row"><label>Perimeter</label><input type="text" readonly value="${formatCoord(perimeter)}"></div>
                    <div class="prop-row"><label>Area</label><input type="text" readonly value="${formatCoord(area)}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'arc') {
            const r = selectedEntity.r;
            const startAziVal = azimuthRadToValue(selectedEntity.startAzi);
            const endAziVal = azimuthRadToValue(selectedEntity.endAzi);
            const unit = getAngleUnitLabel();

            let delta = normalizeAngle(selectedEntity.endAzi - selectedEntity.startAzi);
            if (delta === 0) delta = 2 * Math.PI;
            const deltaVal = azimuthRadToValue(delta);
            const arcLength = r * delta;
            const chordLength = 2 * r * Math.sin(delta / 2);

            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Arc Geometry</div>
                    <div class="prop-row"><label>Center X</label><input type="text" id="prop-arc-cx" value="${formatCoord(selectedEntity.cx)}"></div>
                    <div class="prop-row"><label>Center Y</label><input type="text" id="prop-arc-cy" value="${formatCoord(selectedEntity.cy)}"></div>
                    <div class="prop-row"><label>Radius (R)</label><input type="text" id="prop-arc-r" value="${formatCoord(r)}"></div>
                    <div class="prop-row"><label style="color:#4caf50;">Start Azimuth (${unit})</label><input type="text" id="prop-arc-start" value="${startAziVal.toFixed(4)}"></div>
                    <div class="prop-row"><label style="color:#ff9800;">End Azimuth (${unit})</label><input type="text" id="prop-arc-end" value="${endAziVal.toFixed(4)}"></div>
                    <div class="prop-row"><label>Included Angle (${unit})</label><input type="text" readonly value="${deltaVal.toFixed(4)} ${unit}"></div>
                    <div class="prop-row"><label style="color:#4ec9b0;">Arc Length (L)</label><input type="text" readonly value="${formatCoord(arcLength)}"></div>
                    <div class="prop-row"><label>Chord Length (C)</label><input type="text" readonly value="${formatCoord(chordLength)}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'dimension') {
            if (selectedEntity.kind === 'angle') {
                const centerX = selectedEntity.cx;
                const centerY = selectedEntity.cy;
                const angleTextPosition = getDimensionTextPosition(selectedEntity);
                const dimensionDecimals = getDimensionDecimals(selectedEntity);
                const angleMode = selectedEntity.angleMode === 'exterior' ? 'exterior' : 'interior';
                const angleValue = azimuthRadToValue(getAngleDimensionSweep(selectedEntity));
                html += `
                    <div class="prop-group">
                        <div class="prop-group-title">Angle Dimension</div>
                        <div class="prop-row"><label>Type</label><input type="text" readonly value="Angle"></div>
                        <div class="prop-row"><label>Center X</label><input type="text" id="prop-angle-dimension-cx" value="${formatCoord(centerX)}"></div>
                        <div class="prop-row"><label>Center Y</label><input type="text" id="prop-angle-dimension-cy" value="${formatCoord(centerY)}"></div>
                        <div class="prop-row"><label>Ray2</label><input type="text" id="prop-angle-dimension-ray2" value="${formatCoord(Math.hypot((selectedEntity.ray2X ?? selectedEntity.cx) - selectedEntity.cx, (selectedEntity.ray2Y ?? selectedEntity.cy) - selectedEntity.cy))}"></div>
                        <div class="prop-row"><label>Angle</label><select id="prop-angle-dimension-mode">
                            <option value="interior" ${angleMode === 'interior' ? 'selected' : ''}>Interior</option>
                            <option value="exterior" ${angleMode === 'exterior' ? 'selected' : ''}>Exterior</option>
                        </select></div>
                        <div class="prop-row"><label>Value (${getAngleUnitLabel()})</label><input type="text" id="prop-angle-dimension-value" value="${angleValue.toFixed(4)}"></div>
                        <div class="prop-row"><label>Text X</label><input type="text" id="prop-angle-dimension-text-x" value="${formatCoord(angleTextPosition.x)}"></div>
                        <div class="prop-row"><label>Text Y</label><input type="text" id="prop-angle-dimension-text-y" value="${formatCoord(angleTextPosition.y)}"></div>
                        <div class="prop-row"><label>Decimals</label><select id="prop-dimension-decimals">
                            ${[0, 1, 2, 3, 4, 5, 6].map(value => `<option value="${value}" ${value === dimensionDecimals ? 'selected' : ''}>${value}</option>`).join('')}
                        </select></div>
                    </div>
                `;
            } else {
                const dimensionLength = Math.hypot(selectedEntity.x2 - selectedEntity.x1, selectedEntity.y2 - selectedEntity.y1);
                const dimensionAngle = azimuthRadToValue(calculateAzimuthRad(selectedEntity.x2 - selectedEntity.x1, selectedEntity.y2 - selectedEntity.y1));
                const dimensionTextPosition = getDimensionTextPosition(selectedEntity);
                const dimensionDecimals = getDimensionDecimals(selectedEntity);
                html += `
                    <div class="prop-group">
                        <div class="prop-group-title">Distance Dimension</div>
                        <div class="prop-row"><label>Type</label><input type="text" readonly value="Distance"></div>
                        <div class="prop-row"><label>Start X (P1)</label><input type="text" id="prop-dimension-x1" value="${formatCoord(selectedEntity.x1)}"></div>
                        <div class="prop-row"><label>Start Y (P1)</label><input type="text" id="prop-dimension-y1" value="${formatCoord(selectedEntity.y1)}"></div>
                        <div class="prop-row"><label>End X (P2)</label><input type="text" id="prop-dimension-x2" value="${formatCoord(selectedEntity.x2)}"></div>
                        <div class="prop-row"><label>End Y (P2)</label><input type="text" id="prop-dimension-y2" value="${formatCoord(selectedEntity.y2)}"></div>
                        <div class="prop-row"><label>Distance</label><input type="text" id="prop-dimension-distance" value="${formatDimensionValue(selectedEntity, dimensionLength)}"></div>
                        <div class="prop-row"><label>Angle</label><input type="text" readonly value="${dimensionAngle.toFixed(4)} ${getAngleUnitLabel()}"></div>
                        <div class="prop-row"><label>Position Offset</label><input type="text" id="prop-dimension-offset" value="${formatCoord(selectedEntity.offset)}"></div>
                        <div class="prop-row"><label>Text X</label><input type="text" id="prop-dimension-text-x" value="${formatCoord(dimensionTextPosition.x)}"></div>
                        <div class="prop-row"><label>Text Y</label><input type="text" id="prop-dimension-text-y" value="${formatCoord(dimensionTextPosition.y)}"></div>
                        <div class="prop-row"><label>Decimals</label><select id="prop-dimension-decimals">
                            ${[0, 1, 2, 3, 4, 5, 6].map(value => `<option value="${value}" ${value === dimensionDecimals ? 'selected' : ''}>${value}</option>`).join('')}
                        </select></div>
                    </div>
                `;
            }
        } else if (selectedEntity.type === 'text') {
            const safeText = String(selectedEntity.text || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const textHeight = Number(selectedEntity.height ?? 0.1);
            const justification = selectedEntity.justify === 'left' ? 'middle-left'
                : selectedEntity.justify === 'right' ? 'middle-right'
                : selectedEntity.justify === 'center' ? 'middle-center'
                : (selectedEntity.justify || 'middle-center');
            const textMode = selectedEntity.textMode === 'multiline' ? 'multiline' : 'one-line';
            const textFont = ['Arial', 'Arial Narrow', 'Tahoma', 'Verdana', 'Consolas', 'Courier New'].includes(selectedEntity.fontFamily)
                ? selectedEntity.fontFamily
                : 'Arial';
            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Text</div>
                    <div class="prop-row"><label>Content</label><textarea id="prop-text-content" rows="${textMode === 'multiline' ? 3 : 1}">${safeText}</textarea></div>
                    <div class="prop-row"><label>Height (m)</label><input type="text" id="prop-text-height" value="${formatCoord(textHeight)}"></div>
                    <div class="prop-row"><label>Text mode</label>
                        <select id="prop-text-mode">
                            <option value="one-line" ${textMode === 'one-line' ? 'selected' : ''}>One line</option>
                            <option value="multiline" ${textMode === 'multiline' ? 'selected' : ''}>Multiline</option>
                        </select>
                    </div>
                    <div class="prop-row"><label>Justification</label>
                        <select id="prop-text-justify">
                            <option value="top-left" ${justification === 'top-left' ? 'selected' : ''}>Top left</option>
                            <option value="top-center" ${justification === 'top-center' ? 'selected' : ''}>Top center</option>
                            <option value="top-right" ${justification === 'top-right' ? 'selected' : ''}>Top right</option>
                            <option value="middle-left" ${justification === 'middle-left' ? 'selected' : ''}>Middle left</option>
                            <option value="middle-center" ${justification === 'middle-center' ? 'selected' : ''}>Middle center</option>
                            <option value="middle-right" ${justification === 'middle-right' ? 'selected' : ''}>Middle right</option>
                            <option value="bottom-left" ${justification === 'bottom-left' ? 'selected' : ''}>Bottom left</option>
                            <option value="bottom-center" ${justification === 'bottom-center' ? 'selected' : ''}>Bottom center</option>
                            <option value="bottom-right" ${justification === 'bottom-right' ? 'selected' : ''}>Bottom right</option>
                        </select>
                    </div>
                    <div class="prop-row"><label>Font</label>
                        <select id="prop-text-font">
                            ${['Arial', 'Arial Narrow', 'Tahoma', 'Verdana', 'Consolas', 'Courier New'].map(font => `<option value="${font}" ${textFont === font ? 'selected' : ''}>${font}</option>`).join('')}
                        </select>
                    </div>
                    <div class="prop-row"><label>Rotation</label><input type="text" id="prop-text-rotation" value="${formatCoord(selectedEntity.rotation || 0)}"></div>
                    <div class="prop-row"><label>X</label><input type="text" id="prop-text-x" value="${formatCoord(selectedEntity.x)}"></div>
                    <div class="prop-row"><label>Y</label><input type="text" id="prop-text-y" value="${formatCoord(selectedEntity.y)}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'point') {
            const pointShowText = selectedEntity.showText !== false;
            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Point Geometry</div>
                    <div class="prop-row"><label>Name</label><input type="text" id="prop-point-name" value="${selectedEntity.name || ''}"></div>
                    <div class="prop-row"><label>Show Text</label><select id="prop-point-show-text">
                        <option value="true" ${pointShowText ? 'selected' : ''}>Yes</option>
                        <option value="false" ${pointShowText ? '' : 'selected'}>No</option>
                    </select></div>
                    <div class="prop-row"><label>Position X</label><input type="text" id="prop-px" value="${formatCoord(selectedEntity.x)}"></div>
                    <div class="prop-row"><label>Position Y</label><input type="text" id="prop-py" value="${formatCoord(selectedEntity.y)}"></div>
                    <div class="prop-row"><label>Elevation Z</label><input type="text" id="prop-pz" value="${formatCoord(selectedEntity.z || 0)}"></div>
                </div>
            `;
        }

        propContainer.innerHTML = html;
        bindOsnapProperties();

        const bindInput = (id, callback) => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', (e) => { 
                saveState();
                callback(parseStrictFloat(e.target.value)); 
                render(); 
                showToast('Entity updated.', 'success', 1500);
            });
        };

        const colorInput = document.getElementById('prop-color');
        bindUsernameInput();

        if (colorInput) colorInput.addEventListener('change', (e) => {
            saveState();
            selectedEntity.color = e.target.value; 
            render(); 
            triggerAutoSave();
            showToast('Entity color updated.', 'success', 1500);
        });

        const widthSelect = document.getElementById('prop-width');
        if (widthSelect) widthSelect.addEventListener('change', (e) => { 
            saveState(); 
            selectedEntity.width = parseInt(e.target.value); 
            render(); 
            showToast('Entity line width updated.', 'success', 1500);
        });

        if (selectedEntity.hatch) {
            const hatch = selectedEntity.hatch === true ? { pattern: 'diagonal', spacing: 10, angle: 45, distance: 10, sideSign: 1 } : selectedEntity.hatch;
            const updateHatch = (property, value) => {
                saveState();
                selectedEntity.hatch = hatch;
                hatch[property] = value;
                updatePropertiesPalette();
                render();
            };
            const hatchEnabled = document.getElementById('prop-hatch-enabled');
            if (hatchEnabled) hatchEnabled.addEventListener('change', (e) => {
                saveState();
                selectedEntity.hatch = e.target.value === 'true' ? hatch : null;
                updatePropertiesPalette();
                render();
            });
            const hatchDistance = document.getElementById('prop-hatch-distance');
            if (hatchDistance) hatchDistance.addEventListener('change', (e) => {
                const value = parseStrictFloat(e.target.value, NaN);
                if (!Number.isFinite(value) || value <= 0) {
                    updatePropertiesPalette();
                    showToast('Hatch offset must be greater than zero.', 'error', 1800);
                    return;
                }
                updateHatch('distance', value);
            });
            const hatchSpacing = document.getElementById('prop-hatch-spacing');
            if (hatchSpacing) hatchSpacing.addEventListener('change', (e) => {
                const value = parseStrictFloat(e.target.value, NaN);
                if (!Number.isFinite(value) || value <= 0) {
                    updatePropertiesPalette();
                    showToast('Hatch spacing must be greater than zero.', 'error', 1800);
                    return;
                }
                updateHatch('spacing', value);
            });
            const hatchAngle = document.getElementById('prop-hatch-angle');
            if (hatchAngle) hatchAngle.addEventListener('change', (e) => {
                const value = parseStrictFloat(e.target.value, NaN);
                if (!Number.isFinite(value)) {
                    updatePropertiesPalette();
                    showToast('Hatch angle must be a number.', 'error', 1800);
                    return;
                }
                updateHatch('angle', value);
            });
            const hatchSide = document.getElementById('prop-hatch-side');
            if (hatchSide) hatchSide.addEventListener('change', (e) => updateHatch('sideSign', Number(e.target.value) < 0 ? -1 : 1));
        }

        if (selectedEntity.type === 'text') {
            const textContentInput = document.getElementById('prop-text-content');
            if (textContentInput) {
                let textEditSnapshotSaved = false;
                textContentInput.addEventListener('input', (e) => {
                    if (!textEditSnapshotSaved) {
                        saveState();
                        textEditSnapshotSaved = true;
                    }
                    selectedEntity.text = e.target.value || 'TEXT';
                    render();
                    triggerAutoSave();
                });
                textContentInput.addEventListener('blur', () => {
                    textEditSnapshotSaved = false;
                });
                textContentInput.addEventListener('change', (e) => {
                    textEditSnapshotSaved = false;
                    selectedEntity.text = e.target.value || 'TEXT';
                    updatePropertiesPalette();
                    render();
                    showToast('Text content updated.', 'success', 1500);
                });
            }

            const textModeSelect = document.getElementById('prop-text-mode');
            if (textModeSelect) textModeSelect.addEventListener('change', (e) => {
                saveState();
                selectedEntity.textMode = e.target.value === 'multiline' ? 'multiline' : 'one-line';
                if (selectedEntity.textMode === 'one-line') selectedEntity.text = selectedEntity.text.replace(/[\r\n]+/g, ' ');
                updatePropertiesPalette();
                render();
                showToast('Text mode updated.', 'success', 1500);
            });

            const textHeightInput = document.getElementById('prop-text-height');
            if (textHeightInput) textHeightInput.addEventListener('change', (e) => {
                const value = parseStrictFloat(e.target.value, NaN);
                if (!Number.isFinite(value) || value <= 0) {
                    updatePropertiesPalette();
                    showToast('Text height must be greater than zero.', 'error', 1800);
                    return;
                }
                saveState();
                selectedEntity.height = value;
                delete selectedEntity.size;
                updatePropertiesPalette();
                render();
                showToast('Text height updated.', 'success', 1500);
            });

            const textJustifySelect = document.getElementById('prop-text-justify');
            if (textJustifySelect) textJustifySelect.addEventListener('change', (e) => {
                saveState();
                selectedEntity.justify = e.target.value || 'middle-center';
                updatePropertiesPalette();
                render();
                showToast('Text justification updated.', 'success', 1500);
            });

            const textFontSelect = document.getElementById('prop-text-font');
            if (textFontSelect) textFontSelect.addEventListener('change', (e) => {
                saveState();
                const allowedFonts = ['Arial', 'Arial Narrow', 'Tahoma', 'Verdana', 'Consolas', 'Courier New'];
                selectedEntity.fontFamily = allowedFonts.includes(e.target.value) ? e.target.value : 'Arial';
                updatePropertiesPalette();
                render();
                triggerAutoSave();
                showToast('Text font updated.', 'success', 1500);
            });

            bindInput('prop-text-rotation', value => { selectedEntity.rotation = value; updatePropertiesPalette(); });
            bindInput('prop-text-x', value => { selectedEntity.x = value; updatePropertiesPalette(); });
            bindInput('prop-text-y', value => { selectedEntity.y = value; updatePropertiesPalette(); });
        }

        if (selectedEntity.type === 'dimension') {
            if (selectedEntity.kind === 'angle') {
                bindInput('prop-angle-dimension-cx', value => { selectedEntity.cx = value; updatePropertiesPalette(); });
                bindInput('prop-angle-dimension-cy', value => { selectedEntity.cy = value; updatePropertiesPalette(); });
                bindInput('prop-angle-dimension-ray2', value => {
                    const ray2 = getAngleDimensionRayEnd(selectedEntity, 'end');
                    const currentLength = Math.hypot(ray2.x - selectedEntity.cx, ray2.y - selectedEntity.cy);
                    if (!Number.isFinite(value) || currentLength < 1e-9) return;
                    const length = Math.max(0.001, value);
                    selectedEntity.ray2X = selectedEntity.cx + (ray2.x - selectedEntity.cx) / currentLength * length;
                    selectedEntity.ray2Y = selectedEntity.cy + (ray2.y - selectedEntity.cy) / currentLength * length;
                    updatePropertiesPalette();
                });
                bindInput('prop-angle-dimension-text-x', value => { selectedEntity.textX = value; updatePropertiesPalette(); });
                bindInput('prop-angle-dimension-text-y', value => { selectedEntity.textY = value; updatePropertiesPalette(); });

                const angleModeSelect = document.getElementById('prop-angle-dimension-mode');
                if (angleModeSelect) angleModeSelect.addEventListener('change', (e) => {
                    saveState();
                    setAngleDimensionMode(selectedEntity, e.target.value);
                    updatePropertiesPalette();
                    render();
                    showToast('Angle dimension mode updated.', 'success', 1500);
                });

                const angleValueInput = document.getElementById('prop-angle-dimension-value');
                if (angleValueInput) angleValueInput.addEventListener('change', (e) => {
                    const value = parseStrictFloat(e.target.value, NaN);
                    const updatedDimension = JSON.parse(JSON.stringify(selectedEntity));
                    if (!setAngleDimensionValue(updatedDimension, value)) {
                        updatePropertiesPalette();
                        showToast('Enter an angle between 0 and the full circle for the selected mode.', 'error', 2200);
                        return;
                    }
                    saveState();
                    Object.assign(selectedEntity, updatedDimension);
                    updatePropertiesPalette();
                    render();
                    showToast('Angle dimension updated.', 'success', 1500);
                });

                const dimensionDecimalsSelect = document.getElementById('prop-dimension-decimals');
                if (dimensionDecimalsSelect) dimensionDecimalsSelect.addEventListener('change', (e) => {
                    saveState();
                    selectedEntity.decimals = Number(e.target.value);
                    updatePropertiesPalette();
                    render();
                    showToast('Dimension decimals updated.', 'success', 1500);
                });
            } else {
                bindInput('prop-dimension-x1', value => { selectedEntity.x1 = value; updatePropertiesPalette(); });
                bindInput('prop-dimension-y1', value => { selectedEntity.y1 = value; updatePropertiesPalette(); });
                bindInput('prop-dimension-x2', value => { selectedEntity.x2 = value; updatePropertiesPalette(); });
                bindInput('prop-dimension-y2', value => { selectedEntity.y2 = value; updatePropertiesPalette(); });

                const dimensionDistanceInput = document.getElementById('prop-dimension-distance');
                if (dimensionDistanceInput) dimensionDistanceInput.addEventListener('change', (e) => {
                    const newDistance = parseStrictFloat(e.target.value, NaN);
                    const dx = selectedEntity.x2 - selectedEntity.x1;
                    const dy = selectedEntity.y2 - selectedEntity.y1;
                    const currentDistance = Math.hypot(dx, dy);
                    if (!Number.isFinite(newDistance) || newDistance <= 0 || currentDistance < 1e-9) {
                        updatePropertiesPalette();
                        showToast('Distance must be greater than zero.', 'error', 1800);
                        return;
                    }
                    saveState();
                    selectedEntity.x2 = selectedEntity.x1 + dx / currentDistance * newDistance;
                    selectedEntity.y2 = selectedEntity.y1 + dy / currentDistance * newDistance;
                    updatePropertiesPalette();
                    render();
                    showToast('Dimension distance updated.', 'success', 1500);
                });

                const dimensionOffsetInput = document.getElementById('prop-dimension-offset');
                if (dimensionOffsetInput) dimensionOffsetInput.addEventListener('change', (e) => {
                    const newOffset = parseStrictFloat(e.target.value, NaN);
                    const dx = selectedEntity.x2 - selectedEntity.x1;
                    const dy = selectedEntity.y2 - selectedEntity.y1;
                    const currentDistance = Math.hypot(dx, dy);
                    if (!Number.isFinite(newOffset) || currentDistance < 1e-9) {
                        updatePropertiesPalette();
                        showToast('Position offset must be a number.', 'error', 1800);
                        return;
                    }
                    saveState();
                    selectedEntity.offset = newOffset;
                    selectedEntity.textX = (selectedEntity.x1 + selectedEntity.x2) / 2 - dy / currentDistance * newOffset;
                    selectedEntity.textY = (selectedEntity.y1 + selectedEntity.y2) / 2 + dx / currentDistance * newOffset;
                    updatePropertiesPalette();
                    render();
                    showToast('Dimension position updated.', 'success', 1500);
                });

                bindInput('prop-dimension-text-x', value => {
                    selectedEntity.textX = value;
                    updatePropertiesPalette();
                });
                bindInput('prop-dimension-text-y', value => {
                    selectedEntity.textY = value;
                    updatePropertiesPalette();
                });

                const dimensionDecimalsSelect = document.getElementById('prop-dimension-decimals');
                if (dimensionDecimalsSelect) dimensionDecimalsSelect.addEventListener('change', (e) => {
                    saveState();
                    selectedEntity.decimals = Number(e.target.value);
                    updatePropertiesPalette();
                    render();
                    showToast('Dimension decimals updated.', 'success', 1500);
                });
            }
        } else if (selectedEntity.type === 'line') {
            bindInput('prop-x1', v => { selectedEntity.x1 = v; updatePropertiesPalette(); });
            bindInput('prop-y1', v => { selectedEntity.y1 = v; updatePropertiesPalette(); });
            bindInput('prop-x2', v => { selectedEntity.x2 = v; updatePropertiesPalette(); });
            bindInput('prop-y2', v => { selectedEntity.y2 = v; updatePropertiesPalette(); });

            const lenInput = document.getElementById('prop-len');
            if (lenInput) {
                lenInput.addEventListener('change', (e) => {
                    saveState();
                    const newLen = Math.max(0.001, parseStrictFloat(e.target.value, 0.001));
                    const dx = selectedEntity.x2 - selectedEntity.x1;
                    const dy = selectedEntity.y2 - selectedEntity.y1;
                    const currentAziRad = calculateAzimuthRad(dx, dy);

                    selectedEntity.x2 = selectedEntity.x1 + newLen * Math.sin(currentAziRad);
                    selectedEntity.y2 = selectedEntity.y1 + newLen * Math.cos(currentAziRad);
                    updatePropertiesPalette();
                    render();
                    showToast('Entity length updated.', 'success', 1500);
                });
            }

            const aziInput = document.getElementById('prop-azi');
            if (aziInput) {
                aziInput.addEventListener('change', (e) => {
                    saveState();
                    const newAziVal = parseStrictFloat(e.target.value, 0);
                    const newAziRad = azimuthValueToRad(newAziVal);
                    const currentLen = Math.hypot(selectedEntity.x2 - selectedEntity.x1, selectedEntity.y2 - selectedEntity.y1);

                    selectedEntity.x2 = selectedEntity.x1 + currentLen * Math.sin(newAziRad);
                    selectedEntity.y2 = selectedEntity.y1 + currentLen * Math.cos(newAziRad);
                    updatePropertiesPalette();
                    render();
                    showToast('Entity azimuth updated.', 'success', 1500);
                });
            }

            const revBtn = document.getElementById('btn-reverse-line');
            if (revBtn) {
                revBtn.addEventListener('click', () => {
                    saveState();
                    const tx = selectedEntity.x1, ty = selectedEntity.y1;
                    selectedEntity.x1 = selectedEntity.x2;
                    selectedEntity.y1 = selectedEntity.y2;
                    selectedEntity.x2 = tx;
                    selectedEntity.y2 = ty;
                    updatePropertiesPalette();
                    render();
                    showToast('Line direction reversed.', 'info');
                });
            }
        } else if (selectedEntity.type === 'pline') {
            const segSelect = document.getElementById('prop-seg-select');
            if (segSelect) {
                segSelect.addEventListener('change', (e) => {
                    selectedSegmentIndex = parseInt(e.target.value);
                    updatePropertiesPalette();
                    render();
                });
            }

            const vertexSelect = document.getElementById('prop-vertex-select');
            if (vertexSelect) {
                vertexSelect.addEventListener('change', (e) => {
                    selectedVertexIndex = parseInt(e.target.value);
                    updatePropertiesPalette();
                    render();
                });
            }

            document.querySelectorAll('.cad-table tr[data-vidx]').forEach(tr => {
                tr.addEventListener('click', () => {
                    selectedVertexIndex = parseInt(tr.dataset.vidx);
                    updatePropertiesPalette();
                    render();
                });
            });

            bindInput('prop-v-x', v => {
                if (selectedEntity.points[selectedVertexIndex]) {
                    selectedEntity.points[selectedVertexIndex].x = v;
                    updatePropertiesPalette();
                }
            });
            bindInput('prop-v-y', v => {
                if (selectedEntity.points[selectedVertexIndex]) {
                    selectedEntity.points[selectedVertexIndex].y = v;
                    updatePropertiesPalette();
                }
            });

            bindInput('prop-elevation', v => {
                selectedEntity.elevation = v;
                updatePropertiesPalette();
            });

            if (selectedSegmentIndex !== null && selectedEntity.points.length > 1) {
                const sIdx = selectedSegmentIndex;
                const eIdx = (sIdx + 1) % selectedEntity.points.length;

                bindInput('prop-seg-x1', v => { selectedEntity.points[sIdx].x = v; updatePropertiesPalette(); });
                bindInput('prop-seg-y1', v => { selectedEntity.points[sIdx].y = v; updatePropertiesPalette(); });
                bindInput('prop-seg-x2', v => { selectedEntity.points[eIdx].x = v; updatePropertiesPalette(); });
                bindInput('prop-seg-y2', v => { selectedEntity.points[eIdx].y = v; updatePropertiesPalette(); });

                const segLenInput = document.getElementById('prop-seg-len');
                if (segLenInput) {
                    segLenInput.addEventListener('change', (e) => {
                        saveState();
                        const newLen = Math.max(0.001, parseStrictFloat(e.target.value, 0.001));
                        const p1 = selectedEntity.points[sIdx];
                        const p2 = selectedEntity.points[eIdx];
                        const currentAziRad = calculateAzimuthRad(p2.x - p1.x, p2.y - p1.y);

                        selectedEntity.points[eIdx].x = p1.x + newLen * Math.sin(currentAziRad);
                        selectedEntity.points[eIdx].y = p1.y + newLen * Math.cos(currentAziRad);
                        updatePropertiesPalette();
                        render();
                        showToast('Polyline segment length updated.', 'success', 1500);
                    });
                }

                const segAziInput = document.getElementById('prop-seg-azi');
                if (segAziInput) {
                    segAziInput.addEventListener('change', (e) => {
                        saveState();
                        const newAziVal = parseStrictFloat(e.target.value, 0);
                        const newAziRad = azimuthValueToRad(newAziVal);
                        const p1 = selectedEntity.points[sIdx];
                        const p2 = selectedEntity.points[eIdx];
                        const currentLen = Math.hypot(p2.x - p1.x, p2.y - p1.y);

                        selectedEntity.points[eIdx].x = p1.x + currentLen * Math.sin(newAziRad);
                        selectedEntity.points[eIdx].y = p1.y + currentLen * Math.cos(newAziRad);
                        updatePropertiesPalette();
                        render();
                        showToast('Polyline segment azimuth updated.', 'success', 1500);
                    });
                }
            }

            const revPlineBtn = document.getElementById('btn-reverse-pline');
            if (revPlineBtn) {
                revPlineBtn.addEventListener('click', () => {
                    saveState();
                    selectedEntity.points.reverse();
                    if (selectedSegmentIndex !== null) {
                        const totalSegs = selectedEntity.closed ? selectedEntity.points.length : selectedEntity.points.length - 1;
                        selectedSegmentIndex = Math.max(0, totalSegs - 1 - selectedSegmentIndex);
                    }
                    selectedVertexIndex = (selectedEntity.points.length - 1 - selectedVertexIndex);
                    updatePropertiesPalette();
                    render();
                    showToast('Polyline direction reversed.', 'info');
                });
            }

            const closedSelect = document.getElementById('prop-closed');
            if (closedSelect) {
                closedSelect.addEventListener('change', (e) => {
                    saveState();
                    selectedEntity.closed = (e.target.value === 'true');
                    updatePropertiesPalette();
                    render();
                    showToast(`Polyline ${selectedEntity.closed ? 'closed' : 'opened'}.`, 'success', 1500);
                });
            }
        } else if (selectedEntity.type === 'rect') {
            bindInput('prop-rx', v => selectedEntity.x = v);
            bindInput('prop-ry', v => selectedEntity.y = v);
            bindInput('prop-rw', v => selectedEntity.w = v);
            bindInput('prop-rh', v => selectedEntity.h = v);
        } else if (selectedEntity.type === 'circle') {
            bindInput('prop-cx', v => selectedEntity.cx = v);
            bindInput('prop-cy', v => selectedEntity.cy = v);
            bindInput('prop-cr', v => selectedEntity.r = Math.max(0.001, v));
        } else if (selectedEntity.type === 'ellipse') {
            bindInput('prop-el-cx', v => selectedEntity.cx = v);
            bindInput('prop-el-cy', v => selectedEntity.cy = v);
            bindInput('prop-el-rx', v => selectedEntity.rx = Math.max(0.001, v));
            bindInput('prop-el-ry', v => selectedEntity.ry = Math.max(0.001, v));
        } else if (selectedEntity.type === 'arc') {
            bindInput('prop-arc-cx', v => selectedEntity.cx = v);
            bindInput('prop-arc-cy', v => selectedEntity.cy = v);
            bindInput('prop-arc-r', v => selectedEntity.r = Math.max(0.001, v));
            bindInput('prop-arc-start', v => { selectedEntity.startAzi = azimuthValueToRad(v); updatePropertiesPalette(); });
            bindInput('prop-arc-end', v => { selectedEntity.endAzi = azimuthValueToRad(v); updatePropertiesPalette(); });
        } else if (selectedEntity.type === 'point') {
            const pointNameInput = document.getElementById('prop-point-name');
            if (pointNameInput) pointNameInput.addEventListener('change', (e) => {
                saveState();
                selectedEntity.name = e.target.value;
                render();
                showToast('Point name updated.', 'success', 1500);
            });
            const pointShowTextSelect = document.getElementById('prop-point-show-text');
            if (pointShowTextSelect) pointShowTextSelect.addEventListener('change', (e) => {
                saveState();
                selectedEntity.showText = e.target.value === 'true';
                updatePropertiesPalette();
                render();
                showToast(`Point text ${selectedEntity.showText ? 'enabled' : 'disabled'}.`, 'success', 1500);
            });
            bindInput('prop-px', v => selectedEntity.x = v);
            bindInput('prop-py', v => selectedEntity.y = v);
            bindInput('prop-pz', v => selectedEntity.z = v);
        }
    }

    canvas.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        if (currentTool === 'pline' && isDrawing) {
            finishPline(false);
        }
    });

    canvas.addEventListener('mousedown', (e) => {
        if (e.button === 1) {
            const now = performance.now();
            if (now - lastMiddleClickTime < 450) {
                lastMiddleClickTime = 0;
                isPanning = false;
                zoomToExtents();
                return;
            }
            lastMiddleClickTime = now;
        }

        if (e.button === 1 || e.buttons === 4 || e.altKey) {
            isPanning = true;
            panStart = { x: e.clientX - camera.x, y: e.clientY - camera.y };
            return;
        }

        const rect = canvas.getBoundingClientRect();
        const mouseScreen = { x: e.clientX - rect.left, y: e.clientY - rect.top };
        if (e.button === 0 && hitTestNorthSymbol(mouseScreen.x, mouseScreen.y)) {
            northSymbolDrag = {
                offsetX: mouseScreen.x - getNorthSymbolRect().x,
                offsetY: mouseScreen.y - getNorthSymbolRect().y
            };
            canvas.style.cursor = 'move';
            return;
        }
        if (e.button === 0 && currentTool === 'select' && !moveCommand && !activeGrip) {
            const frameHit = hitTestPrintFrame(mouseScreen.x, mouseScreen.y);
            if (frameHit.onBorder && !hitTestEntity(screenToWorld(mouseScreen.x, mouseScreen.y), mouseScreen)) {
                const frameRect = getPrintFrameRectWorld();
                paperFrameDrag = {
                    offsetX: screenToWorld(mouseScreen.x, mouseScreen.y).x - (frameRect.left + frameRect.width / 2),
                    offsetY: screenToWorld(mouseScreen.x, mouseScreen.y).y - (frameRect.bottom + frameRect.height / 2)
                };
                canvas.style.cursor = 'move';
                return;
            }
        }
        const mouseWorld = screenToWorld(mouseScreen.x, mouseScreen.y);

        if (e.button === 0 && isImageCaptureMode) {
            imageCaptureSelection = { start: mouseScreen, current: mouseScreen };
            canvas.style.cursor = 'crosshair';
            render();
            return;
        }

        if (e.button === 0 && currentTool === 'board') {
            const boardSnap = findBestSnap(mouseScreen.x, mouseScreen.y);
            const boardPoint = boardSnap ? { x: boardSnap.worldX, y: boardSnap.worldY } : mouseWorld;
            const boardEntity = createBoardAtPoint(boardPoint);
            if (!boardEntity) {
                showToast('Board template could not be created.', 'error', 1800);
                return;
            }
            saveState();
            entities.push(boardEntity);
            selectedEntity = boardEntity;
            selectedEntities = new Set([boardEntity]);
            selectedSegmentIndex = null;
            selectedVertexIndex = 0;
            setActiveToolbarButton('tool-select');
            currentTool = 'select';
            statusMode.innerText = 'MODE: SELECT';
            canvas.style.cursor = 'default';
            updatePropertiesPalette();
            render();
            triggerAutoSave();
            showToast('Πινακίδα inserted at the selected point.', 'success', 2200);
            return;
        }

        if (e.button === 0 && currentTool === 'text') {
            const textSnap = findBestSnap(mouseScreen.x, mouseScreen.y);
            const textPoint = textSnap ? { x: textSnap.worldX, y: textSnap.worldY } : mouseWorld;
            const textEntity = createTextEntityAtPoint(textPoint);
            if (!textEntity) {
                setActiveToolbarButton('tool-select');
                currentTool = 'select';
                statusMode.innerText = 'MODE: SELECT';
                canvas.style.cursor = 'default';
                render();
                return;
            }
            setActiveToolbarButton('tool-select');
            currentTool = 'select';
            statusMode.innerText = 'MODE: SELECT';
            canvas.style.cursor = 'default';
            render();
            return;
        }

        // Calculate snap at mousedown for initial coordinates when drawing
        if (e.button === 0 && currentTool !== 'select') {
            const exclude = activeGrip ? activeGrip.entity : null;
            activeSnap = findBestSnap(mouseScreen.x, mouseScreen.y, exclude);
            if (activeSnap) {
                currentMouse = { x: activeSnap.worldX, y: activeSnap.worldY };
            } else {
                currentMouse = mouseWorld;
            }
        }

        if (e.button === 0) {
            if (hatchCommand) {
                const sidePoint = screenToWorld(mouseScreen.x, mouseScreen.y);
                const sideSign = getHatchSideSign(hatchCommand.entity, sidePoint);
                if (sideSign === null) {
                    showToast('Click clearly on one side of the object.', 'warning', 1800);
                    return;
                }
                saveState();
                hatchCommand.entity.hatch = {
                    pattern: 'diagonal',
                    spacing: 10,
                    angle: 45,
                    distance: hatchCommand.distance,
                    sideSign
                };
                hatchCommand = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                updatePropertiesPalette();
                render();
                showToast('Hatch created.', 'success', 1500);
                return;
            }
            if (angleDimensionCommand) {
                const commandPoint = activeSnap
                    ? { x: activeSnap.worldX, y: activeSnap.worldY }
                    : getCommandPoint(mouseScreen.x, mouseScreen.y, { discreteOnly: true });
                if (!angleDimensionCommand.vertex) {
                    angleDimensionCommand.vertex = commandPoint;
                    statusMode.innerText = 'ANGLE DIMENSION: FIRST RAY';
                    showToast('Select the first ray endpoint.', 'info', 2200);
                    render();
                    return;
                }
                if (!angleDimensionCommand.firstPoint) {
                    angleDimensionCommand.firstPoint = commandPoint;
                    statusMode.innerText = 'ANGLE DIMENSION: SECOND RAY';
                    showToast('Select the second ray endpoint.', 'info', 2200);
                    render();
                    return;
                }
                if (!angleDimensionCommand.secondPoint) {
                    angleDimensionCommand.secondPoint = commandPoint;
                    statusMode.innerText = 'ANGLE DIMENSION: POSITION';
                    showToast('Click where the angle value should appear.', 'info', 2200);
                    render();
                    return;
                }
                const dimension = createAngleDimension(
                    angleDimensionCommand.vertex,
                    angleDimensionCommand.firstPoint,
                    angleDimensionCommand.secondPoint,
                    mouseWorld
                );
                if (!dimension) {
                    showToast('The angle rays must not be zero-length.', 'error', 1800);
                    angleDimensionCommand = null;
                    statusMode.innerText = 'MODE: SELECT';
                    render();
                    return;
                }
                saveState();
                entities.push(dimension);
                angleDimensionCommand = null;
                selectedEntity = dimension;
                selectedEntities = new Set([dimension]);
                selectedSegmentIndex = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                updatePropertiesPalette();
                render();
                triggerAutoSave();
                showToast('Angle dimension created.', 'success', 1500);
                return;
            }
            if (dimensionCommand) {
                const commandPoint = getCommandPoint(mouseScreen.x, mouseScreen.y);
                if (!dimensionCommand.firstPoint) {
                    dimensionCommand.firstPoint = commandPoint;
                    statusMode.innerText = 'DIMENSION: SECOND POINT';
                    showToast('Select the second point.', 'info', 2200);
                    render();
                    return;
                }
                if (!dimensionCommand.secondPoint) {
                    dimensionCommand.secondPoint = commandPoint;
                    statusMode.innerText = 'DIMENSION: POSITION';
                    showToast('Click where the dimension line should appear.', 'info', 2200);
                    render();
                    return;
                }
                const dimension = createDistanceDimension(
                    dimensionCommand.firstPoint,
                    dimensionCommand.secondPoint,
                    mouseWorld
                );
                if (!dimension) {
                    showToast('The two dimension points must be different.', 'error', 1800);
                    dimensionCommand = null;
                    statusMode.innerText = 'MODE: SELECT';
                    render();
                    return;
                }
                saveState();
                entities.push(dimension);
                dimensionCommand = null;
                selectedEntity = dimension;
                selectedEntities = new Set([dimension]);
                selectedSegmentIndex = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                updatePropertiesPalette();
                render();
                triggerAutoSave();
                showToast('Distance dimension created.', 'success', 1500);
                return;
            }
            if (offsetCommand) {
                const sidePoint = screenToWorld(mouseScreen.x, mouseScreen.y);
                const offsetEntity = getOffsetEntity(offsetCommand.source, offsetCommand.distance, sidePoint);
                if (!offsetEntity) {
                    showToast('Offset could not be created at that distance.', 'error', 1800);
                    offsetCommand = null;
                    statusMode.innerText = 'MODE: SELECT';
                    render();
                    return;
                }
                saveState();
                entities.push(offsetEntity);
                offsetCommand = null;
                selectedEntity = offsetEntity;
                selectedEntities = new Set([offsetEntity]);
                selectedSegmentIndex = offsetEntity.type === 'pline' ? 0 : null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                updatePropertiesPalette();
                render();
                triggerAutoSave();
                showToast('Offset created.', 'success', 1500);
                return;
            }
            if (trimCommand) {
                const trimPoint = screenToWorld(mouseScreen.x, mouseScreen.y);
                const trimmed = trimEntityAtPoint(trimCommand.source, trimPoint);
                if (!trimmed) {
                    trimCommand = null;
                    setActiveToolbarButton('tool-select');
                    statusMode.innerText = 'MODE: SELECT';
                    render();
                    showToast('Trim point not found on the selected object.', 'warning', 1800);
                    return;
                }
                saveState();
                const index = entities.indexOf(trimCommand.source);
                if (index >= 0) {
                    entities[index] = trimmed;
                }
                trimCommand = null;
                selectedEntity = trimmed;
                selectedEntities = new Set([trimmed]);
                selectedSegmentIndex = trimmed.type === 'pline' ? 0 : null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                updatePropertiesPalette();
                render();
                triggerAutoSave();
                showToast('Object trimmed.', 'success', 1500);
                return;
            }
            if (moveCommand) {
                const commandPoint = getCommandPoint(mouseScreen.x, mouseScreen.y);
                if (!moveCommand.basePoint) {
                    moveCommand.basePoint = commandPoint;
                    moveCommand.targetPoint = commandPoint;
                    statusMode.innerText = 'MOVE: FINAL POINT';
                    showToast('Specify the final point.', 'info', 2200);
                    render();
                } else {
                    moveCommand.targetPoint = commandPoint;
                    const offsetX = commandPoint.x - moveCommand.basePoint.x;
                    const offsetY = commandPoint.y - moveCommand.basePoint.y;
                    saveState();
                    moveCommand.source.forEach(item => {
                        const movedEntity = translateEntity(
                            JSON.parse(JSON.stringify(item.state)), offsetX, offsetY
                        );
                        Object.keys(item.entity).forEach(key => delete item.entity[key]);
                        Object.assign(item.entity, movedEntity);
                    });
                    moveCommand = null;
                    setActiveToolbarButton('tool-select');
                    statusMode.innerText = 'MODE: SELECT';
                    updatePropertiesPalette();
                    render();
                    triggerAutoSave();
                    showToast('Object(s) moved.', 'success', 1500);
                }
                return;
            }
            if (scaleCommand) {
                const commandPoint = getCommandPoint(mouseScreen.x, mouseScreen.y);
                if (!scaleCommand.basePoint) {
                    scaleCommand.basePoint = commandPoint;
                    statusMode.innerText = 'SCALE: REFERENCE';
                    showToast('Specify the reference distance.', 'info', 2200);
                    render();
                } else if (!scaleCommand.referencePoint) {
                    scaleCommand.referencePoint = commandPoint;
                    statusMode.innerText = 'SCALE: FINAL POINT';
                    showToast('Specify the final distance.', 'info', 2200);
                } else {
                    const referenceDistance = Math.hypot(
                        scaleCommand.referencePoint.x - scaleCommand.basePoint.x,
                        scaleCommand.referencePoint.y - scaleCommand.basePoint.y
                    );
                    const distance = Math.hypot(commandPoint.x - scaleCommand.basePoint.x, commandPoint.y - scaleCommand.basePoint.y);
                    const factor = referenceDistance > 1e-9 ? Math.max(0.001, distance / referenceDistance) : 1;
                    saveState();
                    scaleCommand.source.forEach(item => {
                        const scaled = scaleEntity(JSON.parse(JSON.stringify(item.state)), scaleCommand.basePoint, factor);
                        Object.keys(item.entity).forEach(key => delete item.entity[key]);
                        Object.assign(item.entity, scaled);
                    });
                    scaleCommand = null;
                    setActiveToolbarButton('tool-select');
                    statusMode.innerText = 'MODE: SELECT';
                    updatePropertiesPalette();
                    render();
                    triggerAutoSave();
                    showToast('Object(s) scaled.', 'success', 1500);
                }
                return;
            }

            if (currentTool === 'select') {
                if (pastePreview) {
                    const pasted = getPastePreviewEntities();
                    saveState();
                    entities.push(...pasted);
                    selectedEntities = new Set(pasted);
                    selectedEntity = pasted[pasted.length - 1] || null;
                    selectedSegmentIndex = null;
                    pastePreview = null;
                    updatePropertiesPalette();
                    render();
                    triggerAutoSave();
                    showToast(`${pasted.length} object(s) pasted.`, 'success', 1500);
                    return;
                }

                if (selectedEntity && selectedEntities.size <= 1) {
                    const gripHit = hitTestGrip(mouseScreen.x, mouseScreen.y, selectedEntity);
                    if (gripHit) {
                        saveState();
                        activeGrip = {
                            ...gripHit,
                            startWorld: { ...mouseWorld },
                            initialState: JSON.parse(JSON.stringify(selectedEntity))
                        };
                        statusMode.innerText = `GRIP: ${gripHit.type.toUpperCase()}`;
                        if (gripHit.type === 'vertex') {
                            selectedVertexIndex = gripHit.index;
                            updatePropertiesPalette();
                        }
                        render();
                        return;
                    }
                }

                const angleGripHit = hitTestAngleDimensionGrip(mouseScreen.x, mouseScreen.y);
                if (angleGripHit) {
                    saveState();
                    selectedHatch = null;
                    selectedEntity = angleGripHit.entity;
                    selectedEntities = new Set([angleGripHit.entity]);
                    selectedSegmentIndex = null;
                    activeGrip = {
                        ...angleGripHit,
                        startWorld: { ...mouseWorld },
                        initialState: JSON.parse(JSON.stringify(angleGripHit.entity))
                    };
                    statusMode.innerText = `GRIP: ${angleGripHit.type.toUpperCase()}`;
                    updatePropertiesPalette();
                    render();
                    return;
                }

                const hit = hitTestEntity(mouseWorld, mouseScreen);
                if (hit) {
                    if (hit.hatch) {
                        selectedHatch = hit.hatch;
                        selectedEntity = hit.entity;
                        selectedEntities.clear();
                        selectedSegmentIndex = null;
                        activeMove = null;
                        updatePropertiesPalette();
                        render();
                        return;
                    }
                    selectedHatch = null;
                    if (e.shiftKey) {
                        if (selectedEntities.has(hit.entity)) {
                            selectedEntities.delete(hit.entity);
                        } else {
                            selectedEntities.add(hit.entity);
                        }
                    } else {
                        selectedEntities = new Set([hit.entity]);
                    }
                    selectedEntity = selectedEntities.has(hit.entity)
                        ? hit.entity
                        : (selectedEntities.values().next().value || null);
                    selectedSegmentIndex = hit.segmentIndex;
                    if (selectedEntity && selectedEntities.has(hit.entity)) {
                        activeMove = {
                            startWorld: mouseWorld,
                            startScreen: mouseScreen,
                            initialStates: new Map([...selectedEntities].map(entity => [
                                entity,
                                JSON.parse(JSON.stringify(entity))
                            ])),
                            changed: false,
                            saved: false
                        };
                    }
                } else {
                    selectedHatch = null;
                    selectionBoxStart = mouseWorld;
                    selectionBoxCurrent = mouseWorld;
                    isSelectingBox = true;
                    selectionBoxMoved = false;
                    if (!e.shiftKey) {
                        selectedEntities.clear();
                        selectedEntity = null;
                    }
                    selectedSegmentIndex = null;
                }

                updatePropertiesPalette();
                render();
                return;
            }

            const effectiveCoords = activeSnap ? { x: activeSnap.worldX, y: activeSnap.worldY } : currentMouse;

            if (currentTool === 'point') {
                saveState();
                const newPoint = {
                    type: 'point',
                    x: effectiveCoords.x,
                    y: effectiveCoords.y,
                    z: 0,
                    showText: true,
                    color: document.getElementById('strokeColor').value,
                    width: parseInt(document.getElementById('lineWidth').value)
                };
                entities.push(newPoint);
                switchToSelectMode(newPoint);
                return;
            }

            if (currentTool === 'pline') {
                if (!isDrawing) {
                    isDrawing = true;
                    plineVertices = [effectiveCoords];
                    showToast('Polyline: Click for vertices | Enter/Right-click to finish | "C" to close', 'info', 4000);
                } else {
                    const lastPt = plineVertices[plineVertices.length - 1];
                    const nextPt = applyOrtho(lastPt, effectiveCoords);
                    plineVertices.push(nextPt);
                }
                render();
                return;
            }

            if (currentTool === 'arc') {
                if (arcDrawingStep === 0) {
                    arcCenter = effectiveCoords;
                    arcDrawingStep = 1;
                    isDrawing = true;
                    showToast('Arc: Click the start point to set the radius.', 'info', 3000);
                } else if (arcDrawingStep === 1) {
                    arcStartPoint = effectiveCoords;
                    arcDrawingStep = 2;
                    showToast('Arc: Click the end point.', 'info', 3000);
                } else if (arcDrawingStep === 2) {
                    saveState();
                    const color = document.getElementById('strokeColor').value;
                    const width = parseInt(document.getElementById('lineWidth').value);
                    const r = Math.max(0.001, Math.hypot(arcStartPoint.x - arcCenter.x, arcStartPoint.y - arcCenter.y));
                    const startAzi = calculateAzimuthRad(arcStartPoint.x - arcCenter.x, arcStartPoint.y - arcCenter.y);
                    const endAzi = calculateAzimuthRad(effectiveCoords.x - arcCenter.x, effectiveCoords.y - arcCenter.y);

                    const newArc = {
                        type: 'arc',
                        cx: arcCenter.x,
                        cy: arcCenter.y,
                        r,
                        startAzi,
                        endAzi,
                        color,
                        width
                    };
                    entities.push(newArc);
                    switchToSelectMode(newArc);
                }
                render();
                return;
            }

            if (!isDrawing) {
                isDrawing = true;
                startPoint = effectiveCoords;
            } else {
                saveState();
                const color = document.getElementById('strokeColor').value;
                const width = parseInt(document.getElementById('lineWidth').value);
                const p2 = applyOrtho(startPoint, effectiveCoords);
                let newEntity = null;

                if (currentTool === 'line') {
                    newEntity = { type: 'line', x1: startPoint.x, y1: startPoint.y, x2: p2.x, y2: p2.y, color, width };
                } else if (currentTool === 'rect') {
                    newEntity = { type: 'rect', x: startPoint.x, y: startPoint.y, w: p2.x - startPoint.x, h: p2.y - startPoint.y, color, width };
                } else if (currentTool === 'circle') {
                    const r = Math.hypot(p2.x - startPoint.x, p2.y - startPoint.y);
                    newEntity = { type: 'circle', cx: startPoint.x, cy: startPoint.y, r, color, width };
                } else if (currentTool === 'ellipse') {
                    const rx = Math.max(0.001, Math.abs(p2.x - startPoint.x));
                    const ry = Math.max(0.001, Math.abs(p2.y - startPoint.y));
                    newEntity = { type: 'ellipse', cx: startPoint.x, cy: startPoint.y, rx, ry, color, width };
                }

                if (newEntity) {
                    entities.push(newEntity);
                    switchToSelectMode(newEntity);
                } else {
                    switchToSelectMode(null);
                }
            }
        }
    });

    window.addEventListener('mousemove', (e) => {
        if (isPanning) {
            camera.x = e.clientX - panStart.x;
            camera.y = e.clientY - panStart.y;
            persistCameraView();
            render();
            return;
        }

        const rect = canvas.getBoundingClientRect();
        const sx = e.clientX - rect.left;
        const sy = e.clientY - rect.top;

        if (northSymbolDrag) {
            northSymbolPosition.x = Math.max(0, Math.min(canvas.width - northSymbolSize, sx - northSymbolDrag.offsetX));
            northSymbolPosition.y = Math.max(0, Math.min(canvas.height - northSymbolSize, sy - northSymbolDrag.offsetY));
            localStorage.setItem('cad_north_x', String(northSymbolPosition.x));
            localStorage.setItem('cad_north_y', String(northSymbolPosition.y));
            canvas.style.cursor = 'move';
            render();
            return;
        }

        if (paperFrameDrag) {
            const currentWorld = screenToWorld(sx, sy);
            paperFrameCenter.x = currentWorld.x - paperFrameDrag.offsetX;
            paperFrameCenter.y = currentWorld.y - paperFrameDrag.offsetY;
            localStorage.setItem('cad_paper_frame_cx', String(paperFrameCenter.x));
            localStorage.setItem('cad_paper_frame_cy', String(paperFrameCenter.y));
            canvas.style.cursor = 'move';
            render();
            return;
        }

        if (imageCaptureSelection) {
            imageCaptureSelection.current = {
                x: Math.max(0, Math.min(canvas.width, sx)),
                y: Math.max(0, Math.min(canvas.height, sy))
            };
            render();
            return;
        }

        if (pastePreview) {
            pastePreview.target = screenToWorld(sx, sy);
            render();
            return;
        }

        if (moveCommand && moveCommand.basePoint) {
            moveCommand.targetPoint = getCommandPoint(sx, sy);
            currentMouse = moveCommand.targetPoint;
            statusCoords.innerText = `X: ${formatCoord(currentMouse.x)} | Y: ${formatCoord(currentMouse.y)}`;
            render();
            return;
        }
        if (scaleCommand && scaleCommand.basePoint && scaleCommand.referencePoint) {
            const currentPoint = getCommandPoint(sx, sy);
            const referenceDistance = Math.hypot(
                scaleCommand.referencePoint.x - scaleCommand.basePoint.x,
                scaleCommand.referencePoint.y - scaleCommand.basePoint.y
            );
            scaleCommand.factor = referenceDistance > 1e-9
                ? Math.max(0.001, Math.hypot(currentPoint.x - scaleCommand.basePoint.x, currentPoint.y - scaleCommand.basePoint.y) / referenceDistance)
                : null;
            currentMouse = currentPoint;
            statusCoords.innerText = `X: ${formatCoord(currentMouse.x)} | Y: ${formatCoord(currentMouse.y)}`;
            render();
            return;
        }

        if (isSelectingBox) {
            selectionBoxCurrent = screenToWorld(sx, sy);
            selectionBoxMoved = Math.hypot(
                selectionBoxCurrent.x - selectionBoxStart.x,
                selectionBoxCurrent.y - selectionBoxStart.y
            ) * camera.zoom >= 4;
            render();
            return;
        }

        if (activeMove) {
            const dragDistance = Math.hypot(sx - activeMove.startScreen.x, sy - activeMove.startScreen.y);
            if (dragDistance < MOVE_DRAG_THRESHOLD_PX) return;
            const moveSnap = findBestSnap(sx, sy, activeMove.initialStates, {});
            const currentWorld = moveSnap ? { x: moveSnap.worldX, y: moveSnap.worldY } : screenToWorld(sx, sy);
            applyObjectMove(activeMove, currentWorld);
            if (activeMove.changed && !activeMove.saved) {
                saveState();
                activeMove.saved = true;
            }
            render();
            return;
        }

        const northHit = hitTestNorthSymbol(sx, sy);
        if (!activeGrip && currentTool === 'select') {
            hoveredGrip = selectedEntity ? hitTestGrip(sx, sy, selectedEntity) : hitTestAngleDimensionGrip(sx, sy);
            canvas.style.cursor = northHit.onBorder ? 'move' : (hoveredGrip ? 'pointer' : 'default');
        } else if (currentTool !== 'select') {
            canvas.style.cursor = northHit.onBorder ? 'move' : 'crosshair';
        }

        // During drawing/snapping, don't exclude any entities - we want snap available to ALL existing objects
        let exclude = null;
        if (activeGrip) {
            exclude = activeGrip.entity;
        }
        if (activeGrip && activeGrip.entity.type === 'dimension' && activeGrip.entity.kind === 'angle') {
            activeSnap = findBestSnap(sx, sy, exclude, { discreteOnly: true });
        } else {
            activeSnap = findBestSnap(sx, sy, exclude, {});
        }

        if (activeSnap) {
            currentMouse = { x: activeSnap.worldX, y: activeSnap.worldY };
        } else {
            const raw = screenToWorld(sx, sy);
            if (document.getElementById('snapGrid').checked) {
                currentMouse = { x: Math.round(raw.x / 10) * 10, y: Math.round(raw.y / 10) * 10 };
            } else {
                currentMouse = raw;
            }
        }

        statusCoords.innerText = `X: ${formatCoord(currentMouse.x)} | Y: ${formatCoord(currentMouse.y)}`;

        const refPt = currentTool === 'pline' && plineVertices.length > 0 ? plineVertices[plineVertices.length - 1] : startPoint;
        if (isDrawing && refPt) {
            const p2 = applyOrtho(refPt, currentMouse);
            const dx = p2.x - refPt.x;
            const dy = p2.y - refPt.y;
            const azi = formatAzimuth(dx, dy);
            const dist = Math.hypot(dx, dy);
            statusAngle.innerText = `S: ${formatCoord(dist)} | AZI: ${azi.val}${azi.unit}`;
        } else {
            const azi = formatAzimuth(currentMouse.x, currentMouse.y);
            statusAngle.innerText = `AZI: ${azi.val}${azi.unit}`;
        }

        if (activeGrip) {
            const isAngleGrip = activeGrip.entity && activeGrip.entity.type === 'dimension' && activeGrip.entity.kind === 'angle';
            const targetPt = isAngleGrip ? currentMouse : applyOrtho(activeGrip.startWorld, currentMouse);
            applyGripModification(activeGrip, targetPt);
        }

        if (isDrawing || dimensionCommand || activeSnap || activeGrip || hoveredGrip || isSelectingBox) render();
    });

    window.addEventListener('mouseup', (e) => {
        if (imageCaptureSelection && e.button === 0) {
            const rect = canvas.getBoundingClientRect();
            const selection = imageCaptureSelection;
            selection.current = {
                x: Math.max(0, Math.min(canvas.width, e.clientX - rect.left)),
                y: Math.max(0, Math.min(canvas.height, e.clientY - rect.top))
            };
            imageCaptureSelection = null;
            isImageCaptureMode = false;
            canvas.style.cursor = 'default';
            statusMode.innerText = imageCapturePreviousStatus;
            render();
            copyCanvasRegionAsJpg(selection.start, selection.current);
            return;
        }
        if (northSymbolDrag) {
            northSymbolDrag = null;
            localStorage.setItem('cad_north_x', String(northSymbolPosition.x));
            localStorage.setItem('cad_north_y', String(northSymbolPosition.y));
            canvas.style.cursor = 'default';
            render();
            return;
        }
        if (paperFrameDrag) {
            paperFrameDrag = null;
            localStorage.setItem('cad_paper_frame_cx', String(paperFrameCenter.x));
            localStorage.setItem('cad_paper_frame_cy', String(paperFrameCenter.y));
            canvas.style.cursor = 'default';
            render();
            return;
        }
        if (isPanning) {
            isPanning = false;
            persistCameraView();
        }
        if (isSelectingBox) {
            const rect = canvas.getBoundingClientRect();
            selectionBoxCurrent = screenToWorld(e.clientX - rect.left, e.clientY - rect.top);
            const moved = Math.hypot(
                selectionBoxCurrent.x - selectionBoxStart.x,
                selectionBoxCurrent.y - selectionBoxStart.y
            ) * camera.zoom >= 4 || selectionBoxMoved;
            if (moved) {
                lastSelectionBox = {
                    start: { ...selectionBoxStart },
                    end: { ...selectionBoxCurrent }
                };
                const boxStart = selectionBoxStart;
                const boxEnd = selectionBoxCurrent;
                const boxMatches = entities.filter(entity => isEntityInSelectionBox(entity, boxStart, boxEnd));
                if (e.shiftKey) {
                    boxMatches.forEach(entity => selectedEntities.add(entity));
                } else {
                    selectedEntities = new Set(boxMatches);
                }
                selectedEntity = boxMatches[boxMatches.length - 1] || selectedEntities.values().next().value || null;
            }
            selectedSegmentIndex = null;
            isSelectingBox = false;
            selectionBoxStart = null;
            selectionBoxCurrent = null;
            selectionBoxMoved = false;
            updatePropertiesPalette();
            render();
            return;
        }
        if (activeMove) {
            const moveChanged = activeMove.changed;
            activeMove = null;
            if (moveChanged) {
                triggerAutoSave();
                showToast('Object moved.', 'success', 1200);
            }
            render();
            return;
        }
        if (activeGrip) {
            activeGrip = null;
            statusMode.innerText = 'MODE: SELECT';
            triggerAutoSave();
            render();
            showToast('Entity geometry updated.', 'success', 1500);
        }
    });

    canvas.addEventListener('dblclick', (e) => {
        if (e.button === 1 || e.which === 2) {
            e.preventDefault();
            zoomToExtents();
        }
    });

    canvas.addEventListener('wheel', (e) => {
        e.preventDefault();
        const zoomFactor = e.deltaY < 0 ? 1.30 : 0.70;
        const rect = canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        const worldBefore = screenToWorld(mouseX, mouseY);
        camera.zoom = Math.max(0.05, Math.min(camera.zoom * zoomFactor, MAX_ZOOM));
        camera.x = mouseX - canvas.width / 2 - worldBefore.x * camera.zoom;
        camera.y = mouseY - canvas.height / 2 + worldBefore.y * camera.zoom;
        if (isPanning) {
            panStart = { x: e.clientX - camera.x, y: e.clientY - camera.y };
        }

        persistCameraView();
        statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
        render();
    }, { passive: false });

    angleUnitsSelect.addEventListener('change', () => {
        const val = angleUnitsSelect.value;
        localStorage.setItem('cad_angle_unit', val);
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        const unitLabel = val === 'grad' ? 'Grads (400g)' : (val === 'rad' ? 'Radians (2π)' : 'Degrees (360°)');
        showToast(`Angle unit: ${unitLabel}`, 'info');
    });

    paperSizeSelect.addEventListener('change', () => {
        localStorage.setItem('cad_paper_size', paperSizeSelect.value);
        render();
    });

    lineWidthSelect.addEventListener('change', () => {
        triggerAutoSave();
    });

    printScaleSelect.addEventListener('change', () => {
        localStorage.setItem('cad_print_scale', printScaleSelect.value);
        render();
    });

    // Copy a user-dragged canvas crop as JPEG, with a download fallback when clipboard access is blocked.
    function copyCanvasRegionAsJpg(start, end) {
        const sourceX = Math.max(0, Math.min(start.x, end.x));
        const sourceY = Math.max(0, Math.min(start.y, end.y));
        const sourceRight = Math.min(canvas.width, Math.max(start.x, end.x));
        const sourceBottom = Math.min(canvas.height, Math.max(start.y, end.y));
        const width = Math.floor(sourceRight - sourceX);
        const height = Math.floor(sourceBottom - sourceY);
        if (width < 2 || height < 2) {
            showToast('The capture area is too small.', 'warning', 1800);
            return;
        }

        const exportCanvas = document.createElement('canvas');
        exportCanvas.width = width;
        exportCanvas.height = height;
        const exportContext = exportCanvas.getContext('2d');
        exportContext.fillStyle = '#121212';
        exportContext.fillRect(0, 0, width, height);
        exportContext.drawImage(canvas, sourceX, sourceY, width, height, 0, 0, width, height);
        let dataUrl;
        try {
            dataUrl = exportCanvas.toDataURL('image/jpeg', 0.92);
        } catch (error) {
            showToast('Could not create JPG image.', 'error', 1800);
            return;
        }

        const base64 = dataUrl.split(',')[1];
        const bytes = Uint8Array.from(atob(base64), character => character.charCodeAt(0));
        const blob = new Blob([bytes], { type: 'image/jpeg' });
        const downloadFallback = () => {
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'cad-selection.jpg';
            link.click();
            URL.revokeObjectURL(link.href);
            showToast('Clipboard denied. JPG downloaded instead.', 'warning', 2500);
        };

        if (!navigator.clipboard || typeof ClipboardItem === 'undefined' || !window.isSecureContext) {
            downloadFallback();
            return;
        }
        navigator.clipboard.write([new ClipboardItem({ 'image/jpeg': blob })])
            .then(() => showToast('Selection copied to clipboard as JPG.', 'success', 1800))
            .catch(downloadFallback);
    }

    function startImageCapture() {
        imageCaptureSelection = null;
        isImageCaptureMode = true;
        imageCapturePreviousStatus = statusMode.innerText;
        statusMode.innerText = 'JPG CAPTURE: DRAG AREA';
        canvas.style.cursor = 'crosshair';
        showToast('Drag over the area to copy as JPG. Press Escape to cancel.', 'info', 2500);
        render();
    }

    document.getElementById('btn-angle-dimension').addEventListener('click', startAngleDimensionCommand);
    document.getElementById('btn-copy-jpg').addEventListener('click', startImageCapture);
    document.getElementById('btn-insert-board').addEventListener('click', startBoardInsertMode);
    document.getElementById('tool-text').addEventListener('click', startTextInsertMode);

    // DXF Export Button Event (Full Payload with Units)
    document.getElementById('btn-export-dxf').addEventListener('click', () => {
        if (!entities || entities.length === 0) {
            showToast('There are no entities to export.', 'warning');
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'cad.php';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'export_dxf';
        form.appendChild(actionInput);

        const fileInput = document.createElement('input');
        fileInput.type = 'hidden';
        fileInput.name = 'file';
        fileInput.value = drawingFileName.value.trim();
        form.appendChild(fileInput);

        const dataInput = document.createElement('input');
        dataInput.type = 'hidden';
        dataInput.name = 'data';
        dataInput.value = JSON.stringify({ 
            entities: entities,
            angleUnit: angleUnitsSelect.value,
            printScale: Number(printScaleSelect.value),
            paperSize: paperSizeSelect.value,
            paperFrameCenterX: paperFrameCenter.x,
            paperFrameCenterY: paperFrameCenter.y
        });
        const scaleInput = document.createElement('input');
        scaleInput.type = 'hidden';
        scaleInput.name = 'printScale';
        scaleInput.value = printScaleSelect.value;
        form.appendChild(scaleInput);
        form.appendChild(dataInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

        showToast('AutoCAD 2007 DXF file created.', 'success');
    });

    window.addEventListener('keydown', (e) => {
        const editingText = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
        if ((e.key === 'h' || e.key === 'H') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startHatchCommand();
            return;
        }
        if ((e.key === 'd' || e.key === 'D') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startDimensionCommand();
            return;
        }
        if ((e.key === 'a' || e.key === 'A') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startAngleDimensionCommand();
            return;
        }
        if ((e.key === 'o' || e.key === 'O') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startOffsetCommand();
            return;
        }
        if ((e.key === 't' || e.key === 'T') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startTrimCommand();
            return;
        }
        if ((e.key === 'm' || e.key === 'M') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startMoveCommand();
            return;
        }
        if ((e.key === 's' || e.key === 'S') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startScaleCommand();
            return;
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A') && !editingText && currentTool === 'select') {
            e.preventDefault();
            selectedEntities = new Set(entities);
            selectedEntity = entities[entities.length - 1] || null;
            selectedSegmentIndex = null;
            updatePropertiesPalette();
            render();
            showToast(`${selectedEntities.size} object(s) selected.`, 'info', 1500);
            return;
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'c' || e.key === 'C') && !editingText) {
            if (selectedEntities.size) {
                clipboardEntities = JSON.parse(JSON.stringify([...selectedEntities]));
                e.preventDefault();
                showToast(`${clipboardEntities.length} object(s) copied.`, 'info', 1500);
            }
            return;
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'v' || e.key === 'V') && !editingText) {
            if (clipboardEntities.length) {
                e.preventDefault();
                pastePreview = {
                    source: JSON.parse(JSON.stringify(clipboardEntities)),
                    anchor: getEntitiesCenter(clipboardEntities),
                    target: { x: currentMouse.x, y: currentMouse.y }
                };
                render();
                showToast('Paste preview active. Click to place or press Escape to cancel.', 'info', 2500);
            }
            return;
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) {
            e.preventDefault();
            executeUndo();
            return;
        }

        if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y' || ((e.key === 'z' || e.key === 'Z') && e.shiftKey))) {
            e.preventDefault();
            executeRedo();
            return;
        }

        if (e.key === 'F3') {
            e.preventDefault();
            const chk = document.getElementById('osnapToggle');
            chk.checked = !chk.checked;
            document.getElementById('status-osnap').innerText = `OSNAP: ${chk.checked ? 'ON' : 'OFF'}`;
            updatePropertiesPalette();
            showToast(`OSNAP: ${chk.checked ? 'ON' : 'OFF'}`, chk.checked ? 'success' : 'warning', 1800);
        } else if (e.key === 'F8') {
            e.preventDefault();
            const chk = document.getElementById('orthoToggle');
            chk.checked = !chk.checked;
            document.getElementById('status-ortho').innerText = `ORTHO: ${chk.checked ? 'ON' : 'OFF'}`;
            showToast(`ORTHO: ${chk.checked ? 'ON' : 'OFF'}`, chk.checked ? 'success' : 'warning', 1800);
        } else if (e.key === 'Enter') {
            if (currentTool === 'pline' && isDrawing) {
                finishPline(false);
            }
        } else if (e.key === 'c' || e.key === 'C') {
            if (currentTool === 'pline' && isDrawing && plineVertices.length >= 2) {
                finishPline(true);
            }
        } else if (e.key === 'Escape') {
            if (isImageCaptureMode) {
                imageCaptureSelection = null;
                isImageCaptureMode = false;
                canvas.style.cursor = 'default';
                statusMode.innerText = imageCapturePreviousStatus;
                render();
                showToast('JPG capture cancelled.', 'info', 1200);
                return;
            }
            if (hatchCommand) {
                hatchCommand = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                render();
                showToast('Hatch cancelled.', 'info', 1200);
                return;
            }
            if (angleDimensionCommand) {
                angleDimensionCommand = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                render();
                showToast('Angle dimension cancelled.', 'info', 1200);
                return;
            }
            if (dimensionCommand) {
                dimensionCommand = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                render();
                showToast('Dimension cancelled.', 'info', 1200);
                return;
            }
            if (offsetCommand) {
                offsetCommand = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                render();
                showToast('Offset cancelled.', 'info', 1200);
                return;
            }
            if (trimCommand) {
                trimCommand = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                render();
                showToast('Trim cancelled.', 'info', 1200);
                return;
            }
            if (moveCommand) {
                moveCommand = null;
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                render();
                showToast('Move cancelled.', 'info', 1200);
                return;
            }
            if (pastePreview) {
                pastePreview = null;
                render();
                showToast('Paste cancelled.', 'info', 1200);
                return;
            }
            if (currentTool === 'board') {
                currentTool = 'select';
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                canvas.style.cursor = 'default';
                render();
                showToast('Board insertion cancelled.', 'info', 1200);
                return;
            }
            if (currentTool === 'text') {
                currentTool = 'select';
                setActiveToolbarButton('tool-select');
                statusMode.innerText = 'MODE: SELECT';
                canvas.style.cursor = 'default';
                render();
                showToast('Text insertion cancelled.', 'info', 1200);
                return;
            }
            if (activeGrip) {
                Object.assign(activeGrip.entity, activeGrip.initialState);
                activeGrip = null;
                statusMode.innerText = 'MODE: SELECT';
            }
            isDrawing = false;
            startPoint = null;
            arcCenter = null;
            arcStartPoint = null;
            arcDrawingStep = 0;
            plineVertices = [];
            selectedEntity = null;
            selectedEntities.clear();
            selectedSegmentIndex = null;
            selectedVertexIndex = 0;
            switchToSelectMode(null);
            showToast('Action cancelled / Selection cleared.', 'info', 1500);
        } else if (e.key === 'Delete' || e.key === 'Backspace') {
            if (selectedHatch && !editingText) {
                saveState();
                selectedHatch.hatch = null;
                selectedHatch = null;
                selectedEntity = null;
                selectedEntities.clear();
                updatePropertiesPalette();
                render();
                showToast('Hatch deleted.', 'warning', 1500);
                return;
            }
            if (selectedEntities.size && !editingText) {
                saveState();
                entities = entities.filter(ent => !selectedEntities.has(ent));
                const deletedCount = selectedEntities.size;
                selectedEntity = null;
                selectedEntities.clear();
                selectedSegmentIndex = null;
                selectedVertexIndex = 0;
                updatePropertiesPalette();
                render();
                showToast(`${deletedCount} object(s) deleted.`, 'warning');
            }
        }
    });

    document.querySelectorAll('.tool-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!btn.dataset.tool || btn.id === 'btn-insert-board') return;
            setActiveToolbarButton(btn.id);
            currentTool = btn.dataset.tool;
            isDrawing = false;
            startPoint = null;
            arcCenter = null;
            arcStartPoint = null;
            arcDrawingStep = 0;
            plineVertices = [];
            activeGrip = null;
            statusMode.innerText = `MODE: ${currentTool.toUpperCase()}`;
            render();
            showToast(`Tool: ${btn.innerText}`, 'info', 1500);
        });
    });

    const pointImportModal = document.getElementById('point-import-modal');
    const pointImportInput = document.getElementById('point-import-input');
    const pointImportHasLabels = document.getElementById('point-import-has-labels');
    const contourInterval = document.getElementById('contour-interval');
    const pointImportFields = document.getElementById('point-import-fields');
    const closePointImport = () => {
        pointImportModal.classList.remove('open');
        pointImportInput.value = '';
    };

    document.getElementById('btn-generate-contours').addEventListener('click', () => {
        pointImportModal.classList.add('open');
        contourInterval.focus();
    });
    document.querySelectorAll('input[name="contour-point-source"]').forEach(input => {
        input.addEventListener('change', () => {
            const importingPoints = input.value === 'new' && input.checked;
            pointImportFields.hidden = !importingPoints;
            if (importingPoints) pointImportInput.focus();
        });
    });
    document.getElementById('btn-cancel-point-import').addEventListener('click', closePointImport);
    pointImportModal.addEventListener('click', (event) => {
        if (event.target === pointImportModal) closePointImport();
    });
    document.getElementById('btn-apply-point-import').addEventListener('click', () => {
        const interval = parseStrictFloat(contourInterval.value, NaN);
        if (!Number.isFinite(interval) || interval <= 0) {
            showToast('Enter a contour interval greater than zero.', 'warning', 2200);
            contourInterval.focus();
            return;
        }
        const importingPoints = document.querySelector('input[name="contour-point-source"]:checked').value === 'new';
        if (!importingPoints) {
            closePointImport();
            generateContours(interval);
            return;
        }
        const lines = pointImportInput.value.split(/\r?\n/);
        const importedPoints = [];
        const invalidLines = [];
        const color = document.getElementById('strokeColor').value;
        const width = parseInt(document.getElementById('lineWidth').value);
        const hasLabels = pointImportHasLabels.checked;

        lines.forEach((line, index) => {
            if (!line.trim()) return;
            const values = line.trim().split(/[,\t]+/).map(value => value.trim());
            const expectedLengths = hasLabels ? [3, 4] : [2, 3];
            if (!expectedLengths.includes(values.length) || (hasLabels && !values[0])) {
                invalidLines.push(index + 1);
                return;
            }
            const label = hasLabels ? values[0] : '';
            const coordinates = values.slice(hasLabels ? 1 : 0).map(value => parseStrictFloat(value, NaN));
            if (coordinates.some(value => !Number.isFinite(value))) {
                invalidLines.push(index + 1);
                return;
            }
            importedPoints.push({
                type: 'point',
                x: coordinates[0],
                y: coordinates[1],
                z: coordinates[2] ?? 0,
                name: label,
                color,
                width
            });
        });

        if (invalidLines.length) {
            showToast(`Invalid point rows: ${invalidLines.join(', ')}`, 'error', 3500);
        }
        if (!importedPoints.length) return;

        saveState();
        entities.push(...importedPoints);
        closePointImport();
        selectedEntities = new Set(importedPoints);
        selectedEntity = importedPoints[importedPoints.length - 1];
        selectedSegmentIndex = null;
        updatePropertiesPalette();
        render();
        showToast(`${importedPoints.length} point(s) added.`, 'success', 2000);
        generateContours(interval);
    });

    document.getElementById('btn-move').addEventListener('click', startMoveCommand);
    document.getElementById('btn-scale').addEventListener('click', startScaleCommand);
    document.getElementById('btn-offset').addEventListener('click', startOffsetCommand);
        document.getElementById('btn-dimension').addEventListener('click', startDimensionCommand);
        document.getElementById('btn-trim').addEventListener('click', startTrimCommand);
        document.getElementById('btn-hatch').addEventListener('click', startHatchCommand);

    saveButton.addEventListener('click', () => {
        clearTimeout(autoSaveTimer);
        saveDrawing(true)
            .then(() => showToast('Drawing saved.', 'success', 1500))
            .catch(error => showToast(error.message, 'error', 2500));
    });

    renameButton.addEventListener('click', () => {
        const newFileName = window.prompt('New drawing name:', drawingFileName.value);
        if (newFileName === null || !newFileName.trim()) return;
        const formData = new FormData();
        formData.append('action', 'rename');
        formData.append('file', drawingFileName.value.trim());
        formData.append('newFile', newFileName.trim());
        renameButton.disabled = true;
        fetch(apiEndpoint, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success') throw new Error(res.message || 'Rename failed.');
                drawingFileName.value = res.fileName;
                refreshDrawingList(res.fileName);
                showToast(`Drawing renamed to ${res.fileName}.`, 'success', 2000);
            })
            .catch(error => showToast(error.message, 'error', 2500))
            .finally(() => { renameButton.disabled = false; });
    });

    function applyLoadedDrawing(res, entitiesOnly = false, fitInitialView = false) {
        if (res.status === 'success' && res.data) {
            lastKnownRevision = res.revision || lastKnownRevision;
            lastKnownEntityRevision = res.entityRevision || lastKnownEntityRevision;
            localChangesPending = false;
            undoStack = [];
            redoStack = [];
            updateHistoryButtons();
            if (res.fileName) drawingFileName.value = res.fileName;
            if (Array.isArray(res.data)) {
                entities = normalizeTextEntities(res.data);
            } else {
                entities = normalizeTextEntities(res.data.entities || []);
                if (!entitiesOnly && res.data.angleUnit) {
                    angleUnitsSelect.value = res.data.angleUnit;
                    localStorage.setItem('cad_angle_unit', res.data.angleUnit);
                }
                if (!entitiesOnly && !hasSavedCameraView && Number.isFinite(Number(res.data.zoom))) {
                    camera.zoom = Math.max(0.05, Math.min(Number(res.data.zoom), MAX_ZOOM));
                    localStorage.setItem('cad_zoom', String(camera.zoom));
                    statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
                }
                if (!entitiesOnly && Number.isFinite(Number(res.data.propertiesWidth))) {
                    const propertiesWidth = Math.max(240, Math.min(600, Number(res.data.propertiesWidth)));
                    propertiesPalette.style.width = `${propertiesWidth}px`;
                    propertiesPalette.style.flexBasis = `${propertiesWidth}px`;
                }
                if (!entitiesOnly) resize();
                const hasSavedView = Number.isFinite(Number(res.data.viewCenterX)) && Number.isFinite(Number(res.data.viewCenterY));
                if (!entitiesOnly && !hasSavedCameraView && hasSavedView) {
                    let viewCenterX = Number(res.data.viewCenterX);
                    if (Number(res.data.viewCenterVersion) !== 2) {
                        viewCenterX -= canvas.width / (2 * camera.zoom);
                    }
                    camera.x = -viewCenterX * camera.zoom;
                    camera.y = Number(res.data.viewCenterY) * camera.zoom;
                }
                if (!hasSavedView && entities.length > 0) {
                    zoomToExtents();
                }
                if (!entitiesOnly && ['1', '2', '3', '4'].includes(String(res.data.lineWidth))) {
                    lineWidthSelect.value = String(res.data.lineWidth);
                }
            }
            selectedEntity = null;
            selectedEntities.clear();
            updatePropertiesPalette();
            resize();
            render();
        }
    }

    function loadDrawing(fileName, remoteRefresh = false, fitInitialView = false) {
        const formData = new FormData();
        formData.append('action', 'load');
        formData.append('file', fileName);
        fetch(apiEndpoint, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success') throw new Error(res.message || 'Load failed.');
                applyLoadedDrawing(res, remoteRefresh, fitInitialView);
                refreshDrawingList(res.fileName);
                updatePresence();
                if (!remoteRefresh) showToast(`Loaded ${res.fileName}.`, 'success', 1500);
            })
            .catch(error => showToast(error.message, 'error', 2500));
    }

    // Poll the shared drawing for external edits while no local edit is in progress.
    function checkForRemoteChanges() {
        if (localChangesPending || isDrawing || activeMove || activeGrip) return;
        const formData = new FormData();
        formData.append('action', 'check');
        formData.append('file', drawingFileName.value.trim());
        fetch(apiEndpoint, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success' || !res.entityRevision || res.entityRevision === lastKnownEntityRevision) return;
                loadDrawing(drawingFileName.value.trim(), true);
            })
            .catch(() => {});
    }

    function refreshDrawingList(selectedFile = drawingFileName.value) {
        const formData = new FormData();
        formData.append('action', 'list');
        fetch(apiEndpoint, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.status !== 'success') throw new Error('Could not list drawings.');
                drawingFileSelect.replaceChildren();
                res.files.forEach(fileName => {
                    const option = new Option(fileName, fileName);
                    option.selected = fileName === selectedFile;
                    drawingFileSelect.add(option);
                });
                if (!res.files.length) drawingFileSelect.add(new Option('No drawings found', ''));
            })
            .catch(() => {});
    }

    drawingFileSelect.addEventListener('change', () => {
        if (!drawingFileSelect.value) return;
        if (drawingFileSelect.value === drawingFileName.value || window.confirm('Load this drawing and discard unsaved changes?')) {
            loadDrawing(drawingFileSelect.value);
        }
    });

    statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
    document.getElementById('osnapToggle').addEventListener('change', event => {
        document.getElementById('status-osnap').innerText = `OSNAP: ${event.target.checked ? 'ON' : 'OFF'}`;
        updatePropertiesPalette();
    });
    resize();
    refreshDrawingList();
    loadDrawing(drawingFileName.value, false, false);
    updatePresence();
    window.setInterval(updatePresence, 5000);
    window.setInterval(checkForRemoteChanges, 3000);
})();
</script>
</body>
</html>
