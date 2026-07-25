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
- JSON-Dateien als dauerhafter Datenspeicher
- keine SQL-Datenbank; das dateibasierte JSON-Modell ist auch langfristig die maßgebliche Datenhaltung
- externe Bibliotheken nur dann, wenn sie einen klaren, nicht sinnvoll selbst abbildbaren Nutzen bringen

Das Dateisystem und die JSON-Dateien bleiben dauerhaft die Source of Truth. Zusätzliche Suchindizes, Caches oder Hilfsdateien dürfen später ergänzt werden, müssen aber aus den Workspace- und Seitendateien reproduzierbar sein und ersetzen diese nicht.

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
GET /api/v1/workspaces/301/pages/102
GET /api/v1/workspaces/301/pages/102/blocks
GET /api/v1/workspaces/301/pages/102/blocks?type=task
GET /api/v1/workspaces/301/pages/102/blocks?type=task&completed=false
GET /api/v1/workspaces/301/pages/102/blocks?type=task&completed=false&sort=-priority&limit=1
```

In GUI-Pfaden, API-Pfaden und gespeicherten Seitenreferenzen werden immer sowohl `workspaceId` als auch `pageId` angegeben. Die global eindeutige Seiten-ID ersetzt diese explizite Workspace-Zuordnung im Pfad nicht.

Für einfache Geräte können zusätzliche vereinfachte Endpunkte angeboten werden:

```http
GET /api/device/latest-task
POST /api/device/task
```

Ein Gerät kann fest einer Seite zugeordnet werden. Dadurch muss das Gerät die Seiten-ID nicht immer selbst mitsenden.

---

# 3. Workspace-, Index- und Seitenmodell

Die Daten werden in voneinander getrennte Workspaces aufgeteilt. Jeder Workspace besitzt einen eigenen Ordner und eine eigene `workspace.json`.

Die `workspace.json` enthält insbesondere:

- Workspace-Metadaten,
- den vollständigen Hierarchie-Index,
- die Reihenfolge der Hauptseiten,
- die Reihenfolge der Unterseiten,
- Navigationsmetadaten für den Seitenbaum,
- reservierte beziehungsweise nicht wiederverwendbare Seiten-IDs.

Beispiel:

```json
{
  "schemaVersion": 1,
  "id": 301,
  "title": "Privat",
  "createdAt": "2026-07-18T00:00:00+02:00",
  "updatedAt": "2026-07-25T20:00:00+02:00",
  "pageIndex": {
    "rootPageIds": [102, 205],
    "pages": {
      "102": {
        "title": "Wetterstation",
        "slug": "wetterstation",
        "children": [103, 104]
      },
      "103": {
        "title": "Verdrahtung",
        "slug": "verdrahtung",
        "children": []
      },
      "104": {
        "title": "Gehäuse",
        "slug": "gehaeuse",
        "children": []
      },
      "205": {
        "title": "Inbox",
        "slug": "inbox",
        "children": []
      }
    },
    "retiredPageIds": []
  }
}
```

JSON-Objektschlüssel sind technisch Strings. Die IDs selbst werden in Feldern, URLs und APIs trotzdem als numerische Werte behandelt.

## 3.1 Hierarchie-Index

Die Seitenhierarchie wird ausschließlich im `pageIndex` der jeweiligen `workspace.json` verwaltet. Eine Seitendatei enthält weder `parentPageId` noch eine eigene Kopie der Hierarchie.

Dabei gilt:

- `rootPageIds` enthält die Seiten auf der obersten Ebene in ihrer manuellen Reihenfolge.
- `pages.<pageId>.children` enthält die direkten Unterseiten in ihrer manuellen Reihenfolge.
- Die Elternseite ergibt sich aus der Position der Page-ID in `rootPageIds` oder in einem `children`-Array.
- Eine Page-ID darf innerhalb eines Workspace-Index genau einmal vorkommen.
- Der `page_tree`-Block liest diesen Index rekursiv und muss zum Aufbau des Baums nicht jede einzelne Seitendatei öffnen.

`title` und `slug` werden im Index als Navigationsmetadaten gespiegelt, damit Seitenbäume, Auswahlfelder und Breadcrumbs ohne Öffnen aller Seitendateien aufgebaut werden können. Beim Umbenennen einer Seite werden Seitendatei und Workspace-Index gemeinsam unter Locks und mit atomaren Dateiersetzungen aktualisiert.

Der Hierarchie-Index ist die maßgebliche Quelle für:

- Workspace-Zugehörigkeit,
- Eltern-Kind-Beziehungen,
- Reihenfolge der Seiten,
- Wurzelseiten.

## 3.2 Seitendatei

Eine Seite besitzt mindestens:

```json
{
  "schemaVersion": 1,
  "id": 102,
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

Die Seitendatei enthält bewusst keine `workspaceId`. Der aktuelle Workspace ergibt sich aus dem Workspace-Ordner und dem aufgerufenen Pfad. Dadurch muss die Seitendatei beim Verschieben in einen anderen Workspace nicht umgeschrieben werden.

## 3.3 Seitentitel

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

## 3.4 Seiten verschieben

Beim Verschieben innerhalb desselben Workspace wird nur der Hierarchie-Index geändert. Die Seitendatei selbst bleibt unverändert.

Beim Verschieben zwischen Workspaces werden:

1. Quell- und Ziel-Workspace in stabiler Reihenfolge gesperrt,
2. die betroffene Seite beziehungsweise der gesamte gewählte Unterbaum ermittelt,
3. die zugehörigen Seitenordner in den Ziel-Workspace verschoben,
4. beide Workspace-Indizes aktualisiert,
5. die Operation über ein Transaktionsjournal gegen Teilfehler absicherbar gemacht.

Da Seiten-IDs global eindeutig sind, bleibt die Page-ID beim Verschieben unverändert.

Ein alter Pfad darf bei Bedarf aufgelöst werden, indem der Resolver bei einer nicht im angegebenen Workspace gefundenen Page-ID die übrigen Workspace-Indizes durchsucht und auf den aktuellen Workspace-Pfad umleitet. Dies ist ein Fallback für veraltete Links und ersetzt nicht die explizite Angabe beider IDs.

## 3.5 Pfade und Referenzen

GUI-Pfad:

```text
/workspaces/301/pages/102
```

API-Pfad:

```text
/api/v1/workspaces/301/pages/102
```

Gespeicherte Seitenreferenz:

```json
{
  "workspaceId": 301,
  "pageId": 102
}
```

Auch bei global eindeutigen Page-IDs werden `workspaceId` und `pageId` immer gemeinsam gespeichert und übertragen. Geheime Freigabe-URLs sind die bewusste Ausnahme, weil sie interne Pfade verschleiern sollen.

## 3.6 ID-Regeln

### Workspace-IDs

- automatisch erzeugt,
- ausschließlich numerisch,
- größer als 100,
- keine führenden Nullen,
- eindeutig unter allen Workspaces,
- als JSON-Zahl und nicht als formatierter String gespeichert.

### Page-IDs

- automatisch erzeugt,
- ausschließlich numerisch,
- größer als 100,
- keine führenden Nullen,
- global eindeutig über alle Workspaces,
- werden nach dem Löschen nicht wiederverwendet.

Für neue Page-IDs wird unter einem globalen ID-Lock:

1. jede vorhandene `workspace.json` eingelesen,
2. jede aktive Page-ID gesammelt,
3. jede `retiredPageId` gesammelt,
4. mit `random_int(101, 999999999999)` ein Kandidat erzeugt,
5. bei einer Kollision erneut gewürfelt,
6. die neue ID erst anschließend in den Zielindex geschrieben.

Der Wertebereich bleibt deutlich unter `Number.MAX_SAFE_INTEGER` und kann dadurch in PHP, JavaScript und JSON zuverlässig als Ganzzahl verarbeitet werden.

### Block-IDs

Block-IDs dürfen bis zu 64 hexadezimale Zeichen besitzen. Empfohlen ist eine 64-stellige, kleingeschriebene SHA-256-ID aus:

- Source-Page-ID,
- hochauflösendem Zeitstempel,
- kryptografisch zufälligen Bytes.

Beispielhaft:

```php
$blockId = hash('sha256', $pageId . '|' . hrtime(true) . '|' . random_bytes(32));
```

Dadurch ist keine globale Suche durch alle Seiten nötig. Vor dem Einfügen wird trotzdem geprüft, ob die ID innerhalb der aktuellen Seite bereits existiert.

Die lesbaren IDs wie `block_task_1` in den Beispielen dieser Spezifikation dienen ausschließlich der Verständlichkeit. Die Implementierung verwendet die hier definierten hexadezimalen Block-IDs.

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
GET /api/v1/workspaces/301/pages/102/blocks/{blockId}/content
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
    "rootWorkspaceId": 301,
    "rootPageId": 102,
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
      "workspaceId": 301,
      "pageId": 102,
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
    "workspaceId": 301,
    "pageId": 123
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
/editor/workspaces/301/pages/102/blocks/{blockId}
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
└── workspaces/
    └── 301/
        └── pages/
            └── 102/
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
data/workspaces/301/assets/asset_42/v1/original.bin
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
- workspaceId
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
/workspaces/301/pages/102
```

Freigabe-URL:

```text
/s/Fv8yKp3xR7mQ2zN6...
```

Alternativ:

```text
/workspaces/301/pages/102?share=Fv8yKp3xR7mQ2zN6...
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
- workspaceId
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
GET    /api/v1/workspaces/{workspaceId}/pages/{pageId}
POST   /api/v1/workspaces/{workspaceId}/pages
PATCH  /api/v1/workspaces/{workspaceId}/pages/{pageId}
DELETE /api/v1/workspaces/{workspaceId}/pages/{pageId}
```

## 16.2 Blöcke

```http
GET    /api/v1/workspaces/{workspaceId}/pages/{pageId}/blocks
POST   /api/v1/workspaces/{workspaceId}/pages/{pageId}/blocks
GET    /api/v1/workspaces/{workspaceId}/pages/{pageId}/blocks/{blockId}
PATCH  /api/v1/workspaces/{workspaceId}/pages/{pageId}/blocks/{blockId}
DELETE /api/v1/workspaces/{workspaceId}/pages/{pageId}/blocks/{blockId}
```

## 16.3 Filter

Bevorzugte einfache Parameter:

```http
GET /api/v1/workspaces/301/pages/102/blocks?type=task
GET /api/v1/workspaces/301/pages/102/blocks?type=task&completed=false
GET /api/v1/workspaces/301/pages/102/blocks?type=task&tag=esp32
GET /api/v1/workspaces/301/pages/102/blocks?type=task&recursive=true
GET /api/v1/workspaces/301/pages/102/blocks?type=task&sort=-priority&limit=1
```

Später optional komplexere Query-Sprache oder POST-Abfrage.

---

## 16.4 Raw-Text für Geräte

Einzelner Raw-Text-Block:

```http
GET /api/v1/workspaces/301/pages/102/blocks/{blockId}/content
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
- workspaceId
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

Workspace und Seite ergeben sich aus dem Gerätetoken.

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

# 18. Dauerhafte dateibasierte JSON-Speicherung

Das dateibasierte JSON-Modell ist nicht nur ein Provisorium für Version 1, sondern die dauerhaft vorgesehene Datenarchitektur.

Dabei gilt:

- Workspace- und Seitendateien bleiben die Source of Truth.
- Es ist keine spätere Migration auf SQL erforderlich oder vorgesehen.
- Suchindizes, Caches und Vorschaudateien dürfen ergänzt werden, sind aber abgeleitete Daten.
- Jede abgeleitete Datei muss löschbar und aus den maßgeblichen JSON-Dateien erneut erzeugbar sein.
- Die Daten bleiben ohne Spezialdatenbank direkt im Dateisystem lesbar, sicherbar und versionierbar.

## 18.1 Grundstruktur

Beispiel:

```text
data/
├── workspaces/
│   ├── 301/
│   │   ├── workspace.json
│   │   ├── workspace.previous.json
│   │   ├── pages/
│   │   │   ├── 102/
│   │   │   │   ├── page.json
│   │   │   │   ├── autosave.json
│   │   │   │   ├── autosave.previous.json
│   │   │   │   └── versions/
│   │   │   │       ├── 000001.json
│   │   │   │       └── 000002.json
│   │   │   └── 103/
│   │   ├── assets/
│   │   ├── shares/
│   │   ├── devices/
│   │   └── logs/
│   └── 402/
│       ├── workspace.json
│       ├── pages/
│       ├── assets/
│       ├── shares/
│       ├── devices/
│       └── logs/
├── users/
├── auth/
├── transactions/
├── locks/
│   ├── page-id.lock
│   ├── workspace-id.lock
│   └── workspace-move.lock
└── logs/
```

Damit liegen nicht tausende Seiten, Versionen und Assets in einem einzigen Verzeichnis. Jeder Workspace bildet eine eigene Dateisystemgrenze, und jede Seite besitzt wiederum einen eigenen Unterordner.

## 18.2 Workspace-Index

Jeder Workspace besitzt genau eine maßgebliche `workspace.json` mit seinem eigenen Seitenindex.

Für einen vollständigen Seitenbaum wird nur diese Datei gelesen. Die einzelnen `page.json`-Dateien werden erst geöffnet, wenn konkrete Seiteninhalte benötigt werden.

`workspace.previous.json` kann als letzte technisch gültige Sicherung des Index dienen. Zusätzlich kann der Workspace-Index selbst versioniert oder regelmäßig gesichert werden, weil die Hierarchie nicht aus den Seitendateien allein rekonstruiert werden kann.

## 18.3 ID-Erzeugung

Die Erzeugung einer neuen global eindeutigen Page-ID erfolgt unter `locks/page-id.lock`.

Während dieser Sperre werden alle `workspace.json`-Dateien eingelesen und aktive sowie stillgelegte Page-IDs gesammelt. Erst danach wird eine zufällige numerische ID gewählt und in den Zielindex geschrieben.

Der globale Lock verhindert, dass zwei parallele Requests nach demselben vollständigen Scan zufällig dieselbe noch freie ID übernehmen.

Für neue Workspace-IDs gilt dasselbe Prinzip mit `locks/workspace-id.lock` und einem Scan der vorhandenen Workspace-Ordner beziehungsweise Workspace-IDs.

## 18.4 Atomare Schreibvorgänge

Jede JSON-Datei wird atomar geschrieben:

1. zuständige Lock-Datei öffnen,
2. neue Datei vollständig als temporäre Datei schreiben,
3. JSON erneut einlesen und validieren,
4. optional `fsync` ausführen,
5. bisherige gültige Datei als `.previous` sichern,
6. temporäre Datei per `rename()` ersetzen,
7. Lock freigeben.

Beispiel:

```text
workspace.json.tmp
↓
Validierung
↓
workspace.previous.json
workspace.json
```

Zusätzlich:

- `flock()`,
- Schema-Validierung,
- maximale Dateigröße,
- keine Benutzereingaben als direkte Dateipfade,
- stabile IDs,
- Backups.

Beim gleichzeitigen Sperren mehrerer Workspaces werden Locks immer in aufsteigender numerischer Workspace-ID erworben. Dadurch werden Deadlocks vermieden.

Workspace-übergreifende Verschiebungen erhalten zusätzlich eine Datei unter `transactions/`. Sie beschreibt Ausgangszustand, Zielzustand und Fortschritt, damit ein abgebrochener Vorgang sicher fortgesetzt oder zurückgerollt werden kann.

## 18.5 Spätere Auslagerung großer Blockinhalte

Zunächst liegen alle Blockinhalte direkt im Seiten-JSON.

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
  "contentRef": "blocks/4f6c7d8e.md"
}
```

Dadurch bleibt das Schema eindeutig. Die ausgelagerten Dateien bleiben Teil der dateibasierten Workspace-Struktur und werden gemeinsam mit der Seite verschoben, versioniert und gesichert.

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
2. Es gibt keine Ordnerobjekte innerhalb der Seitenhierarchie.
3. Die Daten sind dauerhaft in dateibasierten JSON-Dateien organisiert; eine SQL-Migration ist nicht vorgesehen.
4. Jeder Workspace besitzt einen eigenen Dateisystemordner und eine eigene `workspace.json`.
5. Die vollständige Seitenhierarchie und Seitenreihenfolge liegen im Workspace-Index, nicht in den Seitendateien.
6. Seitendateien enthalten weder `parentPageId` noch `workspaceId`.
7. Page-IDs sind zufällige numerische Ganzzahlen, größer als 100 und global über alle Workspaces eindeutig.
8. Workspace-IDs sind zufällige numerische Ganzzahlen, größer als 100 und eindeutig.
9. GUI-, API- und Referenzpfade enthalten immer `workspaceId` und `pageId`.
10. Seiten bestehen aus Blöcken.
11. Aufgaben sind strukturierte Blöcke.
12. Block-IDs sind stabile, bis zu 64 Zeichen lange hexadezimale IDs.
13. Raw Text wird niemals interpretiert.
14. Markdown bleibt die kanonische Quelle.
15. Trusted HTML läuft vollständig im Hauptfenster.
16. Sandbox HTML läuft isoliert und erst nach Bestätigung.
17. Seitenrevisionen speichern Snapshots und Asset-Referenzen.
18. Dateien besitzen eigene unveränderliche Versionen.
19. Autosaves werden überschrieben.
20. Dauerhafte Versionen sind unveränderlich.
21. Freigaben sind eigene Objekte.
22. Gepinnte Freigaben sind read-only.
23. Der Editor ist ein Blockeditor und kein klassischer Texteditor.
24. Große Blöcke können minimiert und separat bearbeitet werden.
25. Die API wird von Beginn an mitgedacht.
26. Komplexe Zusammenarbeit wird schrittweise ergänzt.

---

# 23. Beispiel eines vollständigen Seiten-JSON

```json
{
  "schemaVersion": 1,
  "id": 102,
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
Vanilla JavaScript und dauerhaft dateibasierten JSON-Dateien. Eine spätere SQL-Migration
ist nicht vorgesehen.

Alle Hierarchieelemente sind Seiten. Es gibt keine Ordnerobjekte. Die Anwendung ist in
Workspaces aufgeteilt. Jeder Workspace besitzt einen eigenen Ordner und eine eigene
workspace.json mit dem vollständigen Hierarchie-Index. Seitendateien enthalten weder
parentPageId noch workspaceId.

Workspace- und Page-IDs sind automatisch erzeugte numerische Ganzzahlen größer als 100.
Page-IDs müssen nach Einlesen aller Workspace-Indizes global eindeutig vergeben werden.
Block-IDs sind 64-stellige hexadezimale IDs. GUI-, API- und Referenzpfade enthalten immer
workspaceId und pageId.

Bitte beginne mit einem minimalen, aber sauber strukturierten Grundgerüst für:

- Benutzeranmeldung
- Workspaces und Workspace-Auswahl
- workspace.json mit vollständigem Hierarchie-Index
- Seiten erstellen, umbenennen und innerhalb beziehungsweise zwischen Workspaces verschieben
- dauerhafte JSON-Dateispeicherung
- globale ID-Locks und kollisionssichere ID-Erzeugung
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
