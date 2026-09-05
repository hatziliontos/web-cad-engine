# Web CAD Engine

## Ελληνικά

### Τι είναι

Το Web CAD Engine είναι ένας αυτοτελής 2D CAD editor που εκτελείται σε
σύγχρονο browser. Η εφαρμογή υλοποιείται στο [cad-v11.php](/workspaces/web-cad-engine/cad-v11.php)
και περιλαμβάνει στο ίδιο αρχείο:

- PHP backend για αποθήκευση και φόρτωση σχεδίων.
- HTML/CSS interface με toolbar, Canvas, status bar και properties palette.
- JavaScript μηχανή σχεδίασης, επιλογής, snapping, grips, commands και undo/redo.
- Παραγωγή AutoCAD 2007/AC1021 ASCII DXF.

Δεν απαιτείται database, framework, package manager ή build process. Τα σχέδια
αποθηκεύονται ως JSON αρχεία στον ίδιο φάκελο της εφαρμογής.

### Εκκίνηση

Απαιτούνται PHP με υποστήριξη JSON και filesystem functions, καθώς και browser
με Canvas, Fetch API, Clipboard API και `localStorage`.

```bash
php -S localhost:8000
```

Άνοιγμα:

```text
http://localhost:8000/cad-v11.php
```

Ο φάκελος πρέπει να είναι εγγράψιμος, επειδή η εφαρμογή δημιουργεί ή
ενημερώνει JSON σχέδια και το `cad_presence.json`.

### Περιβάλλον εργασίας

Το interface περιλαμβάνει:

- toolbar για τα εργαλεία σχεδίασης και επεξεργασίας,
- κεντρικό Canvas για το σχέδιο,
- properties palette για τις ιδιότητες του επιλεγμένου entity,
- επιλογές χαρτιού, κλίμακας εκτύπωσης και γωνιακών μονάδων,
- status bar για ενεργό εργαλείο, OSNAP, συντεταγμένες και κατάσταση αποθήκευσης.

Οι συντεταγμένες αποθηκεύονται σε model/world units. Το zoom και η μετατόπιση
της κάμερας επηρεάζουν μόνο την προβολή και όχι τη γεωμετρία.

### Εργαλεία σχεδίασης

Υποστηρίζονται:

- Select και πολλαπλή επιλογή.
- Line.
- Polyline με κλείσιμο, επεξεργασία κορυφών και αντιστροφή κατεύθυνσης.
- Rectangle.
- Circle.
- Arc με ακτίνες και azimuth.
- Ellipse.
- Point με όνομα, υψόμετρο `z` και εμφάνιση label.
- Text.
- Aligned Dimension.
- Angular Dimension.
- Hatch.
- Generate Contours από υψομετρικά σημεία.
- Εισαγωγή title board.

### Εργαλεία επεξεργασίας

- Move.
- Scale πολλαπλών επιλεγμένων entities γύρω από snap base point με απευθείας
  εισαγωγή θετικού scale factor.
- Offset.
- Trim.
- Διαγραφή.
- Undo και redo.
- Αντιγραφή και επικόλληση.

### Πλοήγηση και βοηθήματα σχεδίασης

Υπάρχουν Grid Snap, Ortho και OSNAP. Τα OSNAP modes ενεργοποιούνται ανεξάρτητα
και περιλαμβάνουν:

- Endpoint
- Midpoint
- Center
- Quadrant
- Intersection
- Perpendicular
- Tangent
- Nearest

Το OSNAP εμφανίζει snap preview/highlight όταν ο δείκτης πλησιάζει διαθέσιμο
σημείο. Οι ρυθμίσεις OSNAP αποθηκεύονται τοπικά στον browser.

### Συντομεύσεις

| Πλήκτρο | Ενέργεια |
| --- | --- |
| `F3` | Ενεργοποίηση/απενεργοποίηση OSNAP |
| `F8` | Ενεργοποίηση/απενεργοποίηση Ortho |
| `M` | Move |
| `S` | Scale |
| `O` | Offset |
| `T` | Trim |
| `D` | Aligned Dimension |
| `A` | Angular Dimension |
| `H` | Hatch |
| `Enter` ή δεξί click | Ολοκλήρωση ανοιχτής polyline |
| `C` | Κλείσιμο polyline |
| `Ctrl+A` | Επιλογή όλων |
| `Ctrl+C` / `Ctrl+V` | Αντιγραφή / επικόλληση |
| `Ctrl+Z` / `Ctrl+Y` | Undo / redo |
| `Delete` / `Backspace` | Διαγραφή |
| `Escape` | Ακύρωση ενεργού command |

### Entities

