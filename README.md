# Web CAD Engine — v10

Browser-based 2D CAD editor built as a self-contained PHP application. The
current release is available in [`cad-v10.php`](./cad-v10.php); it preserves
the complete v9 editor while providing a clean v10 entry point.

## Ελληνικά

### Περιγραφή

Το Web CAD Engine είναι ένας αυτοτελής 2D CAD editor για browser. Το
[`cad-v10.php`](./cad-v10.php) περιέχει σε ένα αρχείο:

- PHP API για σχέδια, revisions, παρουσία χρηστών και DXF export.
- HTML/CSS για toolbar, Canvas, properties palette και dialogs.
- JavaScript engine για σχεδίαση, επιλογή, grips, OSNAP, commands και
  αποθήκευση.

Τα σχέδια αποθηκεύονται ως JSON στον ίδιο φάκελο και εξάγονται σε ASCII DXF
συμβατό με AutoCAD 2007 (AC1021). Δεν απαιτείται database, framework,
dependency manager ή build step.

### Εκκίνηση

Απαιτούνται PHP με JSON/file functions και σύγχρονος browser με Canvas,
Fetch API, Clipboard API και `localStorage`.

```bash
php -S localhost:8000
```

Άνοιξε:

```text
http://localhost:8000/cad-v10.php
```

Ο server πρέπει να έχει δικαίωμα εγγραφής στον φάκελο, επειδή δημιουργούνται
και ενημερώνονται drawing JSON και το `cad_presence.json`.

### Αρχιτεκτονική

1. **PHP backend**
   - Επιλέγει το drawing από `POST[file]`, cookie ή το προεπιλεγμένο αρχείο.
   - Επικυρώνει filenames και nicknames.
   - Υλοποιεί save/load, revisions, entity revisions, drawing list και rename.
   - Διαχειρίζεται collaborative presence.
   - Παράγει DXF 2007 μέσω `generateDXF2007()`.
2. **HTML/CSS interface**
   - Παρέχει toolbar, Canvas, status bar, properties palette και modals.
3. **JavaScript editor**
   - Διατηρεί τα entities στη μεταβλητή `entities`.
   - Μετατρέπει world coordinates σε screen coordinates.
   - Εκτελεί render, hit-testing, selection, grips, snapping και commands.
   - Αποθηκεύει τοπικές ρυθμίσεις κάμερας/περιβάλλοντος στο `localStorage`.

Οι γεωμετρικές συντεταγμένες είναι model/world units. Το zoom επηρεάζει μόνο
την οθόνη και όχι τις αποθηκευμένες διαστάσεις.

Το Scale εφαρμόζεται στα επιλεγμένα αντικείμενα γύρω από ένα snap base point.
Μετά την επιλογή του σημείου ο χρήστης εισάγει απευθείας τον θετικό αριθμητικό
συντελεστή scale.

### Εργαλεία και πλοήγηση

Διατίθενται:

- Select, Line, Polyline, Rectangle, Circle, Arc, Ellipse, Point, Text.
- Move, Scale, Offset, Trim, aligned Dimension, Angle Dimension και Hatch.
- Generate Contours από υψομετρικά σημεία.
- Εισαγωγή ελληνικής πινακίδας ως ομαδοποιημένο object.
- Η πινακίδα χρησιμοποιεί το πρότυπο `pinakidaA4-1.json`, προσαρμόζεται
  αυτόματα στο ενεργό μέγεθος/προσανατολισμό χαρτιού και διαθέτει πεδία
  `ERGODOTIS`, `ERGO`, `PERIOXI`, `MELETITIS`, `THEMA_SXEDIOU`, `ARSXED`,
  `KLIMAKA`, `XRONOSMELETHS` στην properties palette.
- Export DXF 2007 και αντιγραφή περιοχής σχεδίου ως JPG.
- OSNAP, Grid Snap, Ortho, undo/redo και multi-selection.
- Paper frame A0–A4 σε portrait/landscape.
- Angle units: degrees, grads ή radians.
- Print scales: 1:50, 1:100, 1:200, 1:500 και 1:1000.

Βασικές συντομεύσεις:

| Πλήκτρο | Ενέργεια |
| --- | --- |
| `F3` | OSNAP |
| `F8` | Ortho |
| `M`, `S`, `O`, `T`, `D`, `A`, `H` | Move, Scale, Offset, Trim, Dimension, Angle Dimension, Hatch |
| `Enter` / δεξί click | Ολοκλήρωση polyline |
| `C` | Κλείσιμο polyline |
| `Ctrl+A` | Επιλογή όλων |
| `Ctrl+C` / `Ctrl+V` | Αντιγραφή / επικόλληση |
| `Ctrl+Z` / `Ctrl+Y` | Undo / Redo |
| `Delete` / `Backspace` | Διαγραφή |
| `Escape` | Ακύρωση εντολής |

Το OSNAP υποστηρίζει endpoints, midpoints, centers, quadrants,
intersections, perpendicular, tangent και nearest. Οι ίδιοι τύποι εμφανίζονται
ως checkbox στην properties palette όταν ενεργοποιείται το OSNAP και μπορούν
να ενεργοποιούνται ανεξάρτητα.

