# N9C Inside Monitor für WordPress (`n9c-monitor`)

Meldet sicherheitsrelevante Kennzahlen einer WordPress-Installation an den
[N9C Inside Monitor](https://n9c.io/monitoring). Dort werden sie ausgewertet:
bekannte Sicherheitslücken in Core, Plugins und Themes, verpasste
Sicherheitsupdates, riskante Konfiguration, Administratoren ohne
Zwei-Faktor-Anmeldung und mehr – mit Verlauf, Benachrichtigung bei neuen
kritischen Befunden und ergänzender Außensicht. Gegenstück zur
TYPO3-Extension [n9rai/typo3-monitor](https://github.com/n9rai/typo3-monitor),
gleiches Protokoll ([Protocol.md](https://github.com/n9rai/typo3-monitor/blob/main/Documentation/Protocol.md)).

- **WordPress ab 5.9** · PHP ≥ 7.4 · Einzel-Site und Multisite (netzwerkweit aktiviert, eine Verbindung je Netzwerk)
- **Nur Zahlen und Flags** – keine Benutzernamen, E-Mail-Adressen, IP-Adressen oder Inhalte.
  Was genau gesendet wird, zeigt die Datenvorschau auf der Plugin-Seite bzw. `wp n9c-monitor report --dry-run`.
- **Nur ausgehende HTTPS-Verbindungen**, signiert per HMAC-SHA256; das Plugin bietet keinen eingehenden Endpunkt
- **Vor dem Verbinden wird nichts gesendet** (Vorgabe von wordpress.org, Richtlinie 7)
- Report alle 6 Stunden per WP-Cron, im Hintergrund

Für die Nutzung ist ein Konto im N9C-Dashboard nötig – **kostenlos registrieren:
<https://dashboard.n9c.io/dashboard/register>**. 14 Tage voller Umfang, danach
dauerhaft gratis für eine Installation (Score und offene Befunde); Verlauf,
E-Mail-Alerts, wöchentliche Außenscans und weitere Installationen mit dem Abo.
Die Begrenzung passiert ausschließlich im N9C-Dienst – im Plugin selbst wird
nie etwas gesperrt (wordpress.org verbietet „Trialware“, Richtlinie 5).

## Installation

Aus dem Plugin-Verzeichnis (sobald freigeschaltet) oder die ZIP eines
[Releases](../../releases) unter *Plugins → Installieren → Plugin hochladen*.

## Verbinden

*Werkzeuge → N9C Inside Monitor* (Multisite: *Netzwerkverwaltung →
Einstellungen → N9C Inside Monitor*), Verbindungscode aus dem Dashboard
eingeben, „Verbinden“. Der erste Report geht sofort raus.

Per WP-CLI:

```bash
wp n9c-monitor report --dry-run      # Vorschau, sendet nichts
wp n9c-monitor connect n9c-enroll-…
wp n9c-monitor report
wp n9c-monitor status
wp n9c-monitor disconnect            # Zugangsdaten hier entfernen
```

## Zugangsdaten

Standard: Netzwerk-Option `n9c_monitor_credentials` in der Datenbank (nicht
autoload). Alternativ – z. B. bei Deployments – als Konstanten in der
`wp-config.php` oder als Umgebungsvariablen:

```php
define( 'N9C_MONITOR_INSTANCE', '…' );
define( 'N9C_MONITOR_SECRET', '…' );
// optional
define( 'N9C_MONITOR_ENDPOINT', 'https://api.n9c.io' );
define( 'N9C_MONITOR_AUTO_REPORT', false );   // nur per WP-CLI/Cronjob senden
```

**Kopien und Umzüge:** Gespeicherte Zugangsdaten merken sich die Adresse,
für die sie ausgestellt wurden. Läuft die Datenbank unter einer anderen
Adresse (Staging-Kopie, lokale Kopie, Domainwechsel), pausieren die Reports,
und die Plugin-Seite fragt: „umgezogen – weiter melden“ oder „Kopie – trennen“.
Damit meldet eine Staging-Kopie nie als Live-Website.

## Was übertragen wird

- WordPress-, PHP- und Datenbank-Version, Umgebungstyp (`wp_get_environment_type()`)
- alle Plugins (auch deaktivierte und Must-Use) und Themes mit Slug, Version und Status
- Updates, die WordPress selbst kennt (auch von kommerziellen Plugins mit eigenem Update-Server)
- Adressen der Website(s), je Origin einmal
- Checks, jeweils nur als Zahl oder Ja/Nein:
  - Administratoren ohne 2FA (erkannt: Two Factor, Wordfence Login Security, WP 2FA, Solid Security),
    Anzahl Administratoren, Konto „admin“, inaktive Administratoren (nach 90 Tagen eigener Erfassung)
  - fehlgeschlagene Anmeldungen der letzten 24 Stunden (Anzahl)
  - PHP-Fehlerausgabe für Besucher, Admin nur per HTTPS, Datei-Editor aktiv,
    offene Registrierung mit privilegierter Standardrolle, öffentliche `debug.log`,
    automatische Core-Sicherheitsupdates abgeschaltet, Anzahl deaktivierter Plugins
  - PHP-Dateien in `wp-content/uploads` (relative Pfade, höchstens 20; „Silence is golden“-`index.php` ausgenommen)
  - WP-Cron: Stunden, die die älteste geplante Aufgabe überfällig ist

Lokal gespeichert (nur in dieser Datenbank): letzte Anmeldung von
Administratoren (User-Meta `n9c_monitor_last_login`) und die Zahl
fehlgeschlagener Anmeldungen je Stunde für 24 Stunden.

## Deinstallation

Plugin deaktivieren und löschen – `uninstall.php` entfernt Optionen, User-Meta
und geplante Aufgaben. Die Instanz im N9C-Dashboard bleibt bestehen und kann
dort gelöscht werden.

## Entwicklung und Release

- `Build/build-zip.sh` – ZIP mit Ordner `n9c-monitor/` (prüft, dass Plugin-Header,
  `N9C_MONITOR_VERSION` und `Stable tag` in der `readme.txt` übereinstimmen)
- CI (GitHub Actions): PHP-Syntax 7.4–8.4, ZIP, Plugin Check von wordpress.org
- Tag `v<version>` → GitHub-Release mit ZIP und – sobald die Secrets
  `SVN_USERNAME`/`SVN_PASSWORD` gesetzt sind – Veröffentlichung im SVN von
  wordpress.org (10up/action-wordpress-plugin-deploy). Bilder für die
  Plugin-Seite liegen in `.wordpress-org/`.
- Übersetzungen: Quelltexte englisch (Vorgabe wordpress.org), Deutsch liegt in
  `languages/` bei (`.po`, `.mo`, `.l10n.php`). Neu erzeugen:
  `wp i18n make-pot . languages/n9c-monitor.pot`, `.po` pflegen, dann
  `wp i18n make-mo languages && wp i18n make-php languages`.

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
