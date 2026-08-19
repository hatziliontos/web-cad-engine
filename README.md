# web-cad-engine

A complete **Web-based CAD (Computer-Aided Design) engine** that runs in your browser. Draw, edit, and export professional CAD drawings with AutoCAD 2007 (AC1021) DXF format support.

## Features

### Drawing Tools
- **Select**: Select and manipulate objects
- **Line**: Draw straight lines between two points
- **Polyline (PL)**: Create multi-segment lines with vertices
- **Rectangle**: Draw rectangular shapes with width and height
- **Circle**: Create circles with center point and radius
- **Arc**: Draw arcs with customizable center, radius, and angles
- **Ellipse**: Create elliptical shapes
- **Point**: Mark individual points on the canvas

### Core Functionality
- **AutoCAD DXF Export**: Export drawings as AutoCAD 2007 (AC1021) format files with full DimStyle compliance
- **Color Management**: Automatic conversion from HEX colors to AutoCAD Color Index (ACI)
- **Measurement Units**: Support for both degrees (°) and gradians as angle units
- **Precision**: All coordinates stored with 4 decimal places for accuracy
- **Object Properties**: Full access to modify entity properties (coordinates, dimensions, colors)

### User Interface
- **Dark Theme**: Professional VS Code-inspired dark interface
- **Toolbar**: Quick access to all drawing tools
- **Properties Palette**: Real-time editing of selected object properties
- **Status Bar**: Display current mode, coordinates, and snap information
- **Toast Notifications**: Contextual feedback in Greek and English

### Data Management
- **Auto-Save**: Automatic saving to `cad_drawing.json`
- **Load/Save**: Persist drawings between sessions
- **Undo/Redo**: Full history tracking with undo and redo functionality
- **Clear All**: Reset the canvas and start fresh

### Drawing Aids
- **Object Snapping (OSNAP)**: Snap to endpoints, midpoints, and centers
- **Coordinate Display**: Real-time XY coordinate tracking
- **Entity Selection**: Click to select objects and view their properties
- **Vertex Editing**: Modify individual vertices in polylines

## Architecture

### Backend (PHP)
- Handles data persistence with JSON storage
- Generates AutoCAD DXF files with proper header and dimension style settings
- Supports metric units (meters) and decimal format
- Creates STANDARD DimStyle with architectural tick arrow blocks

### Frontend (HTML5/JavaScript)
- Canvas-based drawing engine
- Event-driven architecture for tool interactions
- Real-time property synchronization
- Multi-language support (Greek/English)

## File Structure
```
├── cad.php              # Main PHP backend + HTML/CSS/JS frontend
├── cad_drawing.json     # Persistent drawing storage
└── README.md            # This file
```

## Technical Details

### DXF Export Specifications
- **Format**: AutoCAD 2007 (AC1021)
- **Units**: Meters (6 - INSUNITS)
- **Linear Precision**: 3 decimal places (LUPREC)
- **Angular Precision**: 4 decimal places (AUPREC)
- **Default Color System**: AutoCAD Color Index (ACI)
- **Arrow Style**: Architectural Tick (_ARCHTICK)

### Supported Entity Types
- LWPOLYLINE (for lines, rectangles, polylines, and complex shapes)
- CIRCLE (for circular shapes)
- ARC (for arc segments)
- POINT (for point entities)

### Color Mapping
Automatic conversion from HEX colors to the nearest standard AutoCAD color (ACI 1-9):
- Red, Yellow, Green, Cyan, Blue, Magenta, White, Gray, Light Gray

## Browser Compatibility
- Chrome/Chromium
- Firefox
- Safari
- Edge
- Any modern browser with HTML5 Canvas and Fetch API support

## Usage

1. **Open in Browser**: Load `cad.php` in a web browser
2. **Select a Tool**: Click on a tool button in the toolbar
3. **Draw**: Click and drag on the canvas to create shapes
4. **Edit**: Select objects to modify their properties in the palette
5. **Export**: Click Export to download your drawing as a DXF file
6. **Save**: The drawing auto-saves to `cad_drawing.json`

## Language
The interface supports both **Greek** and **English** with full localization for messages, tooltips, and dialogs.
