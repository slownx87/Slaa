#!/bin/sh
cd "$(dirname "$0")" || exit 1

if ! command -v node > /dev/null 2>&1; then
  echo ""
  echo "  Node.js nao encontrado. Instale em https://nodejs.org (versao LTS)."
  echo ""
  exit 1
fi

echo ""
echo "  Iniciando o coletor... deixe este terminal aberto."
echo ""
node servidor.js
