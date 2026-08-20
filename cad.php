<?php
// cad.php - Complete Web CAD Engine with Strict AutoCAD 2007 (AC1021) Header & DimStyle Compliance
$dataFile = __DIR__ . '/cad_drawing.json';

// Helper: RGB Hex to AutoCAD Color Index (ACI)
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

// AutoCAD 2007 (AC1021) DXF Generator
function generateDXF2007($entities, $angleUnit = 'deg') {
    $dxf = [];
    $nl = "\r\n";

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
    $hNext        = 0x50;

    $aunits = $angleUnit === 'grad' ? 2 : ($angleUnit === 'rad' ? 3 : 0);

    // 1. HEADER SECTION (Correct System Variable Group Codes)
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}HEADER";
    $dxf[] = "9{$nl}\$ACADVER";
    $dxf[] = "1{$nl}AC1021"; // AutoCAD 2007
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
    $dxf[] = "70{$nl}3";

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

    $dxf[] = "0{$nl}ENDSEC";

    // 5. ENTITIES SECTION
    $dxf[] = "0{$nl}SECTION";
    $dxf[] = "2{$nl}ENTITIES";

    if (is_array($entities)) {
        foreach ($entities as $ent) {
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
                $dxf[] = "0{$nl}POINT";
                $dxf[] = "5{$nl}{$handle}";
                $dxf[] = "330{$nl}{$hModelBlockR}";
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbPoint";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$ent['x']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$ent['y']);
                $dxf[] = "30{$nl}" . sprintf('%.4f', (float)($ent['z'] ?? 0));
            }
        }
    }

    $gridBounds = getDXFGridBounds($entities);
    if ($gridBounds) {
        $gridStep = 50.0;
        $gridMinX = floor($gridBounds['minX'] / $gridStep) * $gridStep;
        $gridMaxX = ceil($gridBounds['maxX'] / $gridStep) * $gridStep;
        $gridMinY = floor($gridBounds['minY'] / $gridStep) * $gridStep;
        $gridMaxY = ceil($gridBounds['maxY'] / $gridStep) * $gridStep;
        $gridHandle = $hNext++;
        $gridColor = 8;
        $labelHeight = max(2.0, $gridStep * 0.16);
        $labelOffset = $labelHeight * 1.5;

        for ($x = $gridMinX; $x <= $gridMaxX; $x += $gridStep) {
            $dxf[] = "0{$nl}LINE";
            $dxf[] = "5{$nl}" . dechex($gridHandle++);
            $dxf[] = "330{$nl}{$hModelBlockR}";
            $dxf[] = "100{$nl}AcDbEntity";
            $dxf[] = "8{$nl}0";
            $dxf[] = "62{$nl}{$gridColor}";
            $dxf[] = "100{$nl}AcDbLine";
            $dxf[] = "10{$nl}" . sprintf('%.4f', $x);
            $dxf[] = "20{$nl}" . sprintf('%.4f', $gridMinY);
            $dxf[] = "30{$nl}0.0000";
            $dxf[] = "11{$nl}" . sprintf('%.4f', $x);
            $dxf[] = "21{$nl}" . sprintf('%.4f', $gridMaxY);
            $dxf[] = "31{$nl}0.0000";
        }

        for ($y = $gridMinY; $y <= $gridMaxY; $y += $gridStep) {
            $dxf[] = "0{$nl}LINE";
            $dxf[] = "5{$nl}" . dechex($gridHandle++);
            $dxf[] = "330{$nl}{$hModelBlockR}";
            $dxf[] = "100{$nl}AcDbEntity";
            $dxf[] = "8{$nl}0";
            $dxf[] = "62{$nl}{$gridColor}";
            $dxf[] = "100{$nl}AcDbLine";
            $dxf[] = "10{$nl}" . sprintf('%.4f', $gridMinX);
            $dxf[] = "20{$nl}" . sprintf('%.4f', $y);
            $dxf[] = "30{$nl}0.0000";
            $dxf[] = "11{$nl}" . sprintf('%.4f', $gridMaxX);
            $dxf[] = "21{$nl}" . sprintf('%.4f', $y);
            $dxf[] = "31{$nl}0.0000";
        }

        foreach (range($gridMinX, $gridMaxX, $gridStep) as $x) {
            $label = (string)(int)round($x);
            $labelWidth = max(1, strlen($label)) * $labelHeight * 0.6;
            $dxf[] = "0{$nl}TEXT";
            $dxf[] = "5{$nl}" . dechex($gridHandle++);
            $dxf[] = "330{$nl}{$hModelBlockR}";
            $dxf[] = "100{$nl}AcDbEntity";
            $dxf[] = "8{$nl}0";
            $dxf[] = "100{$nl}AcDbText";
            $dxf[] = "10{$nl}" . sprintf('%.4f', $x + ($labelHeight / 2));
            $dxf[] = "20{$nl}" . sprintf('%.4f', $gridMinY - $labelOffset - ($labelWidth / 2));
            $dxf[] = "30{$nl}0.0000";
            $dxf[] = "40{$nl}" . sprintf('%.4f', $labelHeight);
            $dxf[] = "1{$nl}{$label}";
            $dxf[] = "50{$nl}90.0";
            $dxf[] = "7{$nl}STANDARD";
            $dxf[] = "100{$nl}AcDbText";
        }

        foreach (range($gridMinY, $gridMaxY, $gridStep) as $y) {
            $label = (string)(int)round($y);
            $labelWidth = max(1, strlen($label)) * $labelHeight * 0.6;
            $dxf[] = "0{$nl}TEXT";
            $dxf[] = "5{$nl}" . dechex($gridHandle++);
            $dxf[] = "330{$nl}{$hModelBlockR}";
            $dxf[] = "100{$nl}AcDbEntity";
            $dxf[] = "8{$nl}0";
            $dxf[] = "100{$nl}AcDbText";
            $dxf[] = "10{$nl}" . sprintf('%.4f', $gridMinX - $labelOffset - $labelWidth);
            $dxf[] = "20{$nl}" . sprintf('%.4f', $y - ($labelHeight / 2));
            $dxf[] = "30{$nl}0.0000";
            $dxf[] = "40{$nl}" . sprintf('%.4f', $labelHeight);
            $dxf[] = "1{$nl}{$label}";
            $dxf[] = "7{$nl}STANDARD";
            $dxf[] = "100{$nl}AcDbText";
        }
    }

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
    $dxf[] = "4{$nl}ISO_A4_(210.00_x_297.00_MM)";
    $dxf[] = "6{$nl}";
    $dxf[] = "40{$nl}5.7";
    $dxf[] = "41{$nl}5.7";
    $dxf[] = "42{$nl}5.7";
    $dxf[] = "43{$nl}5.7";
    $dxf[] = "44{$nl}210.0";
    $dxf[] = "45{$nl}297.0";
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
    $dxf[] = "11{$nl}297.0";
    $dxf[] = "21{$nl}210.0";
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

    $dxf[] = "0{$nl}ENDSEC";
    $dxf[] = "0{$nl}EOF";

    return implode($nl, $dxf);
}