Τα entities είναι plain JSON objects. Οι βασικοί τύποι είναι:

| Type | Περιεχόμενο |
| --- | --- |
| `line` | Δύο endpoints `x1`, `y1`, `x2`, `y2` |
| `rect` | Γωνία, width και height |
| `pline` | Λίστα κορυφών και κατάσταση closed |
| `circle` | Center και radius |
| `arc` | Center, radius, start/end azimuth σε radians |
| `ellipse` | Center και ακτίνες `rx`, `ry` |
| `point` | `x`, `y`, `z`, name και label visibility |
| `text` | Περιεχόμενο, height, rotation, mode, font και justification |
| `dimension` | Aligned ή angular dimension |
| `dxf-import` | Ομαδοποιημένο entity με child entities |

Τα περισσότερα entities διαθέτουν επίσης `color` και `width`.

### Text

Κάθε text entity υποστηρίζει:

- model-space height,
- one-line ή multiline mode,
- εννέα θέσεις justification,
- rotation,
- γραμματοσειρές Arial, Arial Narrow, Tahoma, Verdana, Consolas και
  Courier New,
- live αλλαγή περιεχομένου,
- grips και δυναμικά bounds,
- OSNAP στα όρια του text.

Τα one-line texts έχουν anchor grip στη θέση justification. Τα multiline texts
έχουν grips στις γωνίες και στα midpoints του text box.

### Διαστάσεις και contours

Οι aligned dimensions εμφανίζουν το μήκος πάνω στη dimension line και διαθέτουν
ιδιότητα Placement για τοποθέτηση Above ή Below του νοητού άξονα. Οι angular
dimensions εμφανίζουν τόξο, γωνιακή τιμή, Ray1 και Ray2 grips, καθώς και τα
μήκη των construction rays. Η ιδιότητα Ray2 αλλάζει το μήκος της δεύτερης
ακτίνας χωρίς να αλλάζει τη διεύθυνσή της.

Το Generate Contours δημιουργεί γραμμές ισοϋψών από point entities που διαθέτουν
υψόμετρο.

### Title board

Η title board εισάγεται από το [pinakidaA4-1.json](/workspaces/web-cad-engine/pinakidaA4-1.json)
και προσαρμόζεται στο ενεργό paper size και print scale. Εισάγεται portrait και
μπορεί να περιστραφεί γύρω από το rotation center της.

Τα editable πεδία είναι:

`ERGODOTIS`, `ERGO`, `PERIOXI`, `MELETITIS`, `THEMA_SXEDIOU`, `ARSXED`,
`KLIMAKA`, `XRONOSMELETHS`.

Για κάθε πεδίο υπάρχουν ξεχωριστά:

- περιεχόμενο,
- ύψος,
- γραμματοσειρά,
- one-line/multiline mode,
- justification,
- collapsible property section.

Η κατάσταση open/collapsed κάθε πεδίου αποθηκεύεται στο `localStorage` και
επαναφέρεται όταν ξανανοίγει η palette ή γίνεται reload. Τα πεδία παραμένουν
child text entities της title board και έτσι ενημερώνονται σε Canvas, αποθήκευση
και DXF export.

### Paper frame και μονάδες

Το paper frame υποστηρίζει A0, A1, A2, A3 και A4 σε portrait ή landscape.
Υποστηρίζονται print scales 1:50, 1:100, 1:200, 1:500 και 1:1000.

Οι angular units είναι:

- degrees,
- grads,
- radians.

Οι γωνίες αποθηκεύονται εσωτερικά σε radians, ενώ εμφανίζονται σύμφωνα με την
επιλεγμένη μονάδα.

### Αποθήκευση και συνεργασία

Τα drawing JSON περιέχουν το array `entities` και μπορούν να περιέχουν:

- `angleUnit`,
- `printScale`,
- `paperSize`,
- paper-frame center,
- camera view state.

Το backend υποστηρίζει τα actions:

- `save`,
- `load`,
- `check`,
- `list`,
- `rename`,
- `presence`,
- `export_dxf`.

Η παρουσία χρηστών αποθηκεύεται στο `cad_presence.json` και χρησιμοποιείται για
ελαφριά ένδειξη ενεργών χρηστών ανά σχέδιο. Δεν αποτελεί database ή σύστημα
version control.

### DXF export

Η εξαγωγή παράγει ASCII DXF AutoCAD 2007/AC1021 και περιλαμβάνει:

- LINE,
- LWPOLYLINE,
- CIRCLE,
- ARC,
- ELLIPSE,
- POINT,
- TEXT για one-line text,
- MTEXT για multiline text,
- aligned και angular dimensions,
- hatch boundaries,
- paper frame,
- title information,
- north arrow,
- imported child entities.

