# Web CAD Engine — `cad-v9.php`

Το `cad-v9.php` είναι ένας αυτοτελής 2D CAD editor για browser. Ένα μόνο PHP
αρχείο περιέχει το server-side API, το HTML/CSS περιβάλλον και όλο το
client-side JavaScript που σχεδιάζει σε HTML5 Canvas. Τα σχέδια αποθηκεύονται
ως JSON στον ίδιο φάκελο και μπορούν να εξαχθούν σε DXF συμβατό με AutoCAD
2007.

## Περιεχόμενα

- [Εκκίνηση](#εκκίνηση)
- [Αρχιτεκτονική](#αρχιτεκτονική)
- [Περιβάλλον εργασίας](#περιβάλλον-εργασίας)
- [Γεωμετρικά αντικείμενα](#γεωμετρικά-αντικείμενα)
- [Εντολές και αλληλεπίδραση](#εντολές-και-αλληλεπίδραση)
- [Hatch και διαστάσεις](#hatch-και-διαστάσεις)
- [Ισοϋψείς και εισαγωγή σημείων](#ισοϋψείς-και-εισαγωγή-σημείων)
- [Αποθήκευση και συνεργασία](#αποθήκευση-και-συνεργασία)
- [Εξαγωγή DXF](#εξαγωγή-dxf)
- [Μορφή JSON](#μορφή-json)
- [Δομή κώδικα](#δομή-κώδικα)
- [Περιορισμοί και ασφάλεια](#περιορισμοί-και-ασφάλεια)

## Εκκίνηση

Απαιτούνται:

- PHP με ενεργοποιημένες τις βασικές συναρτήσεις αρχείων και JSON.
- Σύγχρονος browser με Canvas, Fetch API, Clipboard API και `localStorage`.
- Δικαίωμα εγγραφής στον φάκελο του repository, επειδή το PHP API γράφει
  αρχεία σχεδίων και το `cad_presence.json`.

Από τη ρίζα του repository:

```bash
php -S localhost:8000
```

Άνοιξε το <http://localhost:8000/cad-v9.php>. Το αρχείο που χρησιμοποιείται για
το ενεργό σχέδιο είναι αρχικά το `cad_drawing.json`. Η έκδοση έχει σχεδιαστεί
να λειτουργεί χωρίς database ή build step.

## Αρχιτεκτονική

Το αρχείο εκτελείται σε δύο φάσεις:

1. **PHP πριν από το HTML**
   - Επιλέγει το ενεργό JSON drawing από `POST[file]`, cookie ή το
     `cad_drawing.json`.
   - Επικυρώνει ονόματα αρχείων και nicknames.
   - Υλοποιεί αποθήκευση, φόρτωση, έλεγχο revision, μετονομασία, λίστα σχεδίων,
     παρουσία χρηστών και λήψη DXF.
   - Παράγει ολόκληρο το ASCII DXF 2007 μέσω της `generateDXF2007()`.
2. **HTML/CSS/JavaScript**
   - Δημιουργεί toolbar, Canvas, status bar, properties palette και modal
     εισαγωγής σημείων.
   - Κρατά τα entities στη μεταβλητή `entities`, μετατρέπει screen/world
     coordinates και επανασχεδιάζει με τη `render()`.
   - Χρησιμοποιεί `fetch()` προς το ίδιο URL για το JSON API και αποθηκεύει
     ρυθμίσεις εμφάνισης στο `localStorage`.

Η σχεδίαση είναι 2D. Οι συντεταγμένες αποθηκεύονται σε world units, ενώ τα
σημεία μπορούν επιπλέον να έχουν υψόμετρο `z`.

## Περιβάλλον εργασίας

### Toolbar

- **Select**, **Line**, **Polyline**, **Rectangle**, **Circle**, **Arc**,
  **Ellipse**, **Point**, **Text**.
- **Generate Contours**, **Move**, **Offset**, **Trim**, aligned
  **Dimension**, angle **Dimension**, **Copy selection as JPG** και **Hatch**.
- Μονάδα γωνιών: μοίρες (`deg`), grads (`grad`) ή radians (`rad`).
- Μέγεθος πλαισίου εκτύπωσης: A0–A4, portrait ή landscape.
- Κλίμακα DXF: 1:50, 1:100, 1:200, 1:500 ή 1:1000.
- Χρώμα και πάχος γραμμής.
- **OSNAP**, **Grid Snap** και **Ortho**.
- Όνομα drawing, **Save**, **Rename**, επιλογή άλλου drawing, **Undo**,
  **Redo**, εισαγωγή ελληνικής πινακίδας και **Export to DXF (2007)**.

Η δεξιά properties palette είναι μεταβαλλόμενου πλάτους και μπορεί να
συμπτυχθεί. Παρουσιάζει κοινές ιδιότητες (χρώμα και πάχος) και πεδία ειδικά
για το επιλεγμένο entity. Η θέση της κάμερας, το πλάτος/η σύμπτυξη του panel,
η μονάδα γωνιών, η κλίμακα, το χαρτί και η θέση του βορρά παραμένουν τοπικά
στο browser.

### Πλοήγηση και shortcuts

- Μεσαίο πλήκτρο ή `Alt` + drag: pan.
- Διπλό middle-click: zoom στα extents.
- Mouse wheel: zoom γύρω από τον δείκτη.
- `F3`: ενεργοποίηση/απενεργοποίηση OSNAP.
- `F8`: ενεργοποίηση/απενεργοποίηση Ortho.
- `M`, `O`, `T`, `D`, `A`, `H`: Move, Offset, Trim, Dimension, Angle
  Dimension και Hatch.
- `Enter` ή δεξί click ολοκληρώνει ανοικτή polyline. `C` την κλείνει.
- `Ctrl+A`: επιλογή όλων, `Ctrl+C`/`Ctrl+V`: αντιγραφή/τοποθέτηση.
- `Ctrl+Z` και `Ctrl+Y` (ή `Ctrl+Shift+Z`): Undo/Redo.
- `Delete` ή `Backspace`: διαγραφή entity ή hatch. `Escape`: ακύρωση
  ενεργής εντολής.

Το OSNAP αναζητά endpoints, midpoints, centers, quadrants, intersections,
perpendicular, tangent και nearest σημεία. Το Grid Snap στρογγυλοποιεί σε
βήμα 10 world units. Το Ortho περιορίζει τη νέα ευθυγράμμιση στον οριζόντιο ή
κατακόρυφο άξονα.

## Γεωμετρικά αντικείμενα

Τα entities είναι απλά associative JSON objects:

| `type` | Βασικές ιδιότητες | Παρατήρηση |
| --- | --- | --- |
| `line` | `x1`, `y1`, `x2`, `y2` | Ευθύγραμμο τμήμα |
| `rect` | `x`, `y`, `w`, `h` | Ορθογώνιο |
| `pline` | `points: [{x,y}]`, `closed` | Ανοικτή ή κλειστή polyline |
| `circle` | `cx`, `cy`, `r` | Κύκλος |
| `arc` | `cx`, `cy`, `r`, `startAzi`, `endAzi` | Γωνίες σε azimuth radians |
| `ellipse` | `cx`, `cy`, `rx`, `ry` | Έλλειψη |
| `point` | `x`, `y`, `z`, `name`, `showText` | Σημείο με υψόμετρο |
| `text` | `x`, `y`, `text`, `height`, `rotation`, `justify` | Κείμενο |
| `dimension` | `kind`, γεωμετρικές συντεταγμένες | Απόσταση ή γωνία |
| `dxf-import` | `children`, `labels` | Ομαδοποιημένη πινακίδα |

Τα περισσότερα σχεδιαζόμενα entities έχουν επίσης `color` και `width`.
Οι grips επιτρέπουν stretch σε κορυφές/άκρα, αλλαγή radius ή μετακίνηση
κέντρου. Η properties palette επιτρέπει ακριβέστερη αριθμητική επεξεργασία.

Η **ΠΙΝΑΚΙΔΑ** εισάγει ένα `dxf-import` object με ορθογώνια, γραμμές και labels.
Το πρότυπο κλιμακώνεται στο επιλεγμένο A-size και τοποθετείται με ένα click.
Το **Text** εισάγει αρχικά το κείμενο `TEXT`, το οποίο αλλάζει από την palette.

## Εντολές και αλληλεπίδραση

Η κατάσταση του editor περιλαμβάνει active tool, selection set, προσωρινά
preview entities, grips, snap marker, pan/zoom camera και command objects.
Οι βασικές εντολές είναι:

- **Move**: μετακινεί ένα ή πολλά επιλεγμένα entities με base/target point.
- **Offset**: δημιουργεί offset γραμμής, polyline ή καμπύλου αντικειμένου,
  με απόσταση που θυμάται το `localStorage`.
- **Trim**: κόβει line, arc ή segment polyline στο επιλεγμένο σημείο.
- **Dimension**: δημιουργεί aligned distance dimension με offset και
  παραμετροποιήσιμα δεκαδικά.
- **Angle Dimension**: δημιουργεί γωνιακή διάσταση με sweep, radius, mode και
  τιμή σε deg/grad/rad.
- **Copy/Paste**: αντιγράφει deep-cloned entities και δείχνει preview πριν
  την τοποθέτηση.
- **JPG capture**: drag σε περιοχή του Canvas, χωρίς να περιλαμβάνεται το
  πλαίσιο επιλογής. Αν αποτύχει το clipboard, γίνεται download
  `cad-selection.jpg`.

Το history αποθηκεύει snapshots του πίνακα entities και επιτρέπει έως 1000
καταστάσεις (`MAX_HISTORY`). Κάθε αλλαγή ενεργοποιεί debounced auto-save
με καθυστέρηση 400 ms.

## Hatch και διαστάσεις

Το Hatch εφαρμόζεται σε line, polyline, rectangle, circle ή ellipse. Μετά την
επιλογή του αντικειμένου ζητά απόσταση offset και click στην πλευρά:

- line/open polyline: left ή right,
- κλειστό αντικείμενο: inside ή outside.

Το hatch αποθηκεύεται ως `hatch` object με `pattern`, `distance`, `spacing`,
`angle`, `sideSign`, `color` και `width`. Για polylines υπολογίζεται κοινό
mitered offset boundary και ξεχωριστές λωρίδες ανά segment, ώστε οι κορυφές να
μην δημιουργούν επικαλύψεις. Το ίδιο boundary χρησιμοποιείται τόσο στο Canvas
όσο και στο DXF.

Οι διαστάσεις εμφανίζονται με βοηθητικά markers, κείμενο και wipeout πίσω από
το label. Η απόσταση υπολογίζεται από τα δύο άκρα, ενώ η γωνιακή διάσταση
υποστηρίζει αλλαγή mode και επεξεργασία της τιμής από την palette.

## Ισοϋψείς και εισαγωγή σημείων

Το **Generate Contours**:

1. χρησιμοποιεί υπάρχοντα `point` entities ή εισάγει νέα σημεία,
2. δέχεται `X,Y`, `X,Y,Z`, `P,X,Y` ή `P,X,Y,Z` με comma ή tab,
3. απαιτεί τουλάχιστον τρία σημεία με έγκυρα X, Y και Z,
4. δημιουργεί Delaunay triangulation με Bowyer–Watson,
5. τέμνει κάθε τρίγωνο με στάθμες ανά το ζητούμενο interval,
6. ενώνει τα τμήματα ανά στάθμη σε polylines και γράφει το υψόμετρο,
7. αντικαθιστά μόνο entities με `generatedBy: "contours"`.

Το προεπιλεγμένο interval είναι 1 m, αλλά επιτρέπεται οποιαδήποτε θετική
τιμή. Άκυρες γραμμές εισαγωγής αναφέρονται χωρίς να σταματά η επεξεργασία των
έγκυρων γραμμών.

## Αποθήκευση και συνεργασία

### JSON API

Όλα τα requests είναι `POST` στο τρέχον pathname και χρησιμοποιούν `action`:

| Action | Είσοδος | Αποτέλεσμα |
| --- | --- | --- |
| `save` | `file`, `data` | Γράφει pretty-printed JSON με `LOCK_EX` |
| `load` | `file` | Επιστρέφει drawing και revisions |
| `check` | `file` | Επιστρέφει μόνο revisions |
| `list` | — | Επιστρέφει όλα τα επιτρεπτά `.json` drawings |
| `rename` | `file`, `newFile` | Μετονομάζει χωρίς overwrite |
| `presence` | `file`, `clientId`, `nickname` | Ενημερώνει ενεργούς χρήστες |
| `export_dxf` | `file`, `data`, `printScale` | Κατεβάζει DXF |

Το `revision` αποτελείται από modification time και μέγεθος αρχείου. Το
`entityRevision` είναι SHA-256 του JSON των entities και χρησιμοποιείται για
να εντοπίζονται αλλαγές από άλλον client. Ο browser ελέγχει περιοδικά για
remote changes και φορτώνει τη νεότερη έκδοση όταν δεν υπάρχουν τοπικές
εκκρεμείς αλλαγές.

Τα drawing names επιτρέπουν μόνο ASCII γράμματα, αριθμούς, `-` και `_`, με
προαιρετικό `.json`. Το ενεργό όνομα κρατιέται σε HttpOnly cookie. Η παρουσία
γράφεται με αποκλειστικό file lock και οι χρήστες που δεν έχουν ενημερωθεί για
15 δευτερόλεπτα αφαιρούνται. Το nickname επιτρέπει Unicode letters/numbers,
κενά, `-`, `_` και μήκος 1–24 χαρακτήρες· δύο ενεργοί χρήστες δεν μπορούν να
έχουν το ίδιο nickname στο ίδιο drawing.

### Αποθήκευση στον browser

Το `localStorage` κρατά μόνο ρυθμίσεις client, όπως camera, panel,
nickname, angle unit, paper size, print scale, offset distance, paper frame και
north arrow. Τα γεωμετρικά δεδομένα παραμένουν στο JSON αρχείο του server.

## Εξαγωγή DXF

Η `generateDXF2007()` δημιουργεί πλήρες ASCII DXF με:

- `$ACADVER = AC1021` (AutoCAD 2007),
- metric units, meters, decimal notation και τέσσερα δεκαδικά στις
  συντεταγμένες,
- `ANSI_1253` code page και υποστήριξη Unicode labels ως `\U+XXXX`,
- layer `0`, `STANDARD` text style και `STANDARD` DimStyle,
- `_ARCHTICK` block για dimension markers,
- paper frame A0–A4, model/paper space layouts και north arrow,
- ACI χρώμα 1–9 από το πλησιέστερο HEX χρώμα,
- `HATCH`, `MTEXT`, `WIPEOUT` και blocks για διαστάσεις όπου απαιτείται.

Η αντιστοίχιση είναι:

| Editor | DXF |
| --- | --- |
| line | `LINE` |
| rect | closed `LWPOLYLINE` |
| pline | `LWPOLYLINE` |
| circle | `CIRCLE` |
| arc | `ARC` |
| ellipse | `ELLIPSE` |
| point | `POINT` και label `MTEXT` |
| text | `MTEXT` |
| distance dimension | block reference/geometry, markers και `MTEXT` |
| angle dimension | arc, lines και `MTEXT` |
| hatch | `HATCH` με boundary loops |
| dxf-import | τα child geometry και labels |

Το πλαίσιο χαρτιού κεντράρεται στα drawing bounds όταν υπάρχουν entities.
Η κλίμακα επηρεάζει το μέγεθος κειμένου και το πλαίσιο. Το DXF κατεβαίνει με
το βασικό όνομα του ενεργού drawing και κατάληξη `.dxf`.

## Μορφή JSON

Το ελάχιστο drawing είναι:

```json
{
  "entities": [
    {
      "type": "point",
      "name": "P1",
      "x": 100,
      "y": 200,
      "z": 12.5,
      "color": "#ffffff",
      "width": 2
    }
  ]
}
```

Προαιρετικά metadata όπως `angleUnit`, `paperSize`, `printScale`,
`paperFrameCenterX` και `paperFrameCenterY` μεταφέρονται στην εξαγωγή DXF.
Τα renamed drawings είναι ανεξάρτητα `.json` αρχεία στον φάκελο της εφαρμογής.

## Δομή κώδικα

Οι κύριες περιοχές του [cad-v9.php](cad-v9.php) είναι:

| Γραμμές | Περιοχή |
| --- | --- |
| 1–239 | filename/presence validation, bounds, paper και hatch helpers |
| 241–1714 | DXF 2007 generator, tables, blocks και entities |
| 1715–1867 | POST JSON API και headers για DXF download |
| 1869–2274 | HTML, CSS, toolbar, Canvas, palette και contour modal |
| 2275–2633 | client state, αριθμητικά helpers, persistence και presence |
| 2634–4460 | history, camera, snapping, grips και editing commands |
| 4461–5633 | hatch geometry, render pipeline και contour generation |
| 5634–6547 | properties palette και entity-specific editors |
| 6549–7934 | pointer/keyboard events, import, save/load, sync και startup |

## Περιορισμοί και ασφάλεια

- Δεν υπάρχει authentication, authorization ή ιδιωτικό drawing ανά χρήστη.
  Όποιος έχει πρόσβαση στον PHP server μπορεί να δει/τροποποιήσει τα JSON.
- Η συνεργασία είναι file-based και last-write-wins· δεν υπάρχει merge ή
  database transaction μεταξύ διαφορετικών drawings.
- Το PHP process πρέπει να μπορεί να γράψει στον φάκελο. Σε production
  χρειάζονται web-server permissions, HTTPS και κατάλληλοι περιορισμοί πρόσβασης.
- Η εφαρμογή είναι 2D και ο DXF exporter χειρίζεται το υποσύνολο entities που
  αναφέρεται παραπάνω. Άγνωστο entity type δεν γράφεται στο DXF.
- Η εξαγωγή δημιουργείται από το ενεργό POST payload και δεν αποθηκεύει
  αυτόματα αλλαγές που δεν έχουν ήδη σταλεί με save.

## English summary

`cad-v9.php` is a single-file browser-based 2D CAD editor. It combines a PHP
JSON/file API, an HTML5 Canvas frontend, entity editing tools, snapping,
grips, hatching, dimensions, point-based contour generation, collaborative
presence, JSON persistence and AutoCAD 2007 (`AC1021`) DXF export. See the
Greek sections above for the complete implementation-oriented description.
