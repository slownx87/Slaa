#!/usr/bin/env bash
# Roda os testes das partes do app que não dependem do Android (JVM).
#   ./test/rodar.sh [http://127.0.0.1:8099]
set -euo pipefail
AQUI="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="${TMPDIR:-/tmp}/vnapp-testes-$$"
mkdir -p "$SAIDA/classes" "$SAIDA/lib"
JSON="${JSON_JAR:-$SAIDA/lib/json.jar}"
if [ ! -f "$JSON" ]; then
  curl -fsL --retry 3 -o "$JSON" "https://repo.maven.apache.org/maven2/org/json/json/20240303/json-20240303.jar"
fi
P="$AQUI/app/java/com/vnsystem/app"
javac -encoding UTF-8 -cp "$JSON" -d "$SAIDA/classes" \
  "$P/Config.java" "$P/UrlPolicy.java" "$P/Names.java" "$P/HttpDownload.java" "$P/HttpTexto.java" "$P/AvisosParser.java" \
  "$AQUI/test/com/vnsystem/app/TestesPuros.java" 2>&1 | grep -v "^Picked up" || true
java -cp "$SAIDA/classes:$JSON" com.vnsystem.app.TestesPuros "$@" 2>&1 | grep -v "^Picked up"