Η title board εξάγεται ως πραγματικό DXF `BLOCK` definition και προστίθεται
στο τέλος ως `INSERT` block reference. Η θέση, η περιστροφή και οι αναλογίες
της διατηρούνται. Η title board δεν συμμετέχει στον υπολογισμό των ορίων του
κύριου σχεδίου και του κανάβου.

Τα ελληνικά text μεταφέρονται με AutoCAD Unicode escape sequences, ώστε το DXF
να παραμένει ASCII-safe και να μην καταλήγουν σε ερωτηματικά κατά την ανάγνωση.

### Περιορισμοί

- Η εφαρμογή είναι file-based και χρειάζεται δικαιώματα εγγραφής.
- Η γεωμετρία είναι 2D. Το `z` χρησιμοποιείται κυρίως για point elevations.
- Η πιστότητα του DXF εξαρτάται από τον importer που θα το ανοίξει.
- Η title-board template file εξαιρείται από τη λίστα σχεδίων.

---

## English

### Overview

Web CAD Engine is a self-contained browser-based 2D CAD editor implemented in
[cad-v11.php](/workspaces/web-cad-engine/cad-v11.php). The single PHP file
contains the backend API, HTML/CSS interface, Canvas renderer, editing tools,
properties palette and DXF exporter.

Drawings are stored as JSON files. No database, framework, package manager or
build step is required.

### Running

Requirements:

- PHP with JSON and filesystem support.
- A modern browser with Canvas, Fetch API, Clipboard API and `localStorage`.
- Write permission for the application directory.

```bash
php -S localhost:8000
```

Open `http://localhost:8000/cad-v11.php`.

### Editor capabilities

The editor provides Select, Line, Polyline, Rectangle, Circle, Arc, Ellipse,
Point, Text, Move, Scale, Offset, Trim, aligned dimensions, angular dimensions,
Hatch, contour generation and title-board insertion.

Scale operates on multiple selected entities around a snapped base point and
uses a direct positive numeric scale factor. Geometry is stored in model/world
units; camera zoom and pan only affect the view.

OSNAP supports Endpoint, Midpoint, Center, Quadrant, Intersection,
Perpendicular, Tangent and Nearest. Modes are independently configurable and
the active snap is highlighted near the cursor. Grid Snap and Ortho are also
available.

### Text and dimensions

Text entities support model-space height, one-line/multiline mode, rotation,
nine justification positions, live editing, grips, text bounds, boundary OSNAP
and Arial, Arial Narrow, Tahoma, Verdana, Consolas or Courier New.

Aligned dimensions display measured length. Angular dimensions display the angle
arc, value, Ray1/Ray2 grips and construction-ray lengths. Ray2 can be changed
without changing its direction.

### Title board

The title board is loaded from [pinakidaA4-1.json](/workspaces/web-cad-engine/pinakidaA4-1.json),
fitted to the active paper size and print scale, inserted in portrait
orientation and rotatable around its rotation center.

Its editable fields are `ERGODOTIS`, `ERGO`, `PERIOXI`, `MELETITIS`,
`THEMA_SXEDIOU`, `ARSXED`, `KLIMAKA` and `XRONOSMELETHS`.

Every field has independent content, height, font, one-line/multiline mode,
justification and collapsible properties. The open/collapsed state is persisted
in `localStorage`. Field settings are stored on the child text entities and are
used by the Canvas renderer, JSON persistence and DXF export.

### Storage and API

Drawing JSON contains an `entities` array and optional angle-unit, print-scale,
paper-size, paper-frame and camera settings. The backend exposes `save`, `load`,
`check`, `list`, `rename`, `presence` and `export_dxf` actions.

Presence information is stored in `cad_presence.json` as lightweight
per-drawing collaboration state.

### DXF export

The exporter produces AutoCAD 2007/AC1021 ASCII DXF with geometry, dimensions,
hatch boundaries, paper frame, title information, north arrow and imported
entities. One-line text is exported as `TEXT`; multiline text is exported as
`MTEXT`.

The title board is defined as a real DXF `BLOCK` and appended as an `INSERT`
block reference. Its fitted proportions, insertion point and rotation are
preserved, while it is excluded from the main drawing/grid extents.

Greek text is emitted using AutoCAD Unicode escape sequences so the DXF remains
ASCII-safe and can be read without replacing Greek characters with question
marks.

### Limitations

The application is file-based and requires write permissions. Geometry is
primarily 2D, with `z` used mainly for point elevations. DXF fidelity depends
on the target CAD importer.
