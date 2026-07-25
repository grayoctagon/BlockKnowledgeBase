# BlockKnowledgeBase

**BlockKnowledgeBase (BKB)** ist eine blockbasierte, hierarchische Knowledge-Base, die Dokumentation, Aufgaben und leichtgewichtiges Projektmanagement miteinander verbindet.

Das Projekt wird möglichst ohne Frameworks und ohne unnötige externe Bibliotheken mit **Vanilla PHP**, **Vanilla JavaScript** und **JSON-Dateien** als Datenspeicher umgesetzt.

> **Projektstatus:** frühe Entwicklung und Konzeption. Es gibt noch keine stabile oder produktionsreife Version.

Dieses Projekt wurde teilweise mit Unterstützung von ChatGPT entwickelt. Teile des Codes, der Dokumentation und strukturelle Vorschläge wurden mithilfe von KI erstellt und anschließend geprüft, angepasst und integriert. Trotz sorgfältiger Prüfung kann das Projekt Fehler enthalten. Die Nutzung erfolgt auf eigene Gefahr.

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

Die Datenstruktur soll so gestaltet werden, dass später eine Migration auf eine Datenbank oder einen anderen Speicher möglich bleibt.

## Dokumentation

Die ausführliche Spezifikation mit Datenmodellen, Blockdefinitionen, Editor-Konzept, Freigaben, Versionierung, API und Geräteintegration befindet sich in:

[BlockKnowledgeBaseSpezifikation.md](./BlockKnowledgeBaseSpezifikation.md)

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



(details see LICENSE.txt file)

[![CC-BY-SA](https://i.creativecommons.org/l/by-sa/4.0/88x31.png)](#license)
