# Spezifikation: BlockKnowledgeBase (BKB) Hierarchische Knowledge-, Task- und Projekt-Web-App

Stand: 25. Juli 2026

## 1. Zielbild

Die Anwendung soll eine persönliche beziehungsweise kleine kollaborative Arbeitsplattform werden, die Elemente aus folgenden Bereichen verbindet:

- Wiki-basierte Knowledge Base (ähnlich Wikipedia, Wikimedia, Confluence, oneNote, Notion, Slite, ggf SharePoint)
- Aufgabenverwaltung
- leichtgewichtiges Projektmanagement
- blockbasierter Seiteneditor
- API-first-Anbindung
- Integration von ESP32- und E-Ink-Geräten
- Sprachaufnahme mit STT- und LLM-Verarbeitung
- Freigaben und gemeinsames Bearbeiten
- vollständige Seitenversionierung
- bewusst mächtige HTML- und JavaScript-Makros

Die Anwendung soll möglichst einfach selbst hostbar sein und zunächst überwiegend mit folgenden Technologien umgesetzt werden:

- Vanilla PHP
- Vanilla JavaScript
- HTML und CSS
- JSON-Dateien als Datenspeicher
- keine Datenbank in Version 1
- externe Bibliotheken nur dann, wenn sie einen klaren, nicht sinnvoll selbst abbildbaren Nutzen bringen

Die Architektur soll bewusst so gestaltet sein, dass später eine Migration auf eine Datenbank oder andere Speichermechanismen möglich bleibt.

---

# 2. Grundprinzipien

## 2.1 Alles ist eine Seite

Es gibt keine separaten Ordnerobjekte.

Jeder Eintrag in der Hierarchie ist eine Seite. Eine Seite kann:

- eigene Inhalte besitzen,
- nur als Hierarchiepunkt dienen,
- Unterseiten enthalten,
- Aufgaben enthalten,
- dynamische Abfragen enthalten,
- Dateianhänge enthalten,
- als Projektübersicht dienen,
- über eine API angesprochen werden,
- für Geräte oder externe Benutzer freigegeben werden.

Beispiel:

```text
Privater Bereich
├── Inbox
├── Wohnung
│   ├── Übersicht
│   ├── Elektrik
│   ├── Küche
│   └── Badezimmer
├── ESP32-Projekte
│   ├── Wetterstation
│   ├── Complainy
│   └── E-Ink-Anzeige
└── Wissen
    ├── Linux
    ├── PHP
    └── Netzwerk
```

Technisch ist jeder dieser Einträge eine Seite.

Eine Seite kann leer sein und trotzdem Unterseiten enthalten. Dadurch entfällt die Unterscheidung zwischen Ordnern und Dokumenten.

---

## 2.2 Seiten bestehen aus Blöcken

Der Inhalt einer Seite wird nicht als einzelnes HTML-Dokument gespeichert, sondern als geordnete Liste strukturierter Blöcke.

Beispiel:

```text
Seite
├── Überschrift
├── Raw-Text
├── Markdown
├── Aufgabe
├── Dateianhang
├── Aufklappbarer Bereich
│   ├── Codeblock
│   └── Aufgabe
└── Inhaltsverzeichnis
```

Jeder Block besitzt:

- eine stabile Block-ID,
- einen Blocktyp,
- Inhalte,
- Einstellungen,
- optional Kindblöcke,
- Metadaten.

Die stabile Block-ID ist wichtig für:

- Versionierung,
- Diffs,
- API-Zugriffe,
- Geräteintegration,
- Referenzen,
- Verschieben von Blöcken,
- spätere kollaborative Bearbeitung.

---

## 2.3 Aufgaben sind Blöcke

Aufgaben sind keine vollständig getrennte Objektwelt, sondern strukturierte Blöcke innerhalb einer Seite.

Eine Aufgabe:

- gehört logisch zu einer Seite,
- besitzt eine stabile Block-ID,
- kann per API gefiltert werden,
- kann über ein ESP32-Gerät gelesen werden,
- kann über ein Gerät oder eine Spracheingabe hinzugefügt werden,
- kann in Query-Blöcken gesammelt dargestellt werden,
- kann global oder innerhalb eines Seitenbaums gesucht werden.

Beispiel:

```json
{
  "id": "block_task_4711",
  "type": "task",
  "content": "Temperatursensor montieren",
  "settings": {
    "completed": false,
    "priority": 8,
    "dueAt": "2026-07-30T18:00:00+02:00",
    "labels": ["hardware", "esp32"]
  },
  "meta": {
    "createdAt": "2026-07-25T10:00:00+02:00",
    "createdBy": "user_michael",
    "completedAt": null,
    "completedBy": null
  }
}
```

---

## 2.4 API-first

Die Weboberfläche ist nur eine mögliche Ansicht auf die Daten.

Seiten und Blöcke sollen von Beginn an über klar definierte Endpunkte erreichbar sein.

Beispiele:

```http
GET /api/v1/pages/102
GET /api/v1/pages/102/blocks
GET /api/v1/pages/102/blocks?type=task
GET /api/v1/pages/102/blocks?type=task&completed=false
GET /api/v1/pages/102/blocks?type=task&completed=false&sort=-priority&limit=1
```

Für einfache Geräte können zusätzliche vereinfachte Endpunkte angeboten werden:

```http
GET /api/device/latest-task
POST /api/device/task
```

Ein Gerät kann fest einer Seite zugeordnet werden. Dadurch muss das Gerät die Seiten-ID nicht immer selbst mitsenden.

---

# 3. Seitenmodell

Eine Seite besitzt mindestens:

```json
{
  "schemaVersion": 1,
  "id": "page_102",
  "parentPageId": "page_10",
  "title": "Wetterstation",
  "slug": "wetterstation",
  "revision": 12,
  "draftRevision": 4,
  "createdAt": "2026-07-18T00:00:00+02:00",
  "createdBy": "user_michael",
  "updatedAt": "2026-07-25T10:00:00+02:00",
  "updatedBy": "user_michael",
  "labels": ["esp32", "projekt"],
  "blocks": []
}
```

## 3.1 Seitentitel

Der Seitentitel ist kein normaler Heading-Block.

Er gehört zu den Seitenmetadaten, weil er für folgende Funktionen verwendet wird:

- Navigation,
- Hierarchie,
- Breadcrumbs,
- Suche,
- URLs,
- API,
- Freigaben,
- Versionshistorie,
- Browser-Titel,
- spätere `show_page`-Referenzen.

Im Editor darf der Seitentitel trotzdem optisch wie ein oberster Block wirken.

---

## 3.2 Seitenhierarchie

Die Hierarchie entsteht über:

```json
{
  "parentPageId": "page_10"
}
```

Jede Seite darf Unterseiten besitzen.

Die Reihenfolge der Unterseiten kann entweder:

