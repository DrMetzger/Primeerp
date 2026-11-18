#!/bin/bash
# TDS-CLI LS (advpls) na VM. Modos:
#   instalar         - baixa o advpls (binário Linux oficial) se faltar
#   iniciar <AMB>    - instala e dispara um teste (compilar+patch) DESTACADO,
#                      gravando tudo em $CLI/smoke/resultado.txt; volta na hora
#   _executar <AMB>  - uso interno (roda o teste de fato)
# Nunca toca em cliente nenhum: o alvo é a base local (localhost:1234).
set -u
BASE=/opt/totvs
CLI=$BASE/tdscli
BIN=$CLI/bin/linux/advpls
mkdir -p $CLI

instalar() {
  if [ -x "$BIN" ]; then echo "advpls ok ($($BIN --version 2>/dev/null | head -1))"; return; fi
  echo "baixando @totvs/tds-ls do npm"
  local URL VER
  URL=$(curl -s https://registry.npmjs.org/@totvs/tds-ls/latest | grep -oE '"tarball":"[^"]+"' | head -1 | cut -d'"' -f4)
  VER=$(curl -s https://registry.npmjs.org/@totvs/tds-ls/latest | grep -oE '"version":"[^"]+"' | head -1 | cut -d'"' -f4)
  echo "versão $VER"
  curl -sL "$URL" -o $CLI/tds-ls.tgz
  tar -xzf $CLI/tds-ls.tgz -C $CLI --strip-components=1 package/bin package/legado 2>/dev/null || tar -xzf $CLI/tds-ls.tgz -C $CLI
  chmod +x $CLI/bin/linux/* 2>/dev/null || true
  [ -x "$BIN" ] && echo "instalado" || echo "FALHOU instalar"
}

executar() {
  local AMB=${1:-PRIMEERP} S=$CLI/smoke
  rm -rf $S; mkdir -p $S/fontes $S/patch $S/includes
  cat > $S/fontes/xsmoke01.prw <<'PRW'
User Function XSMOKE01()
Return .T.
PRW
  cat > $S/exec.ini <<INI
logToFile=$S/exec.log
showConsoleOutput=True

[valida]
action=validate
server=127.0.0.1
port=1234
secure=0

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
includes=$S/includes
program=$S/fontes/xsmoke01.prw
recompile=T

[patch]
action=patchGen
fileResource=xsmoke01.prw
patchType=PTM
saveLocal=$S/patch/
INI
  echo "== teste compilar+patch na base $AMB (localhost:1234) — $(date '+%H:%M:%S')"
  ( cd $S && timeout --kill-after=10 70 $BIN --verbose 1 --log-file $S/cli.log cli $S/exec.ini > $S/saida.txt 2>&1 < /dev/null; echo "EXIT=$?" >> $S/saida.txt )
  pkill -9 -f advpls 2>/dev/null || true
  echo "--- EXIT ---"; grep -a '^EXIT=' $S/saida.txt
  echo "--- saida ---"; grep -aviE 'psw=|password|senha' $S/saida.txt | grep -av '^EXIT=' | tail -40
  echo "--- cli.log ---"; grep -aviE 'psw=|password|senha' $S/cli.log 2>/dev/null | tail -40
  echo "--- exec.log ---"; grep -aviE 'psw=|password|senha' $S/exec.log 2>/dev/null | tail -20
  echo "--- patch gerado? ---"; ls -la $S/patch/ 2>/dev/null
  echo "FIM-TDSCLI $(date '+%H:%M:%S')"
}

case "${1:-instalar}" in
  instalar) instalar ;;
  smoke)    instalar; executar "${2:-PRIMEERP}" ;;
  iniciar)  instalar; mkdir -p $CLI/smoke; : > $CLI/smoke/resultado.txt
            setsid bash -c "BASE=$BASE CLI=$CLI BIN=$BIN bash $0 _executar ${2:-PRIMEERP} > $CLI/smoke/resultado.txt 2>&1 < /dev/null" >/dev/null 2>&1 &
            echo "teste disparado (destacado)"; exit 0 ;;
  _executar) executar "${2:-PRIMEERP}" ;;
  *) echo "modo inválido"; exit 1 ;;
esac
