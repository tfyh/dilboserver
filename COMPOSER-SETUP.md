# Composer-Setup für dilboserver + tfyh — Vorschlag

*Ein Vorschlag, gedacht zum Übernehmen **oder** Verwerfen. Er ändert keine
Programmlogik und respektiert die bestehende Architektur (siehe „Was bewusst NICHT geändert
wird").*

## Das Problem, das es löst

Aktuell sind `dilboserver` und `tfyh` zwei Repos, die man **von Hand zusammenkopieren** muss,
um ein lauffähiges Programm zu bekommen: Der dilbo-Code lädt das Framework über feste Pfade wie

```php
include_once "../../tfyh/Api/Container.php";
```

— erwartet `tfyh` also unter `<app-root>/tfyh/`. Dieses manuelle Kopieren übernimmt Composer.

## Was bewusst NICHT geändert wird

In `tfyh/init/init.php` steht:

```php
// include statements are inserted later in the script code for performance reasons
```

Das Laden per gezieltem `include_once` ist also eine **bewusste, performance-motivierte
Entscheidung** — kein Altlast-Zufall. Dieser Vorschlag führt deshalb **kein Autoloading ein**
und fasst keine einzige `include_once`-Zeile und keinen Namespace an. Composer übernimmt hier
**nur** eine Aufgabe: tfyh als versioniertes Paket verwalten und an die erwartete Stelle
`<app-root>/tfyh/` legen.

## Wie es funktioniert

1. **`tfyh`** bekommt eine `composer.json` und wird damit ein installierbares Paket (`tfyh/tfyh`).
2. **`dilboserver`** deklariert `tfyh/tfyh` als Abhängigkeit (direkt vom GitHub-Repo per
   `vcs`-Repository — **kein** Veröffentlichen auf Packagist nötig).
3. `composer install` lädt tfyh nach `vendor/tfyh/tfyh`.
4. Ein kleines Skript (`bin/place-tfyh.php`, ~40 Zeilen, gut lesbar) legt anschließend
   automatisch `./tfyh` an — als **Symlink** auf `vendor/tfyh/tfyh` (bzw. als **Kopie**, falls
   Symlinks nicht verfügbar sind, z. B. unter Windows). Damit funktionieren alle
   `../../tfyh/...`-Includes **unverändert**.

Ergebnis: aus „zwei Repos von Hand zusammenlegen" wird **ein Befehl**.

## Nutzung

```bash
cd dilboserver
composer install
```

Danach liegt das lauffähige Programm vor (tfyh unter `./tfyh`). Ein tfyh-Update ist später ein
`composer update tfyh/tfyh`.

> `./tfyh` und `vendor/` sind in `.gitignore` — sie werden von Composer erzeugt, nicht eingecheckt.
> `composer.lock` dagegen **wird** eingecheckt (fixiert den getesteten tfyh-Stand).

## Lokaler Entwicklungs-Modus (beide Repos gleichzeitig bearbeiten)

Wenn du `tfyh` und `dilboserver` parallel lokal editierst (tfyh-Checkout neben dilboserver unter
`../tfyh`), ersetze in `dilboserver/composer.json` den `repositories`-Block durch ein
**Path-Repository mit Symlink**:

```json
"repositories": [
    {
        "type": "path",
        "url": "../tfyh",
        "options": { "symlink": true }
    }
],
```

Dann zeigt `vendor/tfyh/tfyh` direkt auf deinen lokalen tfyh-Checkout, und Änderungen an tfyh
sind in dilbo sofort wirksam — ohne `composer update`. Für Release/Deploy wieder auf das
`vcs`-Repository zurückstellen.

## Die zwei composer.json im Überblick

**`tfyh/composer.json`** (in das `tfyh`-Repo legen):

```json
{
    "name": "tfyh/tfyh",
    "description": "tools-for-your-hobby (tfyh) – Verwaltung von Daten, Sitzungen und API. Wiederverwendbares Framework für dilbo und efaCloud.",
    "type": "library",
    "license": "Apache-2.0",
    "homepage": "https://www.tfyh.org",
    "authors": [
        { "name": "Martin Glade", "homepage": "https://github.com/tfyh" }
    ],
    "require": {
        "php": ">=8.1"
    }
}
```

**`dilboserver/composer.json`** (liegt bereits in diesem PR/Branch) — siehe Datei.

## Offene Punkte / bitte prüfen

- **PHP-Version:** `>=8.1` ist **aus dem Code abgeleitet** (es werden mehrere `enum`-Typen
  verwendet, z. B. `Control\LoggerSeverity`, `Api\ResultForTransaction` → PHP 8.1; auf 8.0 würde
  der Code an den Enums scheitern). Keine 8.2/8.3-only-Features gefunden. Falls deine
  Zielumgebung ohnehin höher liegt, gern anheben.
- **tfyh-Version:** Solange `tfyh` keine Git-Tags hat, zieht dilboserver `dev-master` (deshalb
  `minimum-stability: dev`). Sobald du tfyh taggst (z. B. `0.9.0`, passend zur `version`-Datei
  `0.9_00`), kann dilboserver auf `"tfyh/tfyh": "^0.9"` umstellen und `minimum-stability` entfällt.
- **Windows:** Der Symlink-Fallback kopiert. Wer unter Windows mit aktivierten Entwickler-Symlinks
  arbeitet, bekommt den Symlink direkt.
- **Keine externen PHP-Abhängigkeiten** gefunden — Composer wird hier ausschließlich für die
  tfyh-Einbindung genutzt. Käme später eine echte Library dazu, ist der Weg schon bereitet.

## Was hier liegt (dieser Branch)

- `composer.json` — dilboserver als Composer-Projekt, das tfyh einbindet
- `bin/place-tfyh.php` — legt `./tfyh` an (Symlink, sonst Kopie)
- `.gitignore` — ignoriert `/vendor/` und das erzeugte `/tfyh/`
- `COMPOSER-SETUP.md` — dieses Dokument