### Entities

| Type | Κύριες ιδιότητες | Περιγραφή |
| --- | --- | --- |
| `line` | `x1`, `y1`, `x2`, `y2` | Γραμμή |
| `rect` | `x`, `y`, `w`, `h` | Ορθογώνιο |
| `pline` | `points`, `closed` | Polyline |
| `circle` | `cx`, `cy`, `r` | Κύκλος |
| `arc` | `cx`, `cy`, `r`, `startAzi`, `endAzi` | Τόξο με azimuth radians |
| `ellipse` | `cx`, `cy`, `rx`, `ry` | Έλλειψη |
| `point` | `x`, `y`, `z`, `name`, `showText` | Σημείο και υψόμετρο |
| `text` | `x`, `y`, `text`, `height`, `rotation`, `justify`, `textMode`, `textBox`, `fontFamily` | Κείμενο |
| `dimension` | `kind` και γεωμετρικές ιδιότητες | Απόσταση ή γωνία |
| `dxf-import` | `children`, `labels` | Εισαγόμενο ομαδοποιημένο σχέδιο |

Τα περισσότερα entities έχουν επίσης `color` και `width`.

### Text entity

Το text υποστηρίζει:

- πραγματικό model-space `height`, ανεξάρτητο από zoom,
- αρχικό ύψος 3 mm στο χαρτί μέσω της επιλεγμένης print scale,
- `one-line` και `multiline`,
- εννέα ACAD attachment points:
  `top|middle|bottom` × `left|center|right`,
- συμβατότητα παλιών τιμών `left`, `center`, `right`,
- CAD-friendly fonts: Arial, Arial Narrow, Tahoma, Verdana, Consolas και
  Courier New,
- live ενημέρωση κατά την πληκτρολόγηση,
- δυναμικό περίγραμμα και hit-testing,
- one-line anchor grip στη θέση του justification,
- multiline corners και midpoints grips,
- OSNAP στα όρια του text,
- μετακίνηση grips χωρίς αλλαγή ύψους ή περιεχομένου.

Το `size` δεν χρησιμοποιείται πλέον. Παλιά JSON που το περιέχουν
μετατρέπονται αυτόματα σε `height` και καθαρίζονται κατά την αποθήκευση.

### Διαστάσεις

Οι aligned dimensions εμφανίζουν το μήκος πάνω στη γραμμή διάστασης. Οι angle
dimensions περιλαμβάνουν:

- γωνιακό τόξο και γωνιακή τιμή,
- Ray1 και Ray2 grips,
- μήκος Ray1 και Ray2 πάνω στους νοητούς άξονές τους όταν το αντικείμενο
  είναι επιλεγμένο,
- ιδιότητα `Ray2`, η οποία αλλάζει το μήκος της δεύτερης ακτίνας πάνω στη
  σταθερή διεύθυνσή της.

### JSON και αποθήκευση

Το drawing payload έχει ενδεικτικά τη μορφή:

```json
{
  "entities": [
    {
      "type": "text",
      "x": 10,
      "y": 20,
      "text": "Κείμενο",
      "height": 0.3,
      "textMode": "one-line",
      "justify": "middle-center",
      "fontFamily": "Arial"
    }
  ],
  "angleUnit": "deg",
  "printScale": 100,
  "paperSize": "A3-L"
}
```

Τα ελληνικά αποθηκεύονται ως κανονικό UTF-8 μέσω `JSON_UNESCAPED_UNICODE`.
Τα βασικά API actions είναι `save`, `load`, `list`, `rename`, `presence` και
`export_dxf`.

### DXF export

Το export παράγει AC1021/AutoCAD 2007 DXF και περιλαμβάνει:

- LINE, LWPOLYLINE, CIRCLE, ARC και ELLIPSE,
- POINT και labels,
- MTEXT με ύψος, rotation και attachment point,
- aligned και angular dimensions,
- HATCH boundary loops,
- paper frame, title information και north arrow,
- εισαγόμενα child entities,
- title board από το `pinakidaA4-1.json`, ορισμένο στο DXF ως πραγματικό
  `BLOCK` και τοποθετημένο στο τέλος ως `INSERT` reference, με το ίδιο
  portrait fitting, scale και rotation. Η πινακίδα δεν συμμετέχει στον
  σχηματισμό των ορίων του σχεδίου/κανάβου.

Το DXF είναι το αρχείο ανταλλαγής. Το JSON παραμένει η κύρια μορφή
αποθήκευσης του editor.

### Ασφάλεια και περιορισμοί

- Τα filenames περιορίζονται σε γράμματα, αριθμούς, `_` και `-`.
- Η εφαρμογή είναι file-based και χρειάζεται σωστά filesystem permissions.
- Το `cad_presence.json` είναι ελαφρύ collaboration state, όχι database.
- Η γεωμετρία είναι 2D· το `z` χρησιμοποιείται κυρίως για point elevations.
- Το DXF export εξαρτάται από την υποστήριξη MTEXT/HATCH του importer.