- über eine Reihenfolgenliste im Elternobjekt,
- über ein eigenes Sortierfeld,
- oder über eine separate Indexdatei

gespeichert werden.

Für Version 1 ist eine einfache manuelle Sortierung ausreichend.

---

# 4. Block-Grundmodell

Ein allgemeiner Block kann so aussehen:

```json
{
  "id": "block_abc123",
  "type": "markdown",
  "content": "Der Sensor wird über **I²C** angeschlossen.",
  "settings": {},
  "meta": {
    "createdAt": "2026-07-25T10:00:00+02:00",
    "createdBy": "user_michael",
    "updatedAt": "2026-07-25T10:05:00+02:00",
    "updatedBy": "user_michael"
  }
}
```

Containerblöcke besitzen zusätzlich:

```json
{
  "children": []
}
```

Die Reihenfolge der Blöcke entspricht zunächst der Reihenfolge im JSON-Array.

Ein separates `position`-Feld ist in Version 1 nicht zwingend erforderlich.

---

# 5. Blocktypen in Version 1

Folgende Blocktypen gehören in Version 1:

- `heading`
- `raw_text`
- `markdown`
- `toc`
- `page_tree`
- `task`
- `query`
- `attachment`
- `code`
- `callout`
- `divider`
- `bookmark`
- `expand`
- `trusted_html`
- `sandbox_html`

Später:

- `table`
- `show_page`
- `columns`

---

# 6. Definitionen der Version-1-Blöcke

## 6.1 `heading`

Eine Überschrift innerhalb der Seite.

Der Seitentitel selbst ist kein Heading-Block.

Beispiel:

```json
{
  "id": "block_heading_1",
  "type": "heading",
  "content": "Aufbau der Wetterstation",
  "settings": {
    "level": 1,
    "includeInToc": true,
    "anchor": null
  }
}
```

Mögliche Level:

- H1
- H2
- H3
- H4
- H5
- H6

Optionen:

- Level
- im Inhaltsverzeichnis anzeigen
- automatisch erzeugter oder manueller Anker

---

## 6.2 `raw_text`

Reiner Text ohne Interpretation.

Es gibt:

- keine Formatierung,
- keine Links,
- keine Listen,
- keine Markdown-Auswertung,
- keine HTML-Auswertung,
- keine typografische Ersetzung,
- keine automatische Strukturierung.

Der Inhalt soll möglichst exakt so dargestellt werden, wie er gespeichert wurde.

Beispiel:

```json
{
  "id": "block_raw_1",
  "type": "raw_text",
  "content": "Sensor montieren\nKabel prüfen\nGehäuse schließen",
  "settings": {
    "wrap": true
  }
}
```

Darstellung im Browser:

```css
white-space: pre-wrap;
```

Optional ohne automatischen Umbruch:

```css
white-space: pre;
```

Der Block ist insbesondere für folgende Fälle gedacht:

- Embedded Devices,
- E-Ink-Displays,
- API-Ausgaben,
- technisch exakte Textdarstellung,
- einfache Maschinenverarbeitung.

Optionaler Endpunkt:

```http
GET /api/v1/pages/102/blocks/block_raw_1/content
Accept: text/plain
```

---

## 6.3 `markdown`

Ein Markdown-Block.

Markdown ist die kanonische Quelle. Die gerenderte HTML-Ausgabe wird daraus erzeugt.

Beispiel:

```json
{
  "id": "block_md_1",
  "type": "markdown",
  "content": "Der Sensor wird über **I²C** angeschlossen.\n\n- ESP32\n- BME280",
  "settings": {
    "editorMode": "split"
  }
}
```

Editor-Modi:

- `raw`
- `split`
- `preview`

Im Editor:

```text
Bearbeiten | Geteilt | Vorschau
```

### Raw

Nur Markdown-Quelltext.

### Split

Links Markdown-Quelltext, rechts Vorschau.

### Preview

Nur gerenderte Ansicht.

Eine kleine Werkzeugleiste kann den Quelltext manipulieren:

- fett
- kursiv
- Link
- Liste
- Zitat
- Inline-Code
- Codeblock

Markdown bleibt trotzdem die einzige gespeicherte Quelle.

Ein vollständig eigener visueller Rich-Text-Editor soll in Version 1 nicht gebaut werden, weil verlustfreie Konvertierung zwischen HTML und Markdown komplex ist.

Rohes HTML innerhalb von Markdown sollte deaktiviert sein. Dafür existieren `trusted_html` und `sandbox_html`.

---

## 6.4 `toc`

Ein automatisch erzeugtes Inhaltsverzeichnis aus den Heading-Blöcken der aktuellen Seite.

Beispiel:

```json
{
  "id": "block_toc_1",
  "type": "toc",
  "content": null,
  "settings": {
    "minLevel": 1,
    "maxLevel": 3,
    "numbered": false
  }
}
```

Optionen:

- minimale Ebene
- maximale Ebene
- nummeriert oder unnummeriert
- optional Einrückung

---

## 6.5 `page_tree`

Zeigt einen Ausschnitt aus der Seitenhierarchie.

Beispiel:

```json
{
  "id": "block_tree_1",
  "type": "page_tree",
  "content": null,
  "settings": {
    "rootPageId": "page_102",
    "includeRoot": false,
    "maxDepth": 3,
    "showEmptyPages": true,
    "sort": "manual"
  }
}
```

Optionen:

- Wurzelseite
- Wurzelseite selbst anzeigen
- maximale Tiefe
- leere Seiten anzeigen
- Sortierung

---

## 6.6 `task`

Strukturierte Aufgabe innerhalb einer Seite.

Beispiel:

```json
{
  "id": "block_task_1",
  "type": "task",
  "content": "Gehäuse fertigstellen",
  "settings": {
    "completed": false,
    "priority": 7,
    "dueAt": null,
    "labels": [],
    "description": null
  },
  "meta": {
    "createdAt": "2026-07-25T10:00:00+02:00",
    "createdBy": "user_michael",
    "completedAt": null,
    "completedBy": null
  }
}
```

Mindestens:

- Titel
- erledigt oder offen
- Priorität
- Fälligkeitsdatum
- Labels
- optionale kurze Beschreibung

Serverseitig gepflegt:

- Erstellungszeit
- Ersteller
- Erledigungszeit
- erledigt von

Der Task-Block ist in Version 1 kein Containerblock.

---

## 6.7 `query`

Dynamische Abfrage auf Seiten oder Blöcke.

Beispiel:

```json
{
  "id": "block_query_1",
  "type": "query",
  "content": null,
  "settings": {
    "entity": "block",
    "scope": {
      "pageId": "page_102",
      "includeSubpages": true
    },
    "filter": {
      "type": "task",
      "completed": false
    },
    "sort": [
      {
        "field": "priority",
        "direction": "desc"
      }
    ],
    "display": "list",
    "limit": 10
  }
}
```

