# BlockKnowledgeBase

**BlockKnowledgeBase (BKB)** ist eine blockbasierte, hierarchische Knowledge-Base, die Dokumentation, Aufgaben und leichtgewichtiges Projektmanagement miteinander verbindet.

Das Projekt wird möglichst ohne Frameworks und ohne unnötige externe Bibliotheken mit **Vanilla PHP**, **Vanilla JavaScript** und **JSON-Dateien** als Datenspeicher umgesetzt.

## Dokumentation

Die vollständigen fachlichen und technischen Entscheidungen stehen in der
[BlockKnowledgeBaseSpezifikation.md](BlockKnowledgeBaseSpezifikation.md). Die
kompakte Struktur des aktuellen Meilensteins beschreibt
[ARCHITECTURE.md](ARCHITECTURE.md).

## AI Disclaimer

Dieses Projekt wurde teilweise mit Unterstützung von ChatGPT entwickelt. Teile des Codes, der Dokumentation und strukturelle Vorschläge wurden mithilfe von KI erstellt und anschließend geprüft, angepasst und integriert. Trotz sorgfältiger Prüfung kann das Projekt Fehler enthalten. Die Nutzung erfolgt auf eigene Gefahr.

## Projektstatus Aktueller Stand

Die erste lauffähige Version enthält:

- webbasierte Ersteinrichtung und Benutzeranmeldung,
- mehrere Workspaces mit jeweils eigener `workspace.json`,
- hierarchische Seiten ohne `parentPageId` in den Seitendateien,
- globale numerische Workspace- und Page-IDs,
- 64-stellige hexadezimale Block-IDs,
- Seiten erstellen, umbenennen, löschen und samt Unterbaum verschieben,
- Verschieben innerhalb und zwischen Workspaces,
- Blockeditor für `heading`, `raw_text` und `markdown`,
- feste Move-, Auf-/Ab- und Drei-Punkte-Bedienelemente,
- Minimieren, Duplizieren, Ausschneiden und Rückgängig,
- separaten Einzelblockeditor in einem neuen Tab,
- sichere Markdown-Vorschau ohne rohes HTML,
- Autosave nach zwei Sekunden, spätestens nach 15 Sekunden,
- Konflikterkennung über `draftRevision`,
- unveränderliche Seitenrevisionen und Wiederherstellung,
- JSON-API, über die auch die Weboberfläche arbeitet,
- atomare Schreibvorgänge mit `flock()`, temporären Dateien und `rename()`,
- ein Verschiebejournal für Workspace-übergreifende Seitenbewegungen,
- dateibasierten Papierkorb und nicht wiederverwendbare Page-IDs.

Anhänge, Aufgaben-, Query-, HTML- und weitere Blocktypen, Freigaben,
Geräte-Tokens und Live-Kollaboration folgen in späteren Meilensteinen.

## Grundidee

In BlockKnowledgeBase ist jedes Element der Hierarchie eine Seite. Es gibt keine getrennten Ordnerobjekte.

Seiten bestehen aus strukturierten Blöcken und können unter anderem als Wissensseite, Aufgabenliste, Projektübersicht, API-Datenquelle oder Ziel für eingebettete Geräte verwendet werden.

Zu den geplanten Kernfunktionen gehören:

- hierarchische Seitenstruktur
- blockbasierter Editor
- Aufgaben als strukturierte Seitenblöcke
- automatische Entwürfe und dauerhafte Seitenversionen
- Versionsvergleich und Wiederherstellung
- zeitlich begrenzte und versionsgepinnte Freigaben
- API-Zugriff auf Seiten und Blöcke
- Anbindung von ESP32-, E-Ink- und Spracheingabegeräten
- bewusst mächtige HTML- und JavaScript-Blöcke für Administratoren
- isolierte Sandbox-Blöcke für sicherere Einbettungen

## Start und Ersteinrichtung

Der Document Root des Webservers muss ausschließlich auf `public/` zeigen.
Das Datenverzeichnis darf nie direkt aus dem Web erreichbar sein.

Unter Apache übernimmt `public/.htaccess` das Routing, sofern `mod_rewrite`
aktiviert ist. Unter Nginx sollen unbekannte Anwendungspfade intern an
`public/index.php` weitergereicht werden.

Danach öffnen und den ersten Administrator anlegen. Es gibt **kein voreingestelltes Passwort**.

Bei der Ersteinrichtung entstehen automatisch:
- der erste Administrator,
- der Workspace `Privat`,
- die Seite `Willkommen`.

### Shared Hosting

- In der Subdomain-Verwaltung muss der Serverpfad direkt auf den Ordner
  `BlockKnowledgeBase/public` zeigen, nicht auf den Projektordner.
- `public/router.php` ist ausschließlich für den lokalen PHP-Entwicklungsserver
  bestimmt und wird unter Apache nicht direkt aufgerufen.
