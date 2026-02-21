# Gallery Widget Plugin

Ein WordPress-Plugin zur Anzeige von Bildergalerien mit REST-API-Integration und lokalem Bild-Caching.

## Features

- 🖼️ **Gutenberg-Block** für einfache Integration in Beiträge und Seiten
- 🔗 **REST-API Integration** mit konfigurierbarer Base URL
- 💾 **Lokales Bild-Caching** - Bilder von S3 werden lokal gecacht und ausgeliefert
- 📅 **Mehrfachauswahl** von Dates und Collections
- 🎨 **Responsive Design** mit anpassbarer Spaltenanzahl (1-6 Spalten)
- 🔍 **Lightbox-Funktion** mit Tastaturnavigation
- ⚡ **Lazy Loading** für optimale Performance

## Installation

1. Laden Sie den `gallery-widget` Ordner in das `/wp-content/plugins/` Verzeichnis hoch
2. Aktivieren Sie das Plugin über das 'Plugins' Menü in WordPress
3. Konfigurieren Sie die Base URL unter **Einstellungen → MediaHUB Gallerie**

## Konfiguration

### Base URL einrichten

1. Navigieren Sie zu **Einstellungen → MediaHUB Gallerie**
2. Geben Sie die Base URL Ihrer REST-API ein (z.B. `https://example.com`)
3. Speichern Sie die Einstellungen

### Cache-Einstellungen

Das Plugin cached automatisch alle Bilder von S3 lokal im WordPress-Upload-Verzeichnis unter `/wp-content/uploads/gallery-cache/`:

- **Caching aktivieren**: Ein/Aus-Schalter für das gesamte Caching-System (Standard: aktiviert)
- **Thumbnails sofort cachen**: Thumbnails werden synchron heruntergeladen und gecacht (Standard: aktiviert)
  - Wenn aktiviert: Thumbnails sind sofort nach dem API-Request verfügbar (bessere UX)
  - Wenn deaktiviert: Thumbnails werden asynchron im Hintergrund gecacht
- **Cache-Dauer**: Legen Sie fest, wie lange Bilder gecacht werden (Standard: 7 Tage)
- **Cache leeren**: Löschen Sie alle gecachten Bilder mit einem Klick
- **Cache-Statistik**: Sehen Sie, wie viele Dateien gecacht sind und wie viel Speicher verwendet wird

#### Caching-Strategie

Das Plugin nutzt eine intelligente Caching-Strategie:

1. **Thumbnails** (Standard: synchron)
   - Werden sofort heruntergeladen und gecacht
   - Sorgt für schnelle Galerieansicht
   - Timeout-Schutz: Fallback auf S3-URL wenn Download fehlschlägt

2. **Vollbilder** (asynchron)
   - Werden im Hintergrund heruntergeladen
   - Blockiert nicht den Seitenaufbau
   - Werden bei nächstem Request von local geserved

#### Vorteile des Cachings

1. **Schnellere Ladezeiten**: Bilder werden vom lokalen Server statt von S3 geladen
2. **Reduzierte Kosten**: Weniger Traffic auf S3
3. **Bessere Performance**: Keine Abhängigkeit von externer S3-Verfügbarkeit
4. **Browser-Caching**: Optimierte Cache-Header für lange Browser-Cache-Zeiten
5. **Robustheit**: Fallback-Mechanismus bei Download-Fehlern

### API-Endpunkte

Das Plugin nutzt folgende Endpunkte:

- `BASEURL/api/public/dates` - Liste aller Galerien nach Datum
- `BASEURL/api/public/collections` - Liste aller manuell erstellten Collections
- `BASEURL/api/public/images?date=2025-09-28` - Bilder für ein bestimmtes Datum
- `BASEURL/api/public/images?collection=collection-id` - Bilder für eine Collection

## Verwendung

### Block einfügen

1. Öffnen Sie einen Beitrag oder eine Seite im Block-Editor
2. Suchen Sie nach "Galerie Widget" und fügen Sie den Block hinzu
3. In der Seitenleiste können Sie:
   - **Dates auswählen**: Wählen Sie ein oder mehrere Daten aus
   - **Collections auswählen**: Wählen Sie eine oder mehrere Collections aus
   - **Spalten**: Legen Sie die Anzahl der Spalten fest (1-6)
   - **Titel anzeigen**: Aktivieren/Deaktivieren Sie die Galerieüberschrift

### Block-Einstellungen

- **Spalten** (1-6): Bestimmt das Grid-Layout der Galerie
- **Titel anzeigen**: Zeigt oder verbirgt die Galerieüberschrift
- **Dates**: Mehrfachauswahl von Datums-Galerien
- **Collections**: Mehrfachauswahl von Collections

## Lightbox-Navigation

Die Galerie verfügt über eine integrierte Lightbox mit folgenden Funktionen:

- **Klick auf Bild**: Öffnet die Lightbox
- **← →**: Navigation zwischen Bildern (Pfeiltasten oder Buttons)
- **ESC**: Schließt die Lightbox
- **Klick außerhalb**: Schließt die Lightbox

## API-Response Format

Das Plugin erwartet folgende Datenformate:

### Dates Endpunkt
```json
[
  "2025-09-28",
  "2025-09-27",
  "2025-09-26"
]
```

### Collections Endpunkt
```json
[
  {
    "id": "collection-1",
    "name": "Urlaubsfotos"
  },
  {
    "id": "collection-2",
    "name": "Events"
  }
]
```

### Images Endpunkt
```json
[
  {
    "url": "https://example.com/image1.jpg",
    "thumbnail": "https://example.com/image1-thumb.jpg",
    "title": "Bild 1",
    "alt": "Beschreibung"
  }
]
```

## Anpassungen

### CSS-Anpassungen

Sie können das Styling über Ihr Theme überschreiben:

```css
.gallery-widget-grid {
  gap: 20px; /* Abstand zwischen Bildern */
}

.gallery-widget-item {
  border-radius: 12px; /* Abgerundete Ecken */
}
```

### JavaScript-Hooks

Das Plugin stellt folgende Events bereit:

```javascript
document.addEventListener('galleryWidgetLoaded', function(e) {
  console.log('Galerie geladen:', e.detail);
});
```

## Kompatibilität

- WordPress: 5.8+
- PHP: 7.4+
- Browser: Moderne Browser (Chrome, Firefox, Safari, Edge)

## Support

Bei Fragen oder Problemen wenden Sie sich bitte an den Plugin-Entwickler.

## Changelog

### Version 1.0.0
- Initiales Release
- Gutenberg-Block Integration
- REST-API Support
- Lightbox-Funktion
- Responsive Design

## Lizenz

GPL v2 or later