Mögliche Anwendungsfälle:

- offene Aufgaben dieser Seite
- offene Aufgaben aus Unterseiten
- wichtigste Aufgabe
- zuletzt geänderte Seiten
- neue Anhänge
- Seiten mit bestimmtem Label

Der Query-Block verbindet:

- Seitenstruktur,
- Aufgaben,
- API,
- Geräte,
- dynamische Ansichten.

---

## 6.8 `attachment`

Ein Dateianhang.

Unterstützte Darstellungen:

- `inline`
- `preview`
- `card`
- `link`

Beispiel:

```json
{
  "id": "block_attachment_1",
  "type": "attachment",
  "content": {
    "assetVersionId": "assetver_123"
  },
  "settings": {
    "display": "preview",
    "caption": "Aufbau auf dem Breadboard",
    "alt": "ESP32 mit angeschlossenem Sensor",
    "width": null,
    "height": null
  }
}
```

Darstellung nach MIME-Type:

- Bild: Bildanzeige
- PDF: Vorschau oder Link
- Audio: Player
- Video: Player
- Textdatei: Vorschau
- sonstige Datei: Downloadkarte oder Link

Die Binärdatei wird nicht direkt im Seiten-JSON gespeichert.

Details zum Asset-System stehen im Kapitel Versionierung und Anhänge.

---

## 6.9 `code`

Codeblock.

Beispiel:

```json
{
  "id": "block_code_1",
  "type": "code",
  "content": "digitalWrite(4, HIGH);",
  "settings": {
    "language": "cpp",
    "showLineNumbers": true,
    "wrap": false
  }
}
```

Optionen:

- Sprache
- Zeilennummern
- Zeilenumbruch
- optional Dateiname oder Titel

Syntax-Highlighting ist optional und kann später ergänzt werden.

---

## 6.10 `callout`

Hinweisbox.

Typen:

- Info
- Warnung
- Erfolg
- Fehler
- Idee

Beispiel:

```json
{
  "id": "block_callout_1",
  "type": "callout",
  "content": null,
  "settings": {
    "style": "warning",
    "title": "Achtung",
    "icon": "warning"
  },
  "children": []
}
```

Der Callout darf Kindblöcke enthalten.

Dadurch können innerhalb einer Hinweisbox liegen:

- Text
- Markdown
- Aufgabe
- Code
- Anhang

---

## 6.11 `divider`

Einfache Trennlinie.

Beispiel:

```json
{
  "id": "block_divider_1",
  "type": "divider",
  "content": null,
  "settings": {
    "style": "line"
  }
}
```

---

## 6.12 `bookmark`

Externer Link oder Linkkarte.

Beispiel:

```json
{
  "id": "block_bookmark_1",
  "type": "bookmark",
  "content": {
    "url": "https://example.org"
  },
  "settings": {
    "display": "card",
    "title": null,
    "description": null,
    "fetchMetadata": false
  }
}
```

Darstellungsarten:

- einfacher Link
- Karte
- Vorschau

Für Version 1 kann das automatische Abrufen von Metadaten zunächst deaktiviert sein.

---

## 6.13 `expand`

Aufklappbarer Bereich und Containerblock.

Beispiel:

```json
{
  "id": "block_expand_1",
  "type": "expand",
  "content": null,
  "settings": {
    "title": "Technische Details",
    "defaultDisplay": "collapsed"
  },
  "children": [
    {
      "id": "block_raw_2",
      "type": "raw_text",
      "content": "GPIO 4 = SDA",
      "settings": {
        "wrap": true
      }
    }
  ]
}
```

Mögliche Werte:

- `collapsed`
- `expanded`

Wichtig:

Der Zustand im Editor ist nicht zwingend identisch mit der Standardanzeige im Lesemodus.

Ein Bereich kann im Lesemodus standardmäßig eingeklappt sein, aber im Editor offen bleiben.

Das Öffnen oder Schließen im Editor erzeugt keine neue Seitenversion, solange es nur ein lokaler Editorzustand ist.

---

## 6.14 `trusted_html`

Bewusst unsicherer HTML-, CSS- und JavaScript-Block.

Er wird vollständig im Hauptfenster und im DOM der Anwendung ausgeführt.

Er ist funktional gespeichertes XSS, wird aber als bewusstes Administratorwerkzeug akzeptiert.

Beispiel:

```json
{
  "id": "block_trusted_1",
  "type": "trusted_html",
  "content": {
    "html": "<div id=\"result\"></div>",
    "css": "#result { padding: 1rem; }",
    "javascript": "document.querySelector('#result').textContent = 'Hallo';"
  },
  "settings": {
    "executeIn": [
      "internal"
    ]
  }
}
```

Regeln:

- nur Administratoren dürfen den Block erstellen,
- nur Administratoren dürfen ihn bearbeiten,
- Gäste dürfen ihn nicht verändern,
- Geräte dürfen ihn nicht erstellen,
- jede Änderung erzeugt sofort eine eigenständige Revision,
- in historischen Versionen nicht automatisch ausführen,
- in externen Freigaben standardmäßig nicht ausführen,
- im Editor standardmäßig erst nach Klick auf „Ausführen“ starten,
- im internen Lesemodus darf er automatisch ausgeführt werden.

Mögliche Ausführungsbereiche:

- `internal`
- `share_read`
- `share_edit`
- `history`

Standard:

```json
{
  "executeIn": ["internal"]
}
```

Administratoren mit Bearbeitungsrecht für Trusted HTML sind technisch so vertrauenswürdig wie Personen mit Zugriff auf den Anwendungscode.

---

## 6.15 `sandbox_html`

Isolierter HTML-, CSS- und JavaScript-Block.

Er läuft in einem Iframe.

Beispiel:

```json
{
  "id": "block_sandbox_1",
  "type": "sandbox_html",
  "content": {
    "html": "<button id=\"btn\">Klick</button>",
    "css": "button { font-size: 2rem; }",
    "javascript": "btn.onclick = () => btn.textContent = 'Hallo';"
  },
  "settings": {
    "profile": "offline",
    "autoRun": false,
    "height": "300px"
  }
}
```

Grundregeln:

- lädt nie automatisch für Gäste,
- zeigt zunächst Quellcode oder eine Vorschau,
- wird erst nach Klick auf „Ausführen“ in ein echtes Iframe umgewandelt,
- `autoRun` bleibt für Gäste serverseitig immer `false`,
- möglichst eigene Sandbox-Origin,
- standardmäßig kein Netzwerkzugriff,
- keine Cookies der Hauptanwendung,
- keine direkte DOM-Verbindung zur Hauptanwendung.

Minimaler Iframe:

```html
<iframe
  sandbox="allow-scripts"
  referrerpolicy="no-referrer"
  loading="lazy">
</iframe>
```

Die Kombination aus `allow-scripts` und `allow-same-origin` soll vermieden werden.

