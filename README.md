# Web CAD Engine

A small web-based 2D CAD editor implemented in a single PHP file. `cad.php`
serves the HTML/CSS/JavaScript interface, stores the drawing as JSON, and
generates an AutoCAD 2007-compatible DXF file.

## Running

PHP with its built-in web server is required:

```bash
php -S localhost:8000
```

Then open [http://localhost:8000/cad.php](http://localhost:8000/cad.php) in a
modern browser. The web server must have permission to write to
`cad_drawing.json`, because the drawing is stored there.

## What `cad.php` Does

### Drawing Environment

The following tools are supported:

- `Select`: select an entity and view or edit its properties.
- `Line`: create a line with two clicks.
- `Polyline (PL)`: create vertices with consecutive clicks. `Enter` or a right click finishes an open polyline, while `C` finishes a closed polyline.
- `Rectangle`: create a rectangle with a corner click and a second click.
- `Circle`: set the center with one click and the radius with a second click.
- `Arc`: set the center, start point, and end point with three clicks.
- `Ellipse`: set the center with one click and the two radii with a second click.
- `Point`: create a point with one click.
- `Generate 1 m Contours`: build contour polylines at 1 m elevation intervals
  from the available points using their X, Y, and Z values. Re-running the
  command replaces only the previously generated contour polylines.

Drawing uses clicks as the primary creation method rather than drag gestures.
Pan the viewport with the middle mouse button or `Alt`, and zoom with the mouse
wheel.

### Drawing Aids

- **OSNAP**: snaps to endpoints, midpoints, centers, quadrants, intersections,
  perpendicular points, and nearby points on geometry. Toggle it with the
  checkbox or `F3`.
- **Grid Snap**: optional snapping to a 10-unit grid.
- **Ortho**: constrains movement to horizontal or vertical directions with
  `F8`.
- Angle units can be set to `Degrees (360°)` or `Grads (400g)`. The selection is
  stored in the browser's `localStorage` and in the drawing JSON.

## Selection and Editing

With `Select`, choose an entity and edit its color, line width, and geometric
properties in the properties palette. Depending on the entity type, the palette
also displays length, area, perimeter, radius, azimuth, vertices, and polyline
angles. Grips support moving, stretching, and changing radii or ellipse axes.

Points have editable `Name`, `Position X`, `Position Y`, and `Elevation Z`
properties. Each point is displayed on the canvas as `name:elevation`.
Generated contour polylines use the interpolated elevation as their polyline
elevation property.

`Undo`/`Redo` and the `Ctrl+Z`/`Ctrl+Y` shortcuts retain up to 50 states.
`Delete` or `Backspace` removes the selected entity, while `Clear` empties the
entire drawing.

## Storage

Every change triggers a delayed auto-save and sends a POST request to the same
`cad.php` file. The drawing is written to [cad_drawing.json](cad_drawing.json)
and loaded automatically when the page opens.

The file format is:

```json
{
  "angleUnit": "deg",
  "entities": [
    {
      "type": "point",
      "name": "P1",
      "x": 100,
      "y": 200,
      "z": 12.5
    }
  ]
}
```

Storage is shared by all clients using the same server. There is no
authentication or separate drawing per user.

## DXF Export

The `Export to DXF (2007)` button sends the current entities to PHP and
downloads `drawing_2007.dxf`. The generator creates an `AC1021` header, metric
and decimal settings, a `STANDARD` DimStyle, and an architectural tick block.
Entity coordinates are written with four decimal places of precision.

Entity mapping:

| Editor entity | DXF entity |
| --- | --- |
| Line | `LINE` |
| Rectangle | closed `LWPOLYLINE` |
| Polyline | `LWPOLYLINE` |
| Circle | `CIRCLE` |
| Arc | `ARC` |
| Ellipse | `ELLIPSE` |
| Point | `POINT` |

HEX colors from the editor are converted to the nearest basic AutoCAD Color
Index (ACI 1-9). No separate DXF layer is stored per entity; exported entities
are written to layer `0`.

## Files

```text
cad.php           # PHP backend and complete frontend
cad_drawing.json  # Local, shared storage for the current drawing
README.md         # Documentation
```

The interface combines Greek messages with English tool and property labels.
There is no separate language switch.
