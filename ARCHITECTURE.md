# BlockKnowledgeBase – Architektur und Implementierungsplan

Stand: 26. Juli 2026

## Zielstruktur

```text
BlockKnowledgeBase/
├── bin/                    Wartungs- und Prüfskripte
├── config/                 lokale, umgebungsabhängige Konfiguration
├── data/                   dauerhafte Laufzeitdaten (nicht öffentlich)
├── public/                 einziger Webroot
│   ├── assets/
│   ├── index.php
│   └── router.php
├── src/                    PHP-Domänen- und Speicherlogik
├── tests/                  ausführbare PHP-Tests
├── ARCHITECTURE.md
├── BlockKnowledgeBaseSpezifikation.md
└── README.md
```

## Maßgebliches Datenmodell

```text
data/
├── users/users.json
├── auth/sessions/
├── locks/
│   ├── page-id.lock
│   ├── workspace-id.lock
│   └── workspace-move.lock
├── transactions/
└── workspaces/{workspaceId}/
    ├── workspace.json
    ├── workspace.previous.json
    ├── pages/{pageId}/
    │   ├── page.json
    │   ├── autosave.json
    │   ├── autosave.previous.json
    │   └── versions/{revision}.json
    ├── assets/
    ├── shares/
    ├── devices/
    └── logs/
```

- `workspace.json` ist die maßgebliche Quelle für Workspace-Zugehörigkeit,
  Hierarchie, Reihenfolge, Titel und Slugs der Navigation.
- `page.json` enthält Metadaten und geordnete Blöcke, aber weder
  `workspaceId` noch `parentPageId`.
- `autosave.json` ist ein überschreibbarer Entwurf mit eigener
  `draftRevision`.
- `versions/*.json` sind unveränderliche Snapshots.
- Alle Schreibvorgänge erfolgen unter `flock()` über eine temporäre Datei und
  atomarem `rename()`.

## Umsetzung der ersten lauffähigen Version

1. Speichergrundlage, Pfadschutz, Locks und globale ID-Vergabe.
2. Erstinstallation, Benutzeranmeldung und geschützte JSON-API.
3. Workspaces, Seitenhierarchie, Umbenennen sowie Verschieben innerhalb und
   zwischen Workspaces.
4. Blockeditor für `heading`, `raw_text`, `markdown`, `code`, `divider`,
   `callout` und `expand`, einschließlich rekursiver Kindblöcke in Containern.
5. Auf-/Ab-Pfeile, Move-Handle, Drag-and-drop, Minimieren, Drei-Punkte-Menü
   und separater Blockeditor.
6. Entwurfs-Autosave, Konflikterkennung und dauerhafte Revisionen.
7. Automatisierte Syntax-, Speicher- und API-nahe Tests sowie Dokumentation.

## Bewusste Grenzen dieses Meilensteins

Anhänge, Freigaben, Geräte-Tokens, Query-Blöcke, HTML-Blöcke, Kommentare und
Live-Kollaboration bleiben für die folgenden Meilensteine vorgesehen. Die
Speicher- und API-Struktur ist bereits so angelegt, dass diese Funktionen ohne
Wechsel der dauerhaften JSON-Datenhaltung ergänzt werden können.