Mögliche Profile:

### Offline

- JavaScript erlaubt
- kein Netzwerk
- keine Formulare
- keine Downloads
- keine Pop-ups
- keine Navigation des Hauptfensters

### Eingeschränktes Netzwerk

- Netzwerkzugriff nur auf freigegebene Domains
- nur durch Administratoren freischaltbar

### Externe Einbettung

- nur freigegebene externe Iframe-Quellen
- kein frei eingegebener Hauptfenster-Code

Später kann eine kontrollierte `postMessage`-Brücke ergänzt werden.

---

# 7. Spätere Blocktypen

## 7.1 `table`

Einfache Tabelle.

Für die erste spätere Version:

- Kopfzeile optional
- einfache Text- oder Markdown-Zellen
- Spaltenbreiten
- keine Formeln
- keine verschachtelten Blöcke
- keine komplexen Datenbankfunktionen

---

## 7.2 `show_page`

Bindet eine andere Seite über eine Referenz ein.

Beispiel:

```json
{
  "id": "block_showpage_1",
  "type": "show_page",
  "content": {
    "pageId": "page_123"
  },
  "settings": {
    "defaultDisplay": "collapsed",
    "width": "full",
    "height": "auto",
    "versionMode": "current",
    "pinnedRevisionId": null
  }
}
```

Wichtige Regeln:

- keine Endlosschleifen,
- maximale Einbettungstiefe,
- Berechtigungen der Zielseite prüfen,
- bei fehlender Berechtigung weder Titel noch Pfad verraten,
- Freigaben dürfen keine nicht freigegebenen Seiten enthüllen,
- gepinnte Freigaben müssen auch eingebettete Seiten auf konkrete Versionen pinnen können.

---

## 7.3 `columns`

Mehrspaltiger Layoutcontainer.

Beispiel:

```text
┌─────────────────────┬─────────────────────┐
│ Aufgaben            │ Dokumentation       │
│                     │                     │
└─────────────────────┴─────────────────────┘
```

Er enthält pro Spalte eigene Kindblöcke.

Dieser Block wird erst später umgesetzt, da verschachteltes Drag-and-drop und responsive Darstellung zusätzlichen Aufwand erzeugen.

---

# 8. Editor-GUI

## 8.1 Grundidee

Der Editor ist ein blockbasierter Struktur-Editor.

Jeder Block wird als eigene visuelle Einheit dargestellt.

Beispiel:

```text
┌──────────────────────────────────────────────────────────────────┐
│ Projekte / ESP32 / Wetterstation                                 │
│ Wetterstation                  Automatisch gespeichert 11:42      │
│                         [Vorschau] [Version speichern] [⋯]        │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  [Move] [↑] [↓]  Überschrift · H1                         [−] [⋯] │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │ Aufbau der Wetterstation                                  │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                  │
│                              [+]                                 │
│                                                                  │
│  [Move] [↑] [↓]  Markdown                                [−] [⋯] │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │ Bearbeiten | Geteilt | Vorschau                           │  │
│  │                                                            │  │
│  │ Der Sensor wird über **I²C** angeschlossen.               │  │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

---

## 8.2 Feste Bedienelemente pro Block

In der Blockkopfzeile sollen immer sichtbar sein:

Links beziehungsweise vor der Typbezeichnung:

1. Move-Schaltfläche oder Drag-Handle
2. Pfeil nach oben
3. Pfeil nach unten

Rechts:

4. Minimieren oder Maximieren
5. Drei-Punkte-Menü

Reihenfolge:

```text
[Move] [↑] [↓]  Blocktyp und Kurzinfo                    [−] [⋯]
```

Die Move-Schaltfläche startet das Verschieben.

Nur die Move-Schaltfläche soll Drag-and-drop auslösen. Das verhindert, dass Textauswahl oder Interaktion in Formularfeldern versehentlich einen Block verschiebt.

Die Pfeile sind immer sichtbar und dienen als:

- zuverlässige Alternative zu Drag-and-drop,
- Touch-Alternative,
- barriereärmere Bedienung,
- schnelle Sortierung bei langen Blöcken.

---

## 8.3 Minimieren von Blöcken

Jeder Block soll im Editor minimiert werden können.

Im minimierten Zustand wird nur die Kopfzeile angezeigt.

Beispiel:

```text
[Move] [↑] [↓]  Markdown · 4.281 Zeichen                 [+] [⋯]
```

Oder:

```text
[Move] [↑] [↓]  Dateianhang · schaltplan.pdf             [+] [⋯]
```

Der Editor-Minizustand ist zunächst nur UI-Zustand und keine Inhaltsänderung.

Er darf daher:

- keine neue Revision erzeugen,
- nicht zwingend im Seiten-JSON gespeichert werden,
- optional pro Benutzer lokal gespeichert werden.

Mögliche Speicherung:

- Local Storage
- Session Storage
- Benutzeroberflächenstatusdatei

Der Minimieren-Button sollte zwischen zwei Zuständen wechseln:

- `−` oder ein Pfeil zum Einklappen
- `+` oder ein Pfeil zum Ausklappen

---

## 8.4 Drei-Punkte-Menü

Das Menü enthält:

```text
Einstellungen
In neuem Fenster oder Tab bearbeiten
Duplizieren
Ausschneiden
Löschen
```

Optional:

```text
Block-ID kopieren
Als JSON anzeigen
```

Nicht mehr im Drei-Punkte-Menü:

- Nach oben
- Nach unten

Diese Aktionen sind fest in der Blockkopfzeile sichtbar.

Für Containerblöcke zusätzlich:

```text
Block hineinverschieben
Block herausverschieben
```

Nach dem Löschen:

```text
Block gelöscht. [Rückgängig]
```

---

## 8.5 Einzelblock in neuem Fenster oder Tab bearbeiten

Jeder Block kann über:

```text
In neuem Fenster oder Tab bearbeiten
```

in einer separaten Browseransicht geöffnet werden.

Beispielroute:

```text
/editor/page/page_102/block/block_md_1
```

Die Ansicht zeigt ausschließlich:

- Seitenname
- Blocktyp
- Blockinhalt
- Blockeinstellungen
- Speichstatus
- Zurück-zur-Seite-Link

Beispiel:

```text
Wetterstation / Markdown-Block

[Bearbeiten] [Geteilt] [Vorschau]

┌──────────────────────────────────────────────────────────────┐
│                                                              │
│                  großer Blockeditor                          │
│                                                              │
└──────────────────────────────────────────────────────────────┘