---

## English

### Overview

Web CAD Engine is a self-contained browser-based 2D CAD editor. The current
entry point is [`cad-v10.php`](./cad-v10.php), which carries forward the v9
editor and its CAD interaction model.

The single PHP file contains the server API, HTML/CSS interface, Canvas
renderer and client-side JavaScript editor. Drawings are stored as JSON files
and exported as AutoCAD 2007 (AC1021) ASCII DXF. No database, framework,
package manager or build step is required.

### Running

Requirements:

- PHP with JSON and filesystem functions.
- A modern browser with Canvas, Fetch API, Clipboard API and `localStorage`.
- Write permission for the repository directory.

```bash
php -S localhost:8000
```

Open:

```text
http://localhost:8000/cad-v10.php
```

### Architecture

The application has two cooperating layers:

1. **PHP backend**: drawing selection, JSON save/load, revision tracking,
   drawing listing, rename, presence and DXF generation.
2. **Canvas frontend**: tools, world/screen transforms, rendering, selection,
   hit-testing, grips, OSNAP, commands, undo/redo and properties editing.

Geometry is stored in model/world units. Camera zoom is visual only and never
changes stored geometry.

Scale operates on all selected objects around a snapped base point. The command
then asks directly for a positive numeric scale factor.

### Tools and shortcuts

The editor provides Select, Line, Polyline, Rectangle, Circle, Arc, Ellipse,
Point, Text, Move, Offset, Trim, aligned Dimension, Angle Dimension, Hatch,
contour generation, title-board insertion, JPG capture and DXF export.
The title board is loaded from `pinakidaA4-1.json`, fitted to the active paper
size/orientation and exposes editable `ERGODOTIS`, `ERGO`, `PERIOXI`,
`MELETITIS`, `THEMA_SXEDIOU`, `ARSXED`, `KLIMAKA`, and `XRONOSMELETHS` fields.

It also provides OSNAP, Grid Snap, Ortho, multi-selection, undo/redo, A0–A4
paper frames, portrait/landscape layouts, angle units and configurable print
scales from 1:50 to 1:1000.
OSNAP modes can be enabled independently through checkboxes in the properties
palette when OSNAP is toggled.

| Key | Action |
| --- | --- |
| `F3` | Toggle OSNAP |
| `F8` | Toggle Ortho |
| `M`, `S`, `O`, `T`, `D`, `A`, `H` | Move, Scale, Offset, Trim, Dimension, Angle Dimension, Hatch |
| `Enter` / right click | Finish an open polyline |
| `C` | Close a polyline |
| `Ctrl+A` | Select all |
| `Ctrl+C` / `Ctrl+V` | Copy / paste |
| `Ctrl+Z` / `Ctrl+Y` | Undo / redo |
| `Delete` / `Backspace` | Delete |
| `Escape` | Cancel the active command |

### Entity model

Entities are plain JSON objects. Supported types are `line`, `rect`, `pline`,
`circle`, `arc`, `ellipse`, `point`, `text`, `dimension` and `dxf-import`.
Most entities also contain `color` and `width`.

### Text

Text entities support real model-space height, paper-scale-based creation,
one-line/multiline modes, nine AutoCAD-style attachment points, live editing,
dynamic bounds, active grips, boundary OSNAP and CAD-oriented font choices:
Arial, Arial Narrow, Tahoma, Verdana, Consolas and Courier New.

One-line text exposes one anchor grip at the justification point. Multiline
text exposes corner and midpoint boundary grips. Editing content updates the
canvas, bounds and grips immediately. The legacy `size` property is normalized
to `height` and removed on save.

### Dimensions

Aligned dimensions place their value on the dimension line. Angular dimensions
show the angle arc and value, expose Ray1/Ray2 grips, and display both ray
lengths on their construction axes while selected. The `Ray2` property changes
the second ray length while preserving its direction.

### JSON and collaboration

Drawing files contain an `entities` array plus optional angle-unit, print-scale,
paper-size and paper-frame settings. JSON is written with unescaped UTF-8 so
Greek and other Unicode text remains readable. The backend exposes save, load,
list, rename, presence and DXF export actions.

### DXF export

The exporter produces AutoCAD 2007/AC1021 DXF with geometry, MTEXT, attachment
points, dimensions, hatch boundaries, paper frame, title information, north
arrow and imported child entities. Title boards are defined as DXF `BLOCK`
definitions and appended last as `INSERT` references, with their fitted scale
and rotation, so their geometry and text proportions are preserved without
affecting drawing/grid extents.
JSON remains the editor's primary storage format; DXF is the interoperability
format.

### Limitations

The application is file-based, requires write permissions, and uses
`cad_presence.json` for lightweight collaboration state. Geometry is primarily
2D, with `z` used for point elevations. DXF fidelity depends on the target
importer's MTEXT and HATCH support.

## Versioning

- `cad-v10.php`: current development entry point.
- `cad-v9.php`: preceding stable working snapshot.
- Older `cad-v*.php` files are historical snapshots.