- Falls das Hosting einen zentralen Session-Cache konfiguriert, muss
  `session.save_handler` beziehungsweise `session_cache` auf `filesystem`
  stehen. Die Anwendung versucht diese Einstellung zusätzlich selbst zu setzen.
- Das Verzeichnis `data/` muss für PHP beschreibbar sein, **darf aber nicht öffentlich erreichbar sein**.

Empfohlen:

- HTTPS,
- ein nur für den PHP-Prozess beschreibbares Datenverzeichnis,
- regelmäßige Sicherungen des vollständigen Datenverzeichnisses,
- deaktivierte Verzeichnisauflistung,
- restriktive Dateirechte,
- ein eigener Betriebssystembenutzer für den PHP-Prozess.


## Geplante Blöcke für Version 1

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

Später vorgesehen:

- `table`
- `show_page`
- `columns`

## Technischer Ansatz

Die erste Version soll bewusst einfach und selbst hostbar bleiben:

- Vanilla PHP
- Vanilla JavaScript
- HTML und CSS
- eine JSON-Datei je Seite
- separate JSON-Dateien je dauerhafter Seitenversion
- atomare Schreibvorgänge und Dateisperren
- stabile IDs für Seiten und Blöcke
- keine Datenbank in Version 1
- keine unnötigen Build-Tools oder Framework-Abhängigkeiten

## Datenstruktur

```text
data/
├── users/users.json
├── auth/sessions/
├── locks/
├── transactions/
└── workspaces/{workspaceId}/
    ├── workspace.json
    ├── workspace.previous.json
    ├── pages/{pageId}/
    │   ├── page.json
    │   ├── autosave.json
    │   ├── autosave.previous.json
    │   └── versions/000001.json
    └── trash/pages/
```

`workspace.json` ist die maßgebliche Quelle für Hierarchie und Reihenfolge.
Eine `page.json` kennt daher weder ihren Workspace noch ihre Elternseite.

## API-Beispiele

```http
GET /api/v1/workspaces
GET /api/v1/workspaces/301/pages/102
GET /api/v1/workspaces/301/pages/102/blocks?type=markdown
GET /api/v1/workspaces/301/pages/102/blocks/{blockId}
GET /api/v1/workspaces/301/pages/102/blocks/{blockId}/content
```

Schreibende Anfragen benötigen:

- die angemeldete Session,
- `Content-Type: application/json`,
- den von `GET /api/session` gelieferten Wert als Header
  `X-CSRF-Token`.

Fehlerantworten folgen diesem Format:

```json
{
  "ok": false,
  "error": {
    "code": "DRAFT_CONFLICT",
    "message": "Diese Seite wurde inzwischen an anderer Stelle geändert.",
    "details": {}
  }
}
```

## Tests

Für den Speicher-, Versions- und Verschiebeablauf:

```bash
test_data_dir="$(mktemp -d)"
BKB_DATA_DIR="$test_data_dir" php tests/run.php
```

Die Tests arbeiten ausschließlich im angegebenen temporären Datenverzeichnis.
Die Dateien `tests/run-wasm.mjs`, `tests/http-wasm.mjs` und
`tests/frontend-smoke.mjs` sind optionale Entwicklungs-Fallbacks für
Umgebungen ohne lokal installiertes PHP beziehungsweise ohne Browser. Die
dort verwendeten Pakete sind keine Laufzeitabhängigkeiten der Anwendung.

## Sicherheitshinweis

Der Blocktyp `trusted_html` soll bewusst HTML, CSS und JavaScript direkt im Hauptfenster der Anwendung ausführen können. Er ist ausschließlich für Administratoren vorgesehen und entspricht technisch einer absichtlich erlaubten Ausführung von vertrauenswürdigem Code im Anwendungskontext.

Für weniger vertrauenswürdige Inhalte ist ein separater `sandbox_html`-Block vorgesehen, der Inhalte erst nach Bestätigung und innerhalb eines eingeschränkten Iframes ausführt.

BlockKnowledgeBase befindet sich in Entwicklung und sollte derzeit nicht für sensible oder produktive Daten verwendet werden.

## To-dos

- Grundstruktur und Benutzeranmeldung
- Seitenhierarchie und JSON-Speicherung
- erster blockbasierter Editor
- Autosave und Versionierung
- Basisblöcke
- Block- und Seiten-API
- Freigaben
- Geräteintegration

## Lizenz

Lizenziert unter **CC-BY-SA 4.0**.
Attribution-ShareAlike 4.0 International CC-BY-SA 

Die Inhalte dürfen unter **Angabe der Quelle** https://github.com/grayoctagon/ verwendet, geteilt und bearbeitet werden. Bearbeitete Versionen müssen unter denselben Lizenzbedingungen veröffentlicht werden.



(details see [LICENSE.txt](LICENSE.txt) file)

[![CC-BY-SA](https://i.creativecommons.org/l/by-sa/4.0/88x31.png)](#license)
