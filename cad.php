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

    $aunits = ($angleUnit === 'grad') ? 2 : 0;

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
                $dxf[] = "100{$nl}AcDbEntity";
                $dxf[] = "8{$nl}0";
                $dxf[] = "62{$nl}{$color}";
                $dxf[] = "100{$nl}AcDbPoint";
                $dxf[] = "10{$nl}" . sprintf('%.4f', (float)$ent['x']);
                $dxf[] = "20{$nl}" . sprintf('%.4f', (float)$ent['y']);
                $dxf[] = "30{$nl}0.0000";
            }
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
        if (file_put_contents($dataFile, $jsonContent)) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Αποτυχία εγγραφής αρχείου.']);
        }
        exit;
    }

    if ($action === 'load') {
        header('Content-Type: application/json; charset=utf-8');
        if (file_exists($dataFile)) {
            echo json_encode(['status' => 'success', 'data' => json_decode(file_get_contents($dataFile), true)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Δεν βρέθηκε αποθηκευμένο σχέδιο.']);
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
<html lang="el">
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
        input[type="color"] { padding: 0 2px; width: 32px; height: 26px; }
        label { font-size: 11px; display: flex; align-items: center; gap: 4px; cursor: pointer; color: var(--text-main); }

        #main-container { display: flex; flex: 1; position: relative; height: calc(100vh - 74px); }
        #viewport { flex: 1; position: relative; height: 100%; cursor: crosshair; background: #121212; }
        canvas { display: block; width: 100%; height: 100%; }

        #properties-palette {
            width: 330px;
            background: var(--bg-panel);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            font-size: 12px;
        }
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
    </style>
</head>
<body>

<div id="toolbar">
    <div class="btn-group">
        <button id="tool-select" class="tool-btn active" data-tool="select">Select</button>
        <button id="tool-line" class="tool-btn" data-tool="line">Line</button>
        <button id="tool-pline" class="tool-btn" data-tool="pline">Polyline (PL)</button>
        <button id="tool-rect" class="tool-btn" data-tool="rect">Rectangle</button>
        <button id="tool-circle" class="tool-btn" data-tool="circle">Circle</button>
        <button id="tool-arc" class="tool-btn" data-tool="arc">Arc</button>
        <button id="tool-ellipse" class="tool-btn" data-tool="ellipse">Ellipse</button>
        <button id="tool-point" class="tool-btn" data-tool="point">Point</button>
    </div>

    <div class="btn-group">
        <select id="angleUnits" title="Μονάδα Μέτρησης Γωνιών (Αποθηκεύεται)">
            <option value="deg">Degrees (°)</option>
            <option value="grad">Grads (g)</option>
        </select>
        <input type="color" id="strokeColor" value="#ffffff" title="Χρώμα Οντότητας">
        <select id="lineWidth" title="Πάχος Γραμμής">
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

    <div class="btn-group">
        <button id="btn-undo" title="Undo (Ctrl+Z)">Undo</button>
        <button id="btn-redo" title="Redo (Ctrl+Y)">Redo</button>
        <button id="btn-delete" title="Delete Selected (Del)">Delete</button>
        <button id="btn-clear">Clear</button>
    </div>

    <div class="btn-group" style="border: none;">
        <button id="btn-export-dxf" style="background: #e65100; border-color: #f57c00; font-weight: 600;">Εξαγωγή σε DXF (2007)</button>
        <span id="save-indicator" style="font-size: 11px; color: #4ec9b0; margin-left: 6px;">● Auto-saved</span>
    </div>
</div>

<div id="main-container">
    <div id="viewport">
        <canvas id="cadCanvas"></canvas>
    </div>

    <div id="properties-palette">
        <div class="panel-header">
            <span>PROPERTIES</span>
            <span id="prop-entity-count" style="color: var(--text-muted);">No selection</span>
        </div>
        <div class="panel-content" id="properties-container">
            <div style="color: var(--text-muted); text-align: center; margin-top: 40px;">
                Επιλέξτε ένα αντικείμενο για προβολή και επεξεργασία ιδιοτήτων.
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
    const toastContainer = document.getElementById('toast-container');
    const saveIndicator = document.getElementById('save-indicator');

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
        return angleUnitsSelect.value === 'grad' ? (aziRad * 200) / Math.PI : (aziRad * 180) / Math.PI;
    }

    function azimuthValueToRad(val) {
        const num = parseStrictFloat(val);
        return angleUnitsSelect.value === 'grad' ? (num * Math.PI) / 200 : (num * Math.PI) / 180;
    }

    function formatAzimuth(dx, dy) {
        const aziRad = calculateAzimuthRad(dx, dy);
        const val = azimuthRadToValue(aziRad);
        const unit = angleUnitsSelect.value === 'grad' ? 'g' : '°';
        return { val: val.toFixed(4), unit, rad: aziRad };
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
                entities: entities
            };
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('data', JSON.stringify(payload));

            fetch('cad.php', { method: 'POST', body: formData })
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
            showToast('Αναίρεση κορυφής Polyline.', 'info', 1500);
            render();
            return;
        }

        if (undoStack.length === 0) {
            showToast('Δεν υπάρχουν άλλες ενέργειες για αναίρεση (Undo).', 'warning', 1800);
            return;
        }

        redoStack.push(JSON.stringify(entities));
        const previousState = undoStack.pop();
        entities = JSON.parse(previousState);
        selectedEntity = null;
        selectedSegmentIndex = null;
        selectedVertexIndex = 0;
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        showToast('Αναίρεση (Undo) επιτυχής.', 'info', 1500);
    }

    function executeRedo() {
        if (redoStack.length === 0) {
            showToast('Δεν υπάρχουν ενέργειες για επαναφορά (Redo).', 'warning', 1800);
            return;
        }

        undoStack.push(JSON.stringify(entities));
        const nextState = redoStack.pop();
        entities = JSON.parse(nextState);
        selectedEntity = null;
        selectedSegmentIndex = null;
        selectedVertexIndex = 0;
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        showToast('Επαναφορά (Redo) επιτυχής.', 'info', 1500);
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
            selectedSegmentIndex = entityToSelect.type === 'pline' ? 0 : null;
            selectedVertexIndex = 0;
        }
        updatePropertiesPalette();
        render();
    }

    let camera = { x: 0, y: 0, zoom: 1 };
    const GRID_SIZE = 50;
    const SNAP_TOLERANCE_PX = 14;
    const SELECT_TOLERANCE_PX = 8;
    const GRIP_HIT_RADIUS_PX = 8;

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

        for (let i = 0; i < allSegments.length; i++) {
            for (let j = i + 1; j < allSegments.length; j++) {
                const inter = getLineIntersection(allSegments[i].p1, allSegments[i].p2, allSegments[j].p1, allSegments[j].p2);
                if (inter) snaps.push({ x: inter.x, y: inter.y, type: 'intersection' });
            }
        }

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
        let minDistance = SNAP_TOLERANCE_PX;

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

    function drawGrid() {
        const topLeft = screenToWorld(0, 0);
        const bottomRight = screenToWorld(canvas.width, canvas.height);

        const startX = Math.floor(topLeft.x / GRID_SIZE) * GRID_SIZE;
        const endX = Math.ceil(bottomRight.x / GRID_SIZE) * GRID_SIZE;
        const startY = Math.floor(bottomRight.y / GRID_SIZE) * GRID_SIZE;
        const endY = Math.ceil(topLeft.y / GRID_SIZE) * GRID_SIZE;

        ctx.lineWidth = 1;
        ctx.strokeStyle = '#222';
        ctx.beginPath();
        for (let x = startX; x <= endX; x += GRID_SIZE) {
            const p1 = worldToScreen(x, startY);
            const p2 = worldToScreen(x, endY);
            ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
        }
        for (let y = startY; y <= endY; y += GRID_SIZE) {
            const p1 = worldToScreen(startX, y);
            const p2 = worldToScreen(endX, y);
            ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
        }
        ctx.stroke();

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
        const isSelected = (selectedEntity === ent);

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
                    const ang = Math.atan2(sp2.y - p1.y, p2.x - p1.x);
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
            case 'nearest':
                ctx.moveTo(x - s, y - s); ctx.lineTo(x + s, y + s);
                ctx.lineTo(x - s, y + s); ctx.lineTo(x + s, y - s); ctx.closePath();
                ctx.stroke();
                break;
        }
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
            showToast(`Polyline δημιουργήθηκε (${plineVertices.length} κορυφές, ${close ? 'Closed' : 'Open'})`, 'info');
            switchToSelectMode(newEntity);
        } else {
            switchToSelectMode(null);
        }
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

        if (activeSnap && (isDrawing || activeGrip)) {
            drawSnapMarker(activeSnap);
        }
    }

    function updatePropertiesPalette() {
        if (!selectedEntity) {
            propCount.innerText = 'No selection';
            propContainer.innerHTML = `<div style="color: var(--text-muted); text-align: center; margin-top: 40px;">Επιλέξτε ένα αντικείμενο για προβολή και επεξεργασία ιδιοτήτων.</div>`;
            return;
        }

        propCount.innerText = selectedEntity.type.toUpperCase();
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
                        <button id="btn-reverse-line" style="width: 100%; background: #333; padding: 4px; font-size: 11px;">⇄ Αντιστροφή Φοράς (P1 ↔ P2)</button>
                    </div>
                    <div class="prop-row"><label style="color:#4caf50; font-weight:bold;">Start X (P1)</label><input type="text" id="prop-x1" value="${formatCoord(selectedEntity.x1)}"></div>
                    <div class="prop-row"><label style="color:#4caf50; font-weight:bold;">Start Y (P1)</label><input type="text" id="prop-y1" value="${formatCoord(selectedEntity.y1)}"></div>
                    <div class="prop-row"><label style="color:#ff9800; font-weight:bold;">End X (P2)</label><input type="text" id="prop-x2" value="${formatCoord(selectedEntity.x2)}"></div>
                    <div class="prop-row"><label style="color:#ff9800; font-weight:bold;">End Y (P2)</label><input type="text" id="prop-y2" value="${formatCoord(selectedEntity.y2)}"></div>
                    <div class="prop-row"><label>Delta X (Δx)</label><input type="text" readonly value="${formatCoord(dx)}"></div>
                    <div class="prop-row"><label>Delta Y (Δy)</label><input type="text" readonly value="${formatCoord(dy)}"></div>
                    <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Length (S)</label><input type="text" id="prop-len" value="${formatCoord(len)}"></div>
                    <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Azimuth α (${azi.unit})</label><input type="text" id="prop-azi" value="${azi.val}"></div>
                </div>
            `;
        } else if (selectedEntity.type === 'pline') {
            const pts = selectedEntity.points || [];
            const numSegs = selectedEntity.closed ? pts.length : Math.max(0, pts.length - 1);
            if (selectedSegmentIndex === null && numSegs > 0) selectedSegmentIndex = 0;
            if (selectedVertexIndex >= pts.length) selectedVertexIndex = 0;

            const centroid = getPolylineCentroid(pts, selectedEntity.closed);
            const unit = angleUnitsSelect.value === 'grad' ? 'g' : '°';

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
                    segOptions += `<option value="${i}" ${i === sIdx ? 'selected' : ''}>Τμήμα ${i + 1} (K${i+1} → K${(i+1)%pts.length + 1})</option>`;
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
                        <div class="prop-row"><label>Delta X (Δx)</label><input type="text" readonly value="${formatCoord(dx)}"></div>
                        <div class="prop-row"><label>Delta Y (Δy)</label><input type="text" readonly value="${formatCoord(dy)}"></div>
                        <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Length (S)</label><input type="text" id="prop-seg-len" value="${formatCoord(sLen)}"></div>
                        <div class="prop-row"><label style="color:#4ec9b0; font-weight:bold;">Azimuth α (${sAzi.unit})</label><input type="text" id="prop-seg-azi" value="${sAzi.val}"></div>
                    </div>
                `;
            }

            let vertexOptions = '';
            pts.forEach((_, idx) => {
                vertexOptions += `<option value="${idx}" ${idx === selectedVertexIndex ? 'selected' : ''}>Κορυφή K${idx + 1}</option>`;
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
                    <div class="prop-row"><label style="color:#e040fb; font-weight:bold;">Γωνία Κορυφής β</label><input type="text" readonly value="${rightAngleVal} ${activeV.angleRight !== null ? unit : ''}"></div>
                    <div class="prop-row"><label style="color:#4caf50;">Εσωτερική Γωνία</label><input type="text" readonly value="${interiorAngleVal} ${activeV.angleInterior !== null ? unit : ''}"></div>
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
                ? (pts.length - 2) * (angleUnitsSelect.value === 'grad' ? 200 : 180) 
                : null;

            html += `
                ${segHtml}
                <div class="prop-group">
                    <div class="prop-group-title" style="color: #e040fb;">Vertex Angles (Γωνίες Κορυφών)</div>
                    <div class="prop-row">
                        <label>Active Vertex</label>
                        <select id="prop-vertex-select">${vertexOptions}</select>
                    </div>
                    ${activeVHtml}
                    
                    <div style="max-height: 140px; overflow-y: auto; margin-top: 8px; border: 1px solid #3f3f46;">
                        <table class="cad-table">
                            <thead>
                                <tr>
                                    <th>Κορ.</th>
                                    <th>X</th>
                                    <th>Y</th>
                                    <th>Γωνία β</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${tableRows}
                            </tbody>
                        </table>
                    </div>
                    ${theoreticalSum !== null ? `
                        <div style="font-size: 10px; color: #aaa; margin-top: 4px; display: flex; justify-content: space-between;">
                            <span>Θεωρητικό Σ(β): <b>${theoreticalSum.toFixed(4)}${unit}</b></span>
                        </div>
                    ` : ''}
                </div>

                <div class="prop-group">
                    <div class="prop-group-title">Polyline Global</div>
                    <div style="margin-bottom: 8px;">
                        <button id="btn-reverse-pline" style="width: 100%; background: #333; padding: 4px; font-size: 11px;">⇄ Αντιστροφή Φοράς Polyline</button>
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
            const unit = angleUnitsSelect.value === 'grad' ? 'g' : '°';

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
                    <div class="prop-row"><label>Position X</label><input type="text" id="prop-px" value="${formatCoord(selectedEntity.x)}"></div>
                    <div class="prop-row"><label>Position Y</label><input type="text" id="prop-py" value="${formatCoord(selectedEntity.y)}"></div>
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
            });
        };

        const colorInput = document.getElementById('prop-color');
        if (colorInput) colorInput.addEventListener('input', (e) => { 
            selectedEntity.color = e.target.value; 
            render(); 
            triggerAutoSave();
        });

        const widthSelect = document.getElementById('prop-width');
        if (widthSelect) widthSelect.addEventListener('change', (e) => { 
            saveState(); 
            selectedEntity.width = parseInt(e.target.value); 
            render(); 
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
                    showToast('Αντιστροφή φοράς γραμμής ολοκληρώθηκε.', 'info');
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
                    showToast('Αντιστροφή φοράς Polyline ολοκληρώθηκε.', 'info');
                });
            }

            const closedSelect = document.getElementById('prop-closed');
            if (closedSelect) {
                closedSelect.addEventListener('change', (e) => {
                    saveState();
                    selectedEntity.closed = (e.target.value === 'true');
                    updatePropertiesPalette();
                    render();
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
            bindInput('prop-px', v => selectedEntity.x = v);
            bindInput('prop-py', v => selectedEntity.y = v);
        }
    }

    canvas.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        if (currentTool === 'pline' && isDrawing) {
            finishPline(false);
        }
    });

    canvas.addEventListener('mousedown', (e) => {
        if (e.button === 1 || e.buttons === 4 || e.altKey) {
            isPanning = true;
            panStart = { x: e.clientX - camera.x, y: e.clientY - camera.y };
            return;
        }

        const rect = canvas.getBoundingClientRect();
        const mouseScreen = { x: e.clientX - rect.left, y: e.clientY - rect.top };
        const mouseWorld = screenToWorld(mouseScreen.x, mouseScreen.y);

        if (e.button === 0) {
            if (currentTool === 'select') {
                if (selectedEntity) {
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
                    selectedEntity = hit.entity;
                    selectedSegmentIndex = hit.segmentIndex;
                } else {
                    selectedEntity = null;
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
                    showToast('Polyline: Κλικ για κορυφές | Enter/Δεξί κλικ για λήξη | "C" για κλείσιμο', 'info', 4000);
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
                    showToast('Arc: Κλικ για το σημείο έναρξης (Start Point & Radius)', 'info', 3000);
                } else if (arcDrawingStep === 1) {
                    arcStartPoint = effectiveCoords;
                    arcDrawingStep = 2;
                    showToast('Arc: Κλικ για το σημείο λήξης (End Point)', 'info', 3000);
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

        if (selectedEntity && !activeGrip && currentTool === 'select') {
            hoveredGrip = hitTestGrip(sx, sy, selectedEntity);
            canvas.style.cursor = hoveredGrip ? 'pointer' : 'default';
        } else if (currentTool !== 'select') {
            canvas.style.cursor = 'crosshair';
        }

        const exclude = activeGrip ? activeGrip.entity : null;
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

        if (isDrawing || activeSnap || activeGrip || hoveredGrip) render();
    });

    window.addEventListener('mouseup', () => {
        if (isPanning) isPanning = false;
        if (activeGrip) {
            activeGrip = null;
            statusMode.innerText = 'MODE: SELECT';
            triggerAutoSave();
            render();
        }
    });

    canvas.addEventListener('wheel', (e) => {
        e.preventDefault();
        const zoomFactor = e.deltaY < 0 ? 1.15 : 0.85;
        const rect = canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        const worldBefore = screenToWorld(mouseX, mouseY);
        camera.zoom = Math.max(0.05, Math.min(camera.zoom * zoomFactor, 30));
        camera.x = mouseX - canvas.width / 2 - worldBefore.x * camera.zoom;
        camera.y = mouseY - canvas.height / 2 + worldBefore.y * camera.zoom;

        statusZoom.innerText = `ZOOM: ${(camera.zoom * 100).toFixed(0)}%`;
        render();
    }, { passive: false });

    angleUnitsSelect.addEventListener('change', () => {
        const val = angleUnitsSelect.value;
        localStorage.setItem('cad_angle_unit', val);
        updatePropertiesPalette();
        render();
        triggerAutoSave();
        showToast(`Μονάδα γωνιών: ${val === 'grad' ? 'Grads (400g)' : 'Degrees (360°)'}`, 'info');
    });

    // DXF Export Button Event (Full Payload with Units)
    document.getElementById('btn-export-dxf').addEventListener('click', () => {
        if (!entities || entities.length === 0) {
            showToast('Δεν υπάρχουν οντότητες προς εξαγωγή.', 'warning');
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

        showToast('Το αρχείο DXF (AutoCAD 2007) δημιουργήθηκε!', 'success');
    });

    window.addEventListener('keydown', (e) => {
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
            showToast(`OSNAP: ${chk.checked ? 'Ενεργό' : 'Απενεργοποιημένο'}`, chk.checked ? 'success' : 'warning', 1800);
        } else if (e.key === 'F8') {
            e.preventDefault();
            const chk = document.getElementById('orthoToggle');
            chk.checked = !chk.checked;
            document.getElementById('status-ortho').innerText = `ORTHO: ${chk.checked ? 'ON' : 'OFF'}`;
            showToast(`ORTHO: ${chk.checked ? 'Ενεργό' : 'Απενεργοποιημένο'}`, chk.checked ? 'success' : 'warning', 1800);
        } else if (e.key === 'Enter') {
            if (currentTool === 'pline' && isDrawing) {
                finishPline(false);
            }
        } else if (e.key === 'c' || e.key === 'C') {
            if (currentTool === 'pline' && isDrawing && plineVertices.length >= 2) {
                finishPline(true);
            }
        } else if (e.key === 'Escape') {
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
            selectedSegmentIndex = null;
            selectedVertexIndex = 0;
            switchToSelectMode(null);
            showToast('Ακύρωση ενέργειας / Αποεπιλογή', 'info', 1500);
        } else if (e.key === 'Delete' || e.key === 'Backspace') {
            if (selectedEntity && document.activeElement.tagName !== 'INPUT') {
                saveState();
                entities = entities.filter(ent => ent !== selectedEntity);
                selectedEntity = null;
                selectedSegmentIndex = null;
                selectedVertexIndex = 0;
                updatePropertiesPalette();
                render();
                showToast('Το αντικείμενο διαγράφηκε.', 'warning');
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
            showToast(`Εργαλείο: ${btn.innerText}`, 'info', 1500);
        });
    });

    document.getElementById('btn-undo').addEventListener('click', executeUndo);
    document.getElementById('btn-redo').addEventListener('click', executeRedo);

    document.getElementById('btn-delete').addEventListener('click', () => {
        if (selectedEntity) {
            saveState();
            entities = entities.filter(ent => ent !== selectedEntity);
            selectedEntity = null;
            selectedSegmentIndex = null;
            selectedVertexIndex = 0;
            updatePropertiesPalette();
            render();
            showToast('Το αντικείμενο διαγράφηκε.', 'warning');
        } else {
            showToast('Δεν υπάρχει επιλεγμένο αντικείμενο προς διαγραφή.', 'warning');
        }
    });

    document.getElementById('btn-clear').addEventListener('click', () => {
        if (entities.length === 0) {
            showToast('Το σχέδιο είναι ήδη κενό.', 'info');
            return;
        }
        showToast('Καθαρισμός όλων των αντικειμένων;', 'warning', 5000);
        saveState();
        entities = [];
        selectedEntity = null;
        selectedSegmentIndex = null;
        selectedVertexIndex = 0;
        plineVertices = [];
        isDrawing = false;
        arcCenter = null;
        arcStartPoint = null;
        arcDrawingStep = 0;
        updatePropertiesPalette();
        render();
        showToast('Όλο το σχέδιο καθαρίστηκε.', 'success');
    });

    function autoLoad() {
        const formData = new FormData();
        formData.append('action', 'load');
        fetch('cad.php', { method: 'POST', body: formData })
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
                    }
                    render();
                }
            })
            .catch(() => {});
    }

    resize();
    autoLoad();
})();
</script>
</body>
</html>
