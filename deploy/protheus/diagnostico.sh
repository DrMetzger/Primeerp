#!/bin/bash
# Só leitura: versões exatas das peças do Protheus e erros recentes do
# AppServer (sem reiniciar nada). Grava /opt/totvs/versoes.json para o painel.
set -u
set +o pipefail
BASE=/opt/totvs
APP=$BASE/protheus12/protheus/bin/appserver
DBA=$BASE/protheus12/dbaccess
ver() { strings "$1" 2>/dev/null | grep -m1 -oE "$2" ; }
APPBUILD=$(grep -m1 -oE 'Build 7\.00\.[0-9]+[A-Z]?' $APP/console.log 2>/dev/null | awk '{print $2}')
APPVER=$(grep -m1 -oE 'Build Version: [0-9.]+' $APP/console.log 2>/dev/null | awk '{print $3}')
WEBAPP=$(ls $APP/.webapp-* 2>/dev/null | head -1 | sed 's/.*\.webapp-//')
[ -n "$WEBAPP" ] || WEBAPP=$(ver $APP/webapp.so '7\.00\.[0-9]{6}[A-Z]?')
DBAVER=$(journalctl -u totvsdbaccess --no-pager 2>/dev/null | grep -oE 'Build Version: [0-9.]+' | tail -1 | awk '{print $3}')
[ -n "$DBAVER" ] || DBAVER=$(ver $DBA/dbaccess64 '[0-9]{2}\.[0-9]\.[0-9]\.[0-9]+')
LICVER=$(ls $BASE/instaladores/*TOTVSLICENSE* 2>/dev/null | grep -oE '[0-9]+_[0-9]+_[0-9]+' | head -1 | tr _ .)
PGVER=$(psql --version 2>/dev/null | awk '{print $3}')
RPO=$(ls $BASE/instaladores/*INSTALADOR_PROTHEUS* 2>/dev/null | grep -oE '12\.1\.[0-9]{4}' | head -1)
RPODATA=$(date -r $BASE/protheus12/protheus/apo/tttm120.rpo +%d/%m/%Y 2>/dev/null)
AMB=$(grep -m1 -E '^\[PRIMEERP\]' $APP/appserver.ini >/dev/null && echo PRIMEERP)
cat > $BASE/versoes.json <<JSON
{"protheus":"${RPO:-12.1.2510}","rpoData":"${RPODATA:-}","appserver":"${APPVER:-}","appserverBuild":"${APPBUILD:-}","webapp":"${WEBAPP:-}","dbaccess":"${DBAVER:-}","licenseServer":"${LICVER:-}","postgresql":"${PGVER:-}","ambiente":"${AMB:-PRIMEERP}","webappUrl":"https://protheus.primeerp.com.br"}
JSON
chmod 644 $BASE/versoes.json
echo "== versões"; cat $BASE/versoes.json; echo
echo "== webapp.so nas pastas"; find $BASE/protheus12 -name 'webapp*.so' -exec sh -c 'printf "%s  build %s\n" "$1" "$(strings "$1" | grep -m1 -oE "7\.00\.[0-9]{6}[A-Z]?")"' _ {} \; 2>/dev/null
echo "== erros recentes do AppServer (conexão do Webapp)"
grep -nE 'ERROR|WARN|license|License|LICENSE|webapp|WEBAPP|connect|Connection|validat' $APP/console.log 2>/dev/null | grep -viE 'senha|password|hub|Server not found, ID|Monitored Calls' | tail -30
echo "== License Server (últimas)"; journalctl -u licenseVirtual -n 15 --no-pager -o cat 2>/dev/null | grep -viE 'senha|password' | grep -iE 'error|warn|license|slot|empresa|contrat' | tail -10
