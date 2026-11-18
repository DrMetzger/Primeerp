#!/bin/bash
# Instala o TDS-CLI LS (advpls, binário Linux oficial da TOTVS) na VM e, com
# o argumento "smoke", faz um teste de compilar + gerar patch na base PRIMEERP
# (nossa base de desenvolvimento, segura). Não toca em cliente nenhum.
set -eu
set +o pipefail
BASE=/opt/totvs
CLI=$BASE/tdscli
BIN=$CLI/bin/linux/advpls
mkdir -p $CLI

instalar() {
  if [ -x "$BIN" ]; then echo "advpls já instalado ($($BIN --version 2>/dev/null | head -1))"; return; fi
  echo "== baixando @totvs/tds-ls do registro do npm"
  URL=$(curl -s https://registry.npmjs.org/@totvs/tds-ls/latest | grep -oE '"tarball":"[^"]+"' | head -1 | cut -d'"' -f4)
  VER=$(curl -s https://registry.npmjs.org/@totvs/tds-ls/latest | grep -oE '"version":"[^"]+"' | head -1 | cut -d'"' -f4)
  echo "versão $VER"
  curl -sL "$URL" -o $CLI/tds-ls.tgz
  tar -xzf $CLI/tds-ls.tgz -C $CLI --strip-components=1 package/bin package/legado 2>/dev/null || tar -xzf $CLI/tds-ls.tgz -C $CLI
  chmod +x $BIN $CLI/bin/linux/* 2>/dev/null || true
  echo "instalado: $(ls -la $BIN 2>/dev/null)"
  $BIN --version 2>&1 | head -2 || echo "(--version não respondeu, segue o teste)"
}

smoke() {
  local AMB=${1:-PRIMEERP}
  echo "== teste de compilação + patch na base $AMB (localhost:1234)"
  rm -rf $CLI/smoke; mkdir -p $CLI/smoke/fontes $CLI/smoke/patch $CLI/smoke/includes
  cat > $CLI/smoke/fontes/xsmoke01.prw <<'PRW'
User Function XSMOKE01()
Return .T.
PRW
  cat > $CLI/smoke/exec.ini <<INI
logToFile=$CLI/smoke/exec.log
showConsoleOutput=True

[auth]
action=authentication
server=127.0.0.1
port=1234
secure=0
build=AUTO
environment=$AMB
user=admin
psw=

[compila]
action=compile
includes=$CLI/smoke/includes
program=$CLI/smoke/fontes/xsmoke01.prw
recompile=T

[patch]
action=patchGen
fileResource=xsmoke01.prw
patchType=PTM
saveLocal=$CLI/smoke/patch/
INI
  echo "--- ajuda do advpls (v2) ---"
  $BIN --help 2>&1 | head -25 | grep -aviE 'psw|password|senha'
  echo "--- ajuda do modo cli ---"
  $BIN cli --help 2>&1 | head -30 | grep -aviE 'psw|password|senha'
  echo "--- rodando advpls cli ---"
  ( cd $CLI/smoke && timeout 120 $BIN cli $CLI/smoke/exec.ini; echo "EXIT=$?" ) 2>&1 | grep -aviE 'psw=|password|senha' | tail -50
  echo "--- exec.log ---"; cat $CLI/smoke/exec.log 2>/dev/null | grep -aviE 'psw=|password|senha' | tail -40
  echo "--- patch gerado? ---"; ls -la $CLI/smoke/patch/ 2>/dev/null
}

instalar
[ "${1:-}" = "smoke" ] && smoke "${2:-PRIMEERP}"
echo "FIM-TDSCLI"
