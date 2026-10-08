#!/usr/bin/env bash
# Baut die Plugin-ZIP (Upload unter Plugins -> Installieren -> Plugin hochladen
# und Anhang im GitHub-Release). WordPress erwartet einen Ordner n9c-monitor/
# als oberste Ebene. Ausgeschlossen wird alles aus .distignore.
#
#   Build/build-zip.sh   -> Build/dist/n9c-monitor-<version>.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/Build/dist"
HEADER="$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' "$ROOT/n9c-monitor.php" | head -1 | tr -d '[:space:]')"
CONST="$(sed -n "s/.*define( 'N9C_MONITOR_VERSION', '\([^']*\)'.*/\1/p" "$ROOT/n9c-monitor.php" | head -1)"
STABLE="$(sed -n 's/^Stable tag: *\(.*\)$/\1/p' "$ROOT/readme.txt" | head -1 | tr -d '[:space:]')"
if [ -z "$HEADER" ] || [ "$HEADER" != "$CONST" ] || [ "$HEADER" != "$STABLE" ]; then
  echo "Versionen passen nicht: Header='$HEADER', N9C_MONITOR_VERSION='$CONST', Stable tag='$STABLE'" >&2
  exit 1
fi
mkdir -p "$DIST"
ZIP="$DIST/n9c-monitor-${HEADER}.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir "$TMP/n9c-monitor"
EXCLUDES=()
while IFS= read -r line; do
  line="${line%%#*}"; line="$(echo "$line" | tr -d '[:space:]')"
  [ -z "$line" ] && continue
  case "$line" in
    /*) EXCLUDES+=("--exclude=.${line}") ;;   # /pfad -> nur im Hauptordner
    *)  EXCLUDES+=("--exclude=${line}") ;;    # name  -> ueberall (z. B. .DS_Store)
  esac
done < "$ROOT/.distignore"
( cd "$ROOT" && tar "${EXCLUDES[@]}" --exclude='./Build' --exclude='.DS_Store' --exclude='._*' -cf - . ) | tar -C "$TMP/n9c-monitor" -xf -
rm -f "$ZIP"
( cd "$TMP" && zip -r -X -q "$ZIP" n9c-monitor )
echo "Erstellt: $ZIP ($(unzip -l "$ZIP" | tail -1 | awk '{print $2}') Dateien)"