// Backend Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        header('Content-Type: application/json; charset=utf-8');
        $jsonContent = $_POST['data'] ?? '{}';
        // Decode and re-encode with formatting
        $decoded = json_decode($jsonContent, true);
        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($dataFile, $formatted)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to write file.']);
        }
        exit;
    }

    if ($action === 'load') {
        header('Content-Type: application/json; charset=utf-8');
        if (file_exists($dataFile)) {
            echo json_encode(['status' => 'success', 'data' => json_decode(file_get_contents($dataFile), true)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No saved drawing found.']);
        }
        exit;
    }

    if ($action === 'export_dxf') {
        $raw = $_POST['data'] ?? '';
        $parsed = json_decode($raw, true);

        $entities = [];
        $angleUnit = 'deg';
        if (is_array($parsed)) {
            if (isset($parsed['entities']) && is_array($parsed['entities'])) {
                $entities = $parsed['entities'];
                $angleUnit = $parsed['angleUnit'] ?? 'deg';
            } elseif (isset($parsed[0]) && is_array($parsed[0])) {
                $entities = $parsed;
            }
        }

        $dxfContent = generateDXF2007($entities, $angleUnit);

        header('Content-Type: application/dxf');
        header('Content-Disposition: attachment; filename="drawing_2007.dxf"');
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
            height: 48px;
            background: var(--bg-toolbar);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            padding: 0 12px;
            gap: 8px;
            z-index: 10;
        }
        .btn-group { display: flex; gap: 4px; border-right: 1px solid var(--border-color); padding-right: 8px; align-items: center; }
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
        }
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
        .point-import-actions { display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px; }
    </style>
</head>
<body>

<div id="toolbar">
    <div class="btn-group">
        <button id="tool-select" class="tool-btn icon-btn active" data-tool="select" title="Select"><svg viewBox="0 0 24 24"><path d="M5 3l4 14 3-4 4 5 2-2-4-5 5-1z"/></svg><span class="sr-only">Select</span></button>
        <button id="tool-line" class="tool-btn icon-btn" data-tool="line" title="Line"><svg viewBox="0 0 24 24"><path d="M5 19L19 5"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="5" r="2"/></svg><span class="sr-only">Line</span></button>
        <button id="tool-pline" class="tool-btn icon-btn" data-tool="pline" title="Polyline (PL)"><svg viewBox="0 0 24 24"><path d="M4 18l5-7 5 3 6-8"/><circle cx="4" cy="18" r="1.5"/><circle cx="9" cy="11" r="1.5"/><circle cx="14" cy="14" r="1.5"/><circle cx="20" cy="6" r="1.5"/></svg><span class="sr-only">Polyline</span></button>
        <button id="tool-rect" class="tool-btn icon-btn" data-tool="rect" title="Rectangle"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14"/><path d="M4 5h3M4 5v3M20 19h-3M20 19v-3"/></svg><span class="sr-only">Rectangle</span></button>
        <button id="tool-circle" class="tool-btn icon-btn" data-tool="circle" title="Circle"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="7"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg><span class="sr-only">Circle</span></button>
        <button id="tool-arc" class="tool-btn icon-btn" data-tool="arc" title="Arc"><svg viewBox="0 0 24 24"><path d="M4 17a9 9 0 0 1 13-10"/><path d="M4 17l-1-5M4 17l5-1"/><circle cx="4" cy="17" r="1.5"/></svg><span class="sr-only">Arc</span></button>
        <button id="tool-ellipse" class="tool-btn icon-btn" data-tool="ellipse" title="Ellipse"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="12" rx="8" ry="5"/><path d="M4 12h16M12 7v10"/></svg><span class="sr-only">Ellipse</span></button>
        <button id="tool-point" class="tool-btn icon-btn" data-tool="point" title="Point"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="2.5" fill="currentColor"/><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/></svg><span class="sr-only">Point</span></button>
        <button id="btn-import-points" class="icon-btn" title="Import Points"><svg viewBox="0 0 24 24"><path d="M12 4v11M8 11l4 4 4-4"/><path d="M5 19h14"/><path d="M4 7V4h5M20 7V4h-5"/></svg><span class="sr-only">Import Points</span></button>
        <button id="btn-generate-contours" class="icon-btn" title="Generate 1 m Contours"><svg viewBox="0 0 24 24"><path d="M4 7c3-3 6 3 9 0s6 3 7 0M4 12c3-3 6 3 9 0s6 3 7 0M4 17c3-3 6 3 9 0s6 3 7 0"/></svg><span class="sr-only">Generate 1 m Contours</span></button>
        <button id="btn-move" class="icon-btn" title="Move selected objects (M)"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"/><path d="M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3"/></svg><span class="sr-only">Move</span></button>
    </div>

    <div class="btn-group">
        <select id="angleUnits" title="Angle Measurement Unit (Saved)">
            <option value="deg">Degrees (°)</option>
            <option value="grad">Grads (g)</option>
            <option value="rad">Radians (rad)</option>
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
        <button id="btn-export-dxf" class="icon-btn" title="Export to DXF (2007)" style="background: #e65100; border-color: #f57c00; font-weight: 600;"><svg viewBox="0 0 24 24"><path d="M12 3v12M8 11l4 4 4-4"/><path d="M5 19h14"/><path d="M5 7V4h14v3"/></svg><span class="sr-only">Export to DXF (2007)</span></button>
        <span id="save-indicator" style="font-size: 11px; color: #4ec9b0; margin-left: 6px;">● Auto-saved</span>
    </div>
</div>

<div id="main-container">
    <div id="viewport">
        <canvas id="cadCanvas"></canvas>
    </div>

    <div id="properties-resizer" title="Resize properties panel"></div>
    <div id="properties-palette">
        <div class="panel-header">
            <span>PROPERTIES</span>
            <span id="prop-entity-count" style="color: var(--text-muted);">No selection</span>
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
</div>

<div id="toast-container"></div>

<div id="point-import-modal" role="dialog" aria-modal="true" aria-labelledby="point-import-title">
    <div class="point-import-panel">
        <h3 id="point-import-title">Import Points</h3>
        <textarea id="point-import-input" placeholder="One point per line: X,Y or X,Y,Z&#10;Tabs are also accepted"></textarea>
        <div class="point-import-actions">
            <button id="btn-cancel-point-import">Cancel</button>
            <button id="btn-apply-point-import" class="active">Add Points</button>
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
    const lineWidthSelect = document.getElementById('lineWidth');
    const toastContainer = document.getElementById('toast-container');
    const saveIndicator = document.getElementById('save-indicator');
    const apiEndpoint = window.location.pathname;
    const propertiesResizer = document.getElementById('properties-resizer');
    const propertiesPalette = document.getElementById('properties-palette');

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

    function formatCoord(val) {
        return parseStrictFloat(val).toFixed(3);
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

    // State & History
    let entities = [];
    let selectedEntity = null;
    let selectedEntities = new Set();
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
    let clipboardEntities = [];
    let activeMove = null;
    let moveCommand = null;
    let lastMiddleClickTime = 0;
    let pastePreview = null;

    let undoStack = [];
    let redoStack = [];
    const MAX_HISTORY = 50;

    let autoSaveTimer = null;
    function triggerAutoSave() {
        if (saveIndicator) saveIndicator.innerText = '● Saving...';
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(() => {
            const payload = {
                angleUnit: angleUnitsSelect.value,
                zoom: camera.zoom,
                lineWidth: lineWidthSelect.value,
                propertiesWidth: propertiesPalette.getBoundingClientRect().width,
                viewCenterVersion: 2,
                viewCenterX: -camera.x / camera.zoom,
                viewCenterY: camera.y / camera.zoom,
                entities: entities
            };
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('data', JSON.stringify(payload));

            fetch(apiEndpoint, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    if (res.status === 'success' && saveIndicator) {
                        saveIndicator.innerText = '● Auto-saved';
                    }
                })
                .catch(() => {
                    if (saveIndicator) saveIndicator.innerText = '● Auto-save offline';
                });
        }, 400);
    }

    function saveState() {
        undoStack.push(JSON.stringify(entities));
        if (undoStack.length > MAX_HISTORY) undoStack.shift();
        redoStack = [];
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

    function switchToSelectMode(entityToSelect = null) {
        document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
        const selectBtn = document.getElementById('tool-select');
        if (selectBtn) selectBtn.classList.add('active');
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
    let camera = {
        x: 0,
        y: 0,
        zoom: Number.isFinite(savedZoom) ? Math.max(0.05, Math.min(savedZoom, 100)) : 1
    };
    const GRID_SIZE = 50;
    const SNAP_TOLERANCE_PX = 14;
    const SELECT_TOLERANCE_PX = 8;
    const GRIP_HIT_RADIUS_PX = 8;

    function getGridSize() {
        const rawSize = GRID_SIZE / camera.zoom;
        const magnitude = Math.pow(10, Math.floor(Math.log10(rawSize)));
        const normalized = rawSize / magnitude;
        const step = normalized <= 1 ? 1 : (normalized <= 2 ? 2 : (normalized <= 5 ? 5 : 10));
        return Math.max(1, step * magnitude);
    }

    function screenToWorld(sx, sy) {
        return {
            x: (sx - canvas.width / 2 - camera.x) / camera.zoom,
            y: (canvas.height / 2 - sy + camera.y) / camera.zoom
        };
    }

    function worldToScreen(wx, wy) {
        return {
            x: wx * camera.zoom + canvas.width / 2 + camera.x,
            y: canvas.height / 2 - wy * camera.zoom + camera.y
        };
    }

    function resize() {
        canvas.width = canvas.parentElement.clientWidth;
        canvas.height = canvas.parentElement.clientHeight;
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

    function getSnapCandidates(refPoint, excludeEntity) {
        const snaps = [];
        const allSegments = [];

        entities.forEach(ent => {
            if (ent === excludeEntity) return;

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
            } else if (ent.type === 'point') {
                snaps.push({ x: ent.x, y: ent.y, type: 'endpoint' });
            }
        });

        getIntersectionPoints().forEach(intersection => {
            snaps.push({ x: intersection.x, y: intersection.y, type: 'intersection' });
        });

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

    function findBestSnap(mouseScreenX, mouseScreenY, excludeEntity = null) {
        if (!document.getElementById('osnapToggle').checked) return null;

        const cursorWorld = screenToWorld(mouseScreenX, mouseScreenY);
        const refPt = isDrawing ? (currentTool === 'pline' && plineVertices.length > 0 ? plineVertices[plineVertices.length - 1] : startPoint) : (activeGrip ? activeGrip.startWorld : null);
        const { discrete, segments } = getSnapCandidates(refPt, excludeEntity);

        let bestSnap = null;
        // During drawing, increase snap tolerance to make snapping easier
        let minDistance = isDrawing ? SNAP_TOLERANCE_PX * 1.5 : SNAP_TOLERANCE_PX;

        discrete.forEach(pt => {
            const screenPt = worldToScreen(pt.x, pt.y);
            const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
            if (d < minDistance) {
                minDistance = d;
                bestSnap = { worldX: pt.x, worldY: pt.y, screenX: screenPt.x, screenY: screenPt.y, type: pt.type };
            }
        });

        if (bestSnap) return bestSnap;

        segments.forEach(seg => {
            const res = pointToSegmentDistance(cursorWorld.x, cursorWorld.y, seg.p1.x, seg.p1.y, seg.p2.x, seg.p2.y);
            const screenPt = worldToScreen(res.x, res.y);
            const d = Math.hypot(mouseScreenX - screenPt.x, mouseScreenY - screenPt.y);
            if (d < minDistance) {
                minDistance = d;
                bestSnap = { worldX: res.x, worldY: res.y, screenX: screenPt.x, screenY: screenPt.y, type: 'nearest' };
            }
        });

        entities.filter(e => e !== excludeEntity).forEach(e => {
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
        if (ent.type === 'point') {
            return [{ id: 'center', type: 'move', x: ent.x, y: ent.y }];
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
        } else if (ent.type === 'point') {
            ent.x = targetPt.x; ent.y = targetPt.y;
        }

        updatePropertiesPalette();
    }

    function hitTestEntity(worldPt, screenPt) {
        for (let i = entities.length - 1; i >= 0; i--) {
            const ent = entities[i];
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
            } else if (ent.type === 'point') {
                const dist = Math.hypot(worldPt.x - ent.x, worldPt.y - ent.y);
                if (dist * camera.zoom <= SELECT_TOLERANCE_PX + 4) return { entity: ent, segmentIndex: null };
            }
        }
        return null;
    }

    function getEntityBounds(ent) {
        const points = [];
        if (ent.type === 'line') {
            points.push({ x: ent.x1, y: ent.y1 }, { x: ent.x2, y: ent.y2 });
        } else if (ent.type === 'rect') {
            points.push(
                { x: ent.x, y: ent.y },
                { x: ent.x + ent.w, y: ent.y + ent.h }
            );
        } else if (ent.type === 'pline') {
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

            camera.zoom = Math.max(0.05, Math.min(availableWidth / width, availableHeight / height, 100));
            camera.x = -centerX * camera.zoom;
            camera.y = centerY * camera.zoom;
        }
        localStorage.setItem('cad_zoom', String(camera.zoom));
        statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
        render();
        triggerAutoSave();
        showToast('Zoom extents applied.', 'info', 1500);
    }

    function isEntityInSelectionBox(ent, start, end) {
        const bounds = getEntityBounds(ent);
        if (!bounds) return false;
        const minX = Math.min(start.x, end.x);
        const minY = Math.min(start.y, end.y);
        const maxX = Math.max(start.x, end.x);
        const maxY = Math.max(start.y, end.y);
        return bounds.maxX >= minX && bounds.minX <= maxX && bounds.maxY >= minY && bounds.minY <= maxY;
    }

    function translateEntity(ent, offsetX, offsetY) {
        if (ent.type === 'line') {
            ent.x1 += offsetX; ent.y1 += offsetY;
            ent.x2 += offsetX; ent.y2 += offsetY;
        } else if (ent.type === 'rect') {
            ent.x += offsetX; ent.y += offsetY;
        } else if (ent.type === 'pline') {
            ent.points.forEach(point => { point.x += offsetX; point.y += offsetY; });
        } else if (['circle', 'ellipse', 'arc'].includes(ent.type)) {
            ent.cx += offsetX; ent.cy += offsetY;
        } else if (ent.type === 'point') {
            ent.x += offsetX; ent.y += offsetY;
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
        statusMode.innerText = 'MOVE: BASE POINT';
        showToast('Specify the base point.', 'info', 2200);
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

    function getCommandPoint(screenX, screenY) {
        activeSnap = findBestSnap(screenX, screenY, null);
        if (activeSnap) return { x: activeSnap.worldX, y: activeSnap.worldY };
        const raw = screenToWorld(screenX, screenY);
        if (document.getElementById('snapGrid').checked) {
            return { x: Math.round(raw.x / 10) * 10, y: Math.round(raw.y / 10) * 10 };
        }
        return raw;
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
            ctx.save();
            ctx.fillStyle = '#777';
            ctx.font = '10px Consolas, monospace';
            ctx.textBaseline = 'top';
            for (let x = startX; x <= endX; x += gridSize) {
                const screenX = worldToScreen(x, 0).x;
                if (screenX >= 2 && screenX <= canvas.width - 2) {
                    ctx.textAlign = 'center';
                    ctx.fillText(String(Math.round(x)), screenX, canvas.height - 14);
                }
            }
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            for (let y = startY; y <= endY; y += gridSize) {
                const screenY = worldToScreen(0, y).y;
                if (screenY >= 2 && screenY <= canvas.height - 2) {
                    ctx.fillText(String(Math.round(y)), 4, screenY);
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

    function drawGrips(ent) {
        const grips = getEntityGrips(ent);
        grips.forEach(g => {
            const sp = worldToScreen(g.x, g.y);
            const isHot = (activeGrip && activeGrip.id === g.id);
            const isHover = (hoveredGrip && hoveredGrip.id === g.id);

            ctx.save();
            ctx.beginPath();
            ctx.fillStyle = isHot ? '#ff2222' : (isHover ? '#33bbff' : (g.color || '#007acc'));
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1.2;
            const size = isHot ? 9 : 7;
            ctx.fillRect(sp.x - size / 2, sp.y - size / 2, size, size);
            ctx.strokeRect(sp.x - size / 2, sp.y - size / 2, size, size);

            if (g.label) {
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

    function drawEntity(ent, isTemp = false) {
        const isSelected = selectedEntities.has(ent) || selectedEntity === ent;

        ctx.save();
        ctx.beginPath();
        ctx.strokeStyle = isSelected ? '#00bfff' : (ent.color || '#fff');
        ctx.fillStyle = isSelected ? '#00bfff' : (ent.color || '#fff');
        ctx.lineWidth = (ent.width || 2);
        ctx.setLineDash(isTemp ? [4, 4] : (isSelected ? [6, 3] : []));

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
        else if (ent.type === 'point') {
            const p = worldToScreen(ent.x, ent.y);
            ctx.arc(p.x, p.y, 4, 0, Math.PI * 2);
            ctx.fill();

            ctx.save();
            ctx.font = '11px Consolas, monospace';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = isSelected ? '#00bfff' : (ent.color || '#fff');
            ctx.fillText(`${ent.name || ''}:${formatCoord(ent.z || 0)}`, p.x + 8, p.y - 8);
            ctx.restore();
        }

        ctx.restore();

        if (isSelected && !isTemp) {
            try {
                drawGrips(ent);
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

    function generateContours() {
        const pointEntities = entities.filter(entity => entity.type === 'point')
            .filter(point => Number.isFinite(Number(point.x)) && Number.isFinite(Number(point.y)) && Number.isFinite(Number(point.z)));
        if (pointEntities.length < 3) {
            showToast('At least 3 points with X, Y and Z are required.', 'warning', 2200);
            return;
        }

        const pointList = pointEntities.map(point => ({ x: Number(point.x), y: Number(point.y), z: Number(point.z) }));
        const minZ = Math.min(...pointList.map(point => point.z));
        const maxZ = Math.max(...pointList.map(point => point.z));
        const firstLevel = Math.ceil(minZ - 1e-9);
        const lastLevel = Math.floor(maxZ + 1e-9);
        if (firstLevel > lastLevel) {
            showToast('The point elevations do not span a full 1 m contour interval.', 'warning', 2200);
            return;
        }

        const triangles = getDelaunayTriangles(pointList);
        const segmentsByLevel = new Map();
        const pointKey = point => `${Math.round(point.x * 1e6)}:${Math.round(point.y * 1e6)}`;
        for (let level = firstLevel; level <= lastLevel; level++) {
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
        showToast(`${contourCount} contour polylines generated at 1 m intervals.`, 'success', 2500);
    }

    function render() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawGrid();

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

        drawIntersectionMarkers();

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

        // Draw snap marker: during drawing, show snap to existing entities
        if (activeSnap) {
            // Only exclude an entity if we have an active grip
            const snapToShow = activeSnap;
            if (isDrawing || activeGrip || moveCommand) {
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
    }

    function updatePropertiesPalette() {
        if (!selectedEntity) {
            propCount.innerText = 'No selection';
            propContainer.innerHTML = `<div style="color: var(--text-muted); text-align: center; margin-top: 40px;">Select an entity to view and edit its properties.</div>`;
            return;
        }

        propCount.innerText = selectedEntities.size > 1
            ? `${selectedEntities.size} SELECTED`
            : selectedEntity.type.toUpperCase();
        let html = `
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
        } else if (selectedEntity.type === 'point') {
            html += `
                <div class="prop-group">
                    <div class="prop-group-title">Point Geometry</div>
                    <div class="prop-row"><label>Name</label><input type="text" id="prop-point-name" value="${selectedEntity.name || ''}"></div>
                    <div class="prop-row"><label>Position X</label><input type="text" id="prop-px" value="${formatCoord(selectedEntity.x)}"></div>
                    <div class="prop-row"><label>Position Y</label><input type="text" id="prop-py" value="${formatCoord(selectedEntity.y)}"></div>
                    <div class="prop-row"><label>Elevation Z</label><input type="text" id="prop-pz" value="${formatCoord(selectedEntity.z || 0)}"></div>
                </div>
            `;
        }

        propContainer.innerHTML = html;

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

        if (selectedEntity.type === 'line') {
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
        const mouseWorld = screenToWorld(mouseScreen.x, mouseScreen.y);

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
                    statusMode.innerText = 'MODE: SELECT';
                    updatePropertiesPalette();
                    render();
                    triggerAutoSave();
                    showToast('Object(s) moved.', 'success', 1500);
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

                const hit = hitTestEntity(mouseWorld, mouseScreen);
                if (hit) {
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
                            initialStates: new Map([...selectedEntities].map(entity => [
                                entity,
                                JSON.parse(JSON.stringify(entity))
                            ])),
                            changed: false,
                            saved: false
                        };
                    }
                } else {
                    selectionBoxStart = mouseWorld;
                    selectionBoxCurrent = mouseWorld;
                    isSelectingBox = true;
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
            render();
            return;
        }

        const rect = canvas.getBoundingClientRect();
        const sx = e.clientX - rect.left;
        const sy = e.clientY - rect.top;

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

        if (isSelectingBox) {
            selectionBoxCurrent = screenToWorld(sx, sy);
            render();
            return;
        }

        if (activeMove) {
            const currentWorld = screenToWorld(sx, sy);
            applyObjectMove(activeMove, currentWorld);
            if (activeMove.changed && !activeMove.saved) {
                saveState();
                activeMove.saved = true;
            }
            render();
            return;
        }

        if (selectedEntity && !activeGrip && currentTool === 'select') {
            hoveredGrip = hitTestGrip(sx, sy, selectedEntity);
            canvas.style.cursor = hoveredGrip ? 'pointer' : 'default';
        } else if (currentTool !== 'select') {
            canvas.style.cursor = 'crosshair';
        }

        // During drawing/snapping, don't exclude any entities - we want snap available to ALL existing objects
        let exclude = null;
        if (activeGrip) {
            exclude = activeGrip.entity;
        }
        activeSnap = findBestSnap(sx, sy, exclude);

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
            const targetPt = applyOrtho(activeGrip.startWorld, currentMouse);
            applyGripModification(activeGrip, targetPt);
        }

        if (isDrawing || activeSnap || activeGrip || hoveredGrip || isSelectingBox) render();
    });

    window.addEventListener('mouseup', (e) => {
        if (isPanning) {
            isPanning = false;
            triggerAutoSave();
        }
        if (isSelectingBox) {
            const rect = canvas.getBoundingClientRect();
            selectionBoxCurrent = screenToWorld(e.clientX - rect.left, e.clientY - rect.top);
            const boxStart = selectionBoxStart;
            const boxEnd = selectionBoxCurrent;
            const boxMatches = entities.filter(entity => isEntityInSelectionBox(entity, boxStart, boxEnd));
            if (e.shiftKey) {
                boxMatches.forEach(entity => selectedEntities.add(entity));
            } else {
                selectedEntities = new Set(boxMatches);
            }
            selectedEntity = boxMatches[boxMatches.length - 1] || selectedEntities.values().next().value || null;
            selectedSegmentIndex = null;
            isSelectingBox = false;
            selectionBoxStart = null;
            selectionBoxCurrent = null;
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
        camera.zoom = Math.max(0.05, Math.min(camera.zoom * zoomFactor, 100));
        camera.x = mouseX - canvas.width / 2 - worldBefore.x * camera.zoom;
        camera.y = mouseY - canvas.height / 2 + worldBefore.y * camera.zoom;

        localStorage.setItem('cad_zoom', String(camera.zoom));
        statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
        triggerAutoSave();
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

    lineWidthSelect.addEventListener('change', () => {
        triggerAutoSave();
    });

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

        const dataInput = document.createElement('input');
        dataInput.type = 'hidden';
        dataInput.name = 'data';
        dataInput.value = JSON.stringify({ 
            entities: entities,
            angleUnit: angleUnitsSelect.value
        });
        form.appendChild(dataInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

        showToast('AutoCAD 2007 DXF file created.', 'success');
    });

    window.addEventListener('keydown', (e) => {
        const editingText = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
        if ((e.key === 'm' || e.key === 'M') && !editingText && currentTool === 'select') {
            e.preventDefault();
            startMoveCommand();
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
            if (moveCommand) {
                moveCommand = null;
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
            document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
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
    const closePointImport = () => {
        pointImportModal.classList.remove('open');
        pointImportInput.value = '';
    };

    document.getElementById('btn-import-points').addEventListener('click', () => {
        pointImportModal.classList.add('open');
        pointImportInput.focus();
    });
    document.getElementById('btn-generate-contours').addEventListener('click', generateContours);
    document.getElementById('btn-cancel-point-import').addEventListener('click', closePointImport);
    pointImportModal.addEventListener('click', (event) => {
        if (event.target === pointImportModal) closePointImport();
    });
    document.getElementById('btn-apply-point-import').addEventListener('click', () => {
        const lines = pointImportInput.value.split(/\r?\n/);
        const importedPoints = [];
        const invalidLines = [];
        const color = document.getElementById('strokeColor').value;
        const width = parseInt(document.getElementById('lineWidth').value);

        lines.forEach((line, index) => {
            if (!line.trim()) return;
            const values = line.trim().split(/[,\t]+/).map(value => value.trim());
            if (values.length !== 2 && values.length !== 3) {
                invalidLines.push(index + 1);
                return;
            }
            const coordinates = values.map(value => parseStrictFloat(value, NaN));
            if (coordinates.some(value => !Number.isFinite(value))) {
                invalidLines.push(index + 1);
                return;
            }
            importedPoints.push({
                type: 'point',
                x: coordinates[0],
                y: coordinates[1],
                z: coordinates[2] ?? 0,
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
    });

    document.getElementById('btn-move').addEventListener('click', startMoveCommand);

    function autoLoad() {
        const formData = new FormData();
        formData.append('action', 'load');
        fetch(apiEndpoint, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'success' && res.data) {
                    if (Array.isArray(res.data)) {
                        entities = res.data;
                    } else {
                        entities = res.data.entities || [];
                        if (res.data.angleUnit) {
                            angleUnitsSelect.value = res.data.angleUnit;
                            localStorage.setItem('cad_angle_unit', res.data.angleUnit);
                        }
                        if (Number.isFinite(Number(res.data.zoom))) {
                            camera.zoom = Math.max(0.05, Math.min(Number(res.data.zoom), 100));
                            localStorage.setItem('cad_zoom', String(camera.zoom));
                            statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
                        }
                        if (Number.isFinite(Number(res.data.propertiesWidth))) {
                            const propertiesWidth = Math.max(240, Math.min(600, Number(res.data.propertiesWidth)));
                            propertiesPalette.style.width = `${propertiesWidth}px`;
                            propertiesPalette.style.flexBasis = `${propertiesWidth}px`;
                        }
                        resize();
                        if (Number.isFinite(Number(res.data.viewCenterX)) && Number.isFinite(Number(res.data.viewCenterY))) {
                            let viewCenterX = Number(res.data.viewCenterX);
                            if (Number(res.data.viewCenterVersion) !== 2) {
                                viewCenterX -= canvas.width / (2 * camera.zoom);
                            }
                            camera.x = -viewCenterX * camera.zoom;
                            camera.y = Number(res.data.viewCenterY) * camera.zoom;
                        }
                        if (['1', '2', '3', '4'].includes(String(res.data.lineWidth))) {
                            lineWidthSelect.value = String(res.data.lineWidth);
                        }
                    }
                    resize();
                }
            })
            .catch(() => {});
    }

    statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
    resize();
    autoLoad();
})();
</script>
</body>
</html>