Automatisch gespeichert 11:48
[Zur Seite] [Version speichern]
```

Vorteile:

- große Markdown-Blöcke angenehmer bearbeiten,
- kein langes Scrollen innerhalb der Gesamtseite,
- große Codeblöcke besser bearbeiten,
- Trusted HTML getrennt testen,
- Dateianhänge oder Metadaten übersichtlicher bearbeiten.

Der Einzelblockeditor bearbeitet denselben Block und keine Kopie.

Er muss dieselbe Konflikterkennung und Autosave-Logik verwenden wie der Seiteneditor.

---

## 8.6 Plus-Schaltflächen zwischen Blöcken

Zwischen jedem Block gibt es eine Plus-Schaltfläche.

Beim Klick öffnet sich eine Blockauswahl:

```text
Text
- Überschrift
- Raw Text
- Markdown
- Code

Struktur
- Inhaltsverzeichnis
- Seitenbaum
- Aufklappbarer Bereich
- Trennlinie
- Hinweisbox

Inhalte
- Aufgabe
- Dateianhang
- Bookmark
- Abfrage

Erweitert
- Trusted HTML
- Sandbox HTML
```

Zusätzlich:

```text
Block suchen …
```

Später kann ein Slash-Menü ergänzt werden:

```text
/task
/md
/code
/html
```

---

## 8.7 Drag-and-drop

Native HTML5-Drag-and-drop reicht für Version 1 auf Desktop.

Zunächst unterstützt:

- Sortieren auf derselben Ebene,
- Verschieben in einen `expand`-Block,
- Verschieben aus einem `expand`-Block,
- Sortieren innerhalb eines `expand`-Blocks.

Beim Ziehen erscheinen klare Einfügelinien:

```text
──── Block hier ablegen ────
```

Noch nicht in Version 1:

- beliebig tiefe komplexe Verschachtelung,
- Mehrfachauswahl mehrerer Blöcke,
- Drag-and-drop über mehrere Browserfenster,
- komplexes mobiles Touch-Dragging.

---

## 8.8 Blockeinstellungen

Beim Anklicken eines Blocks kann rechts eine Einstellungsleiste erscheinen.

Beispiel:

```text
┌───────────────────────────────┐
│ Markdown                      │
│                               │
│ Editor-Modus                  │
│ [Geteilt ▼]                   │
│                               │
│ Block-ID                      │
│ block_md_1                    │
│                               │
│ [Duplizieren]                 │
│ [Löschen]                     │
└───────────────────────────────┘
```

Auf kleinen Bildschirmen kann die Leiste als Dialog oder Bottom Sheet erscheinen.

---

# 9. Markdown-Editor

Version 1 verwendet keinen vollständig selbst entwickelten WYSIWYG-Editor.

Stattdessen:

```text
Bearbeiten | Geteilt | Vorschau
```

Die Markdown-Quelle bleibt maßgeblich.

## 9.1 Werkzeugleiste

Eine kleine Toolbar darf Quelltextoperationen anbieten:

- Fett
- Kursiv
- Link
- Aufzählung
- nummerierte Liste
- Zitat
- Inline-Code
- Codeblock

Beispiel:

Text markieren und Fett drücken:

```text
Text
```

wird zu:

```markdown
**Text**
```

---

## 9.2 Große Markdown-Blöcke

Da große Markdown-Blöcke in einer langen Seite unübersichtlich werden, stehen folgende Funktionen zur Verfügung:

- Block minimieren,
- Vorschau statt Quelltext,
- geteilte Ansicht,
- in neuem Fenster oder Tab bearbeiten.

Der Editor darf im Seitenmodus eine begrenzte Höhe verwenden, solange der Block jederzeit maximiert oder separat geöffnet werden kann.

---

# 10. Autosave, Entwürfe und Versionen

## 10.1 Drei Zustände

Es gibt drei unterschiedliche Konzepte:

### Arbeitsstand

Aktueller Inhalt im Browser.

### Automatischer Entwurf

Ein regelmäßig gespeicherter, überschreibbarer Snapshot.

### Version

Ein unveränderlicher Eintrag in der Versionshistorie.

---

## 10.2 Dateistruktur

Beispiel:

```text
data/
└── pages/
    └── page_102/
        ├── page.json
        ├── autosave.json
        ├── autosave.previous.json
        └── versions/
            ├── 000001.json
            ├── 000002.json
            └── 000003.json
