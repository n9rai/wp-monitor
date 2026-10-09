# Changelog

## 0.1.0 – 2026-10-08

- Erste Version des WordPress-Plugins für den N9C Inside Monitor (Schema
  `n9c.agent.report/1`, dasselbe Protokoll wie die TYPO3-Extension).
- Meldet WordPress-, PHP- und Datenbankversion, alle Plugins (auch
  deaktivierte und Must-Use) und Themes mit Version und Status sowie die
  Updates, die WordPress selbst kennt.
- Checks: Administratoren ohne 2FA (erkannt: Two Factor, Wordfence Login
  Security, WP 2FA, Solid Security), Anzahl Administratoren, Konto „admin“,
  inaktive Administratoren (ab 90 Tagen eigener Erfassung), fehlgeschlagene
  Anmeldungen, Fehlerausgabe, HTTPS im Admin, PHP-Dateien in den Uploads,
  WP-Cron, Datei-Editor, offene Registrierung mit privilegierter Rolle,
  öffentliche debug.log, deaktivierte Plugins, abgeschaltete
  Core-Sicherheitsupdates.
- Admin-Seite (Werkzeuge bzw. Netzwerk-Einstellungen): Verbinden per Code,
  Auswertung mit offenen Befunden, Datenvorschau, Jetzt senden, Neu verbinden,
  Trennen.
- Erkennung umgezogener oder kopierter Sites (Staging): automatische Reports
  pausieren, bis „umgezogen“ oder „Kopie – trennen“ gewählt ist.
- WP-CLI: `wp n9c-monitor connect|report [--dry-run]|status|disconnect`.
- Übersetzbar (Text-Domain `n9c-monitor`); Sprachdateien kommen über
  translate.wordpress.org.