```

Bedeutung:

- `page.json`: aktuell veröffentlichter beziehungsweise gültiger Stand
- `autosave.json`: letzter automatischer Entwurf
- `autosave.previous.json`: optionale technische Sicherung
- `versions/*.json`: unveränderliche historische Versionen

---

## 10.3 Autosave-Verhalten

Autosave soll nur erfolgen, wenn Änderungen existieren.

Empfehlung:

1. Änderung markiert die Seite als `dirty`.
2. Zwei Sekunden nach der letzten Eingabe wird gespeichert.
3. Spätestens 15 Sekunden nach der ersten ungespeicherten Änderung wird gespeichert.
4. Weitere Änderungen überschreiben denselben automatischen Entwurf.
5. Ohne Änderung findet kein Request statt.

UI-Zustände:

```text
Nicht gespeichert
Wird gespeichert …
Automatisch gespeichert um 11:52
Speichern fehlgeschlagen – erneut versuchen
```

Der automatische Entwurf wird immer überschrieben.

Es entstehen nicht bei jedem Autosave neue historische Dateien.

---

## 10.4 Entwurf als Subversion

Der automatische Entwurf kann in der Versionsansicht als temporäre Subversion erscheinen:

```text
Version 12
18. Juli 2026, 00:12 · Michael

Entwurf nach Version 12
Automatisch gespeichert um 11:52 · Michael
Noch nicht als dauerhafte Version gespeichert
```

Intern beispielsweise:

```text
12-draft
```

Im UI besser:

```text
Entwurf nach Version 12
```

---

## 10.5 Version speichern

Eine dauerhafte Version wird bewusst gespeichert.

Button:

```text
Version speichern
```

Optional mit Änderungsbeschreibung:

```text
Änderungsbeschreibung:
[ Verdrahtungsplan und Aufgaben ergänzt ]

[Version speichern]
```

Beim Speichern:

1. Browserzustand validieren,
2. unveränderliche Versionsdatei erzeugen,
3. `page.json` aktualisieren,
4. Versionsnummer erhöhen,
5. Autosave entfernen oder als übernommen markieren,
6. Autor und Zeitpunkt erfassen.

---

## 10.6 Versionierung durch API und Geräte

Auch Änderungen über:

- API,
- ESP32,
- E-Ink-Geräte,
- Sprachverarbeitung,
- Automatisierungen

müssen nachvollziehbar sein.

Beispiel:

```text
Version 25 · E-Ink Küche

Aufgabe geändert:
„Neue Batterie bestellen“

Status:
offen → erledigt
```

Die Quelle wird gespeichert:

- `web`
- `api`
- `device`
- `voice`
- `import`
- `automation`
- `restore`

---

## 10.7 Wiederherstellung

Eine alte Version wird niemals direkt wieder aktiv gesetzt, ohne Historie zu erzeugen.

Stattdessen entsteht eine neue Version:

```text
Version 25
„Version 19 wiederhergestellt“
```

Die alte Historie bleibt vollständig erhalten.

---

# 11. Versions-Diff

Die Versionierung soll blockbewusst sein.

## 11.1 Seitenebene

Beispiel:

```text
Titel geändert:
„Wetterstation“ → „ESP32-Wetterstation“
```

## 11.2 Blockebene

Beispiele:

```text
+ Aufgabenblock hinzugefügt
- Absatz entfernt
↕ Codeblock verschoben
```

## 11.3 Feldebene

Beispiel:

```text
Aufgabe „Sensor montieren“

Status:
offen → erledigt

Priorität:
6 → 8
```

## 11.4 Text-Diff

Bei Markdown, Raw Text und Code kann zusätzlich ein Text-Diff angezeigt werden.

## 11.5 Verschobene Blöcke

Stabile Block-IDs ermöglichen, einen verschobenen Block als Verschiebung zu erkennen, statt als Löschen und Neuerstellen.

---

# 12. Anhänge und Versionierung

## 12.1 Dateien getrennt vom Seiten-JSON

Das Seiten-JSON enthält keine Binärdaten.

Ein Attachment-Block verweist auf eine konkrete Dateiversion.

Beispiel:

```json
{
  "type": "attachment",
  "content": {
    "assetVersionId": "assetver_4711"
  }
}
```

---

## 12.2 Asset-Modell

```text
assets
- id
- workspaceId
- createdBy
- createdAt
- deletedAt
```

```text
asset_versions
- id
- assetId
- versionNumber
- storageKey
- originalFilename
- mimeType
- fileSize
- sha256
- width
- height
- durationSeconds
- createdBy
- createdAt
```

Dateien liegen beispielsweise unter:

```text
data/assets/asset_42/v1/original.bin
```

Oder später in MinIO beziehungsweise S3.

---

## 12.3 Dateien werden nicht überschrieben

Wird eine Datei ersetzt, entsteht eine neue Asset-Version.

Alte Seitenrevisionen verweisen weiterhin auf die alte Asset-Version.

Dadurch wird eine historische Seite exakt so dargestellt wie damals.

---

## 12.4 Löschen

Wird ein Attachment-Block entfernt, darf die Datei nicht sofort physisch gelöscht werden.

Solange eine historische Seitenrevision auf die Asset-Version verweist, muss sie erhalten bleiben.

Erst nach endgültiger Löschung der Historie oder nach definierter Bereinigung darf die Datei entfernt werden.

---

## 12.5 Deduplizierung

Optional später:

```text
file_blobs
- id
- sha256
- storageKey
- fileSize
- mimeType
```

Mehrere Asset-Versionen können auf denselben physischen Blob zeigen.

---

# 13. Freigaben

## 13.1 Freigaben sind eigene Objekte

Eine Seite kann mehrere Freigaben besitzen.

Beispiel:

```text
Freigabe „Max“
- nur lesend
- ein Monat gültig
- versionsgepinnt
- Seite und Unterseiten

Freigabe „Mutti“
- nur lesend
- ohne Ablaufdatum
- immer aktuelle Version
- nur diese Seite
```

---

## 13.2 Freigabemodell

```text
shares
- id
- pageId
- label
- tokenHash
- scope
- accessMode
- versionMode
- pinnedRevisionId
- expiresAt
- revokedAt
- requireLogin
- rules
- createdBy
- createdAt
```

Das Label dient der internen Verwaltung.

---

## 13.3 Umfang

Version 1 benötigt zwei Bereiche:

### Nur diese Seite

Enthalten:

- Seite,
- Blöcke,
- benötigte Anhänge.

Nicht enthalten:

- Elternseiten,
- Breadcrumbs,
- Unterseiten,
- interne Pfade.

### Seite und Unterseiten

Enthalten:

- gewählte Seite,
- gesamter Unterbaum,
- Blöcke,
- Anhänge.

Elternseiten oberhalb des Freigabepunkts bleiben unsichtbar.

---

## 13.4 URLs

Interne URL:

```text
/pages/102
```

Freigabe-URL:

```text
/s/Fv8yKp3xR7mQ2zN6...
```

Alternativ:

```text
/pages/102?share=Fv8yKp3xR7mQ2zN6...
```

Der Query-Link kann auf die separate Freigabe-URL umleiten.

Der Token muss:

- lang,
- zufällig,
- nicht erratbar

sein.

In der Datenhaltung wird nur der Token-Hash gespeichert.

Sinnvolle Header:

```http
Referrer-Policy: no-referrer
X-Robots-Tag: noindex, nofollow
```

---

## 13.5 Versionen in Freigaben

### Aktuell

Die Freigabe zeigt immer die aktuelle Version.

### Gepinnt

Die Freigabe zeigt dauerhaft eine konkrete Revision.

Gepinnte Freigaben sind immer schreibgeschützt.

---

## 13.6 Gepinnte Hierarchien

Bei einem gepinnten Unterbaum wird ein Manifest benötigt:

```text
share_revision_manifest
- shareId
- pageId
- revisionId
```

Dadurch werden festgehalten:

- enthaltene Seiten,
- damalige Hierarchie,
- Revision jeder Seite,
- referenzierte Asset-Versionen.

Neu erstellte Unterseiten erscheinen nicht automatisch in einer gepinnten Freigabe.

---

## 13.7 Zugriffsmodi

Einfaches Modell:

- `read`
- `comment`
- `edit`

Optionale Regeln:

- Anhänge herunterladen
- Versionshistorie sehen
- Unterseiten erstellen
- Blöcke löschen
- Seite umbenennen
- erlaubte Blocktypen

Beispiel:

```json
{
  "canCreateBlocks": true,
  "canEditBlocks": true,
  "canDeleteBlocks": false,
  "canMoveBlocks": true,
  "allowedBlockTypes": [
    "raw_text",
    "markdown",
    "task",
    "attachment"
  ],
  "canCreateSubpages": false,
  "canRenamePage": false
}
```

---

## 13.8 Identität beim Bearbeiten

Lesende Freigaben dürfen anonym über einen geheimen Link funktionieren.

Bearbeitende Freigaben sollen eine Identität verlangen:

- bestehender Benutzer,
- Gastaccount,
- E-Mail-Einladung.

Dadurch kann die Historie anzeigen:

```text
Max Mustermann über Freigabe „Max“
```

---

# 14. Gemeinsames Bearbeiten

## 14.1 Stufenweise Umsetzung

Version 1 benötigt noch kein vollständiges Google-Docs-Verhalten.

Sinnvolle Entwicklung:

1. Konflikterkennung,
2. blockweises Bearbeiten,
3. optionale Block-Sperren,
4. WebSocket-Präsenz,
5. Live-Cursor,
6. gleichzeitige Bearbeitung desselben Textblocks,
7. CRDT oder OT.

---

## 14.2 Präsenzdaten

Kurzlebig:

- Cursorposition,
- Textauswahl,
- fokussierter Block,
- Benutzername,
- Benutzerfarbe,
- online oder offline.

Diese Daten gehören nicht dauerhaft in die Seitenhistorie.

---

## 14.3 Revisionsautoren

Eine Revision kann mehrere Autoren besitzen.

```text
revision_authors
- revisionId
- userId
- firstChangeAt
- lastChangeAt
- operationsCount
```

Beispiel:

```text
Revision 84
Bearbeitet von Michael und Max

- Michael änderte den Einleitungstext.
- Max ergänzte zwei Aufgaben.
```

---

## 14.4 Granulare Historie

Eine dauerhafte Speicherung jedes einzelnen Tastendrucks ist nicht empfohlen.

Besser:

### Kurzfristige Änderungsoperationen

- sehr granular,
- nur einige Tage oder Wochen speichern,
- für Live-Synchronisierung und kurzfristige Nachvollziehbarkeit.

### Automatische Checkpoints

- alle ein bis fünf Minuten,
- nach Bearbeitungspause.

### Dauerhafte Revisionen

- beim expliziten Speichern,
- beim Beenden einer Bearbeitungssitzung,
- nach längerer Ruhe,
- bei API- oder Geräteänderungen,
- vor Wiederherstellung,
- bei sicherheitsrelevanten Änderungen.

---

# 15. Konflikterkennung

Auch ohne Live-Collaboration können zwei Tabs dieselbe Seite bearbeiten.

Jeder Speichervorgang übermittelt eine Basisrevision:

```json
{
  "baseDraftRevision": 4,
  "page": {}
}
```

Ist auf dem Server bereits Revision 5 vorhanden:

```http
409 Conflict
```

UI:

```text
Diese Seite wurde inzwischen an anderer Stelle geändert.

[Neuere Version laden]
[Meine Fassung herunterladen]
[Änderungen vergleichen]
```

Version 1 benötigt noch keinen automatischen Merge.

Wichtig ist, keine Daten still zu überschreiben.

---

# 16. API

## 16.1 Seiten

```http
GET    /api/v1/pages/{pageId}
POST   /api/v1/pages
PATCH  /api/v1/pages/{pageId}
DELETE /api/v1/pages/{pageId}
```

## 16.2 Blöcke

```http
GET    /api/v1/pages/{pageId}/blocks
POST   /api/v1/pages/{pageId}/blocks
GET    /api/v1/blocks/{blockId}
PATCH  /api/v1/blocks/{blockId}
DELETE /api/v1/blocks/{blockId}
```

## 16.3 Filter

Bevorzugte einfache Parameter:

```http
GET /api/v1/pages/102/blocks?type=task
GET /api/v1/pages/102/blocks?type=task&completed=false
GET /api/v1/pages/102/blocks?type=task&tag=esp32
GET /api/v1/pages/102/blocks?type=task&recursive=true
GET /api/v1/pages/102/blocks?type=task&sort=-priority&limit=1
```

Später optional komplexere Query-Sprache oder POST-Abfrage.

---

## 16.4 Raw-Text für Geräte

Einzelner Raw-Text-Block:

```http
GET /api/v1/pages/102/blocks/block_raw_1/content
Accept: text/plain
```

Damit muss ein ESP32 kein komplexes JSON interpretieren.

---

# 17. Geräte

## 17.1 Gerätebindung

```text
devices
- id
- name
- tokenHash
- pageId
- canRead
- canCreate
- canUpdate
- allowedBlockTypes
- lastSeenAt
```

Beispiel:

```text
Gerät: Küchen-E-Ink
Seite: Einkaufsliste
Rechte:
- Task-Blöcke lesen
- Task-Blöcke abschließen
```

Oder:

```text
Gerät: Complainy
Seite: Meldungen Makerspace
Rechte:
- Task-Blöcke erstellen
- keine bestehenden Inhalte lesen
```

---

## 17.2 Vereinfachte Geräte-API

```http
GET /api/device/latest-task
POST /api/device/task
```

Die Seite ergibt sich aus dem Gerätetoken.

---

## 17.3 Sprachaufnahme

Ablauf:

```text
Mikrofon
↓
Audio-Upload
↓
Speech-to-Text
↓
LLM extrahiert strukturierte Daten
↓
Vorschlag
↓
Bestätigung
↓
Task-Block wird erstellt
```

Beispielausgabe des LLM:

```json
{
  "action": "create_task",
  "title": "USB-Kabel für die Wetterstation bestellen",
  "priority": 8,
  "dueAt": "2026-07-25T15:00:00+02:00",
  "confidence": 0.93
}
```

Ein Gerät soll vor der endgültigen Erstellung bestätigen können.

---

# 18. Dateibasierte Speicherung

## 18.1 Grundstruktur

Beispiel:

```text
data/
├── pages/
│   ├── page_102/
│   │   ├── page.json
│   │   ├── autosave.json
│   │   ├── autosave.previous.json
│   │   └── versions/
│   │       ├── 000001.json
│   │       └── 000002.json
│   └── page_103/
├── assets/
├── shares/
├── devices/
├── users/
└── logs/
```

---

## 18.2 Atomare Schreibvorgänge

Jede JSON-Datei wird atomar geschrieben:

1. neue Datei als temporäre Datei schreiben,
2. JSON erneut einlesen und validieren,
3. optional `fsync`,
4. per `rename()` ersetzen.

Beispiel:

```text
autosave.json.tmp
↓
Validierung
↓
autosave.json
```

Zusätzlich:

- `flock()`,
- Schema-Validierung,
- maximale Dateigröße,
- keine Benutzereingaben als direkte Dateipfade,
- stabile IDs,
- Backups.

---

## 18.3 Spätere Auslagerung großer Blockinhalte

In Version 1 liegen alle Blockinhalte direkt im Seiten-JSON.

Später können große Inhalte ausgelagert werden.

Nicht empfohlen:

```json
{
  "content": "String oder manchmal Objekt"
}
```

Besser:

```json
{
  "content": null,
  "contentRef": "blocks/block_17.md"
}
```

Dadurch bleibt das Schema eindeutig.

---

# 19. Benutzer und Authentifizierung

Für einen ersten Prototyp kann eine einfache JSON-basierte Benutzerverwaltung verwendet werden.

Beispiel:

```text
users.json
```

Passwörter:

- mit `password_hash()` speichern,
- mit `password_verify()` prüfen,
- niemals im Klartext speichern.

Langlebige Sessions können über zufällige Authentifikations-IDs umgesetzt werden.

Diese IDs:

- liegen gehasht auf dem Server,
- liegen im Browser,
- sind widerrufbar,
- ersetzen nicht das Passwort im Serverbestand,
- müssen ausreichend lang und zufällig sein.

Trusted HTML bleibt trotzdem ein vollständiger Vertrauensbereich, da es im Hauptfenster ausgeführt wird.

---

# 20. Bewusst nicht in Version 1

Noch nicht umsetzen:

- echte Tabellen,
- `show_page`,
- Spaltenlayouts,
- vollständiger visueller Markdown-WYSIWYG-Editor,
- Live-Cursor,
- CRDT,
- wortgenaue ewige Historie,
- automatischer Konflikt-Merge,
- komplexe Gruppenberechtigungen,
- umfangreiche Rollenmodelle,
- beliebig tiefe Drag-and-drop-Verschachtelung,
- vollständige Offline-Synchronisierung,
- semantische KI-Suche,
- Gantt-Diagramme,
- komplexe Projektmanagement-Funktionen.

---

# 21. Empfohlene Version-1-Meilensteine

## Phase 1: Grundlage

- Benutzeranmeldung
- Seiten erstellen
- Seitenhierarchie
- Seiten umbenennen
- Seiten verschieben
- JSON-Speicherung
- atomare Schreibvorgänge

## Phase 2: Editor

- Blockmodell
- Blockkarten
- Move-Schaltfläche
- feste Auf- und Ab-Pfeile
- Drei-Punkte-Menü
- Minimieren
- Plus-Schaltflächen
- Einzelblockeditor in neuem Tab
- Drag-and-drop

## Phase 3: Basisblöcke

- heading
- raw_text
- markdown
- divider
- code
- callout
- expand

## Phase 4: Funktionsblöcke

- task
- toc
- page_tree
- query
- attachment
- bookmark

## Phase 5: HTML-Blöcke

- trusted_html
- sandbox_html
- Sicherheitsprofile
- getrennte Ausführungslogik

## Phase 6: Versionierung

- Autosave
- Entwurf
- dauerhafte Versionen
- Versionsliste
- Diff
- Wiederherstellung
- Konflikterkennung

## Phase 7: API und Geräte

- Seiten-API
- Block-API
- Filter
- Geräte-Tokens
- vereinfachte Endpunkte
- Raw-Text-Ausgabe

## Phase 8: Freigaben

- Read-only-Link
- Ablaufdatum
- nur Seite oder Unterbaum
- aktuelle oder gepinnte Version
- mehrere Freigaben pro Seite
- Labels
- Widerruf

---

# 22. Zentrale Architekturentscheidungen

1. Alles ist eine Seite.
2. Seiten bestehen aus Blöcken.
3. Aufgaben sind strukturierte Blöcke.
4. Block-IDs sind stabil.
5. Raw Text wird niemals interpretiert.
6. Markdown bleibt die kanonische Quelle.
7. Trusted HTML läuft vollständig im Hauptfenster.
8. Sandbox HTML läuft isoliert und erst nach Bestätigung.
9. Seitenrevisionen speichern Snapshots und Asset-Referenzen.
10. Dateien besitzen eigene unveränderliche Versionen.
11. Autosaves werden überschrieben.
12. Dauerhafte Versionen sind unveränderlich.
13. Freigaben sind eigene Objekte.
14. Gepinnte Freigaben sind read-only.
15. Keine Ordnerobjekte.
16. Version 1 bleibt Vanilla PHP, Vanilla JS und JSON-basiert.
17. Der Editor ist ein Blockeditor und kein klassischer Texteditor.
18. Große Blöcke können minimiert und separat bearbeitet werden.
19. Die API wird von Beginn an mitgedacht.
20. Komplexe Zusammenarbeit wird schrittweise ergänzt.

---

# 23. Beispiel eines vollständigen Seiten-JSON

```json
{
  "schemaVersion": 1,
  "id": "page_102",
  "parentPageId": "page_10",
  "title": "Wetterstation",
  "slug": "wetterstation",
  "revision": 12,
  "draftRevision": 4,
  "createdAt": "2026-07-18T00:00:00+02:00",
  "createdBy": "user_michael",
  "updatedAt": "2026-07-25T11:00:00+02:00",
  "updatedBy": "user_michael",
  "labels": [
    "esp32",
    "projekt"
  ],
  "blocks": [
    {
      "id": "block_heading_1",
      "type": "heading",
      "content": "Aufbau der Wetterstation",
      "settings": {
        "level": 1,
        "includeInToc": true,
        "anchor": null
      }
    },
    {
      "id": "block_raw_1",
      "type": "raw_text",
      "content": "GPIO 4 = SDA\nGPIO 5 = SCL",
      "settings": {
        "wrap": true
      }
    },
    {
      "id": "block_md_1",
      "type": "markdown",
      "content": "Der Sensor wird über **I²C** angeschlossen.",
      "settings": {
        "editorMode": "split"
      }
    },
    {
      "id": "block_task_1",
      "type": "task",
      "content": "Gehäuse fertigstellen",
      "settings": {
        "completed": false,
        "priority": 7,
        "dueAt": null,
        "labels": [],
        "description": null
      },
      "meta": {
        "createdAt": "2026-07-25T10:00:00+02:00",
        "createdBy": "user_michael",
        "completedAt": null,
        "completedBy": null
      }
    },
    {
      "id": "block_expand_1",
      "type": "expand",
      "content": null,
      "settings": {
        "title": "Technische Details",
        "defaultDisplay": "collapsed"
      },
      "children": [
        {
          "id": "block_code_1",
          "type": "code",
          "content": "digitalWrite(4, HIGH);",
          "settings": {
            "language": "cpp",
            "showLineNumbers": true,
            "wrap": false
          }
        }
      ]
    }
  ]
}
```

---

# 24. Startpunkt für die Implementierung

Für den nächsten Chat-Kontext empfiehlt sich folgender erster Auftrag:

```text
Wir bauen eine blockbasierte Knowledge-, Task- und Projekt-Web-App mit Vanilla PHP,
Vanilla JavaScript und JSON-Dateien.

Alle Hierarchieelemente sind Seiten. Es gibt keine Ordnerobjekte.

Bitte beginne mit einem minimalen, aber sauber strukturierten Grundgerüst für:

- Benutzeranmeldung
- Seitenhierarchie
- Seiten erstellen, umbenennen und verschieben
- JSON-Dateispeicherung
- atomare Schreibvorgänge mit flock und temporären Dateien
- blockbasierten Editor
- zunächst die Blocktypen heading, raw_text und markdown
- Move-Schaltfläche, feste Auf-/Ab-Pfeile und Drei-Punkte-Menü
- Block minimieren
- Block in separatem Tab bearbeiten
- Autosave als überschreibbarer Entwurf
- dauerhafte Versionen

Der Code soll sinnvoll auf mehrere Dateien aufgeteilt sein, aber nicht übergranular.
Bitte zuerst Dateistruktur, Datenmodell und Implementierungsplan zeigen und anschließend
die erste vollständig lauffähige Version erstellen.
```
