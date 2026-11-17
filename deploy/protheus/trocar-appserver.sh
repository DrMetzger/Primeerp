#!/bin/bash
# Troca o AppServer do Protheus pelo pacote indicado em versoes.conf
# (APPSERVER_ALVO=24.3.1.9) e o RPO padrão pelo repositório 12.1.2510 dos
# instaladores (renomeado para tttm120.rpo). Apaga o AppServer anterior,
# mantendo só o appserver.ini e o Webapp que já funcionavam. Sobe a pilha.
set -eu
set +o pipefail
BASE=/opt/totvs
P=$BASE/protheus12
APP=$P/protheus/bin/appserver
APO=$P/protheus/apo
INST=$BASE/instaladores
ALVO=$(grep -m1 '^APPSERVER_ALVO=' "$(dirname "$0")/versoes.conf" | cut -d= -f2)
TAR=$(ls $INST/*APPSERVER_BUILD-${ALVO}_LINUX*.TAR.GZ 2>/dev/null | head -1)
RPO=$(ls $INST/*REPOSITORIO_DE_OBJETOS*TTTM120*.RPO 2>/dev/null | sort | tail -1)
[ -n "$TAR" ] || { echo "ERRO: pacote do AppServer $ALVO não está em $INST"; exit 1; }

echo "== 1/5 parando o AppServer"
systemctl stop totvsappserver 2>/dev/null || true; sleep 2; pkill -f "$APP/appsrvlinux" || true

if [ -f $APP/.build-$ALVO ]; then
  echo "AppServer $ALVO já instalado; nada a trocar"
else
  echo "== 2/5 apagando o AppServer atual e instalando $(basename $TAR)"
  GUARDA=$(mktemp -d)
  cp -a $APP/appserver.ini $GUARDA/
  cp -a $APP/webapp.so $GUARDA/ 2>/dev/null || true
  [ -d $APP/webapp ] && cp -a $APP/webapp $GUARDA/ || true
  rm -rf $APP $APP.original-*
  mkdir -p $APP
  tar -xzf "$TAR" -C $APP
  cp -a $GUARDA/. $APP/
  rm -rf $GUARDA
  chmod +x $APP/appsrvlinux
  touch $APP/.build-$ALVO
  echo "AppServer instalado: $(ls $APP | wc -l) arquivos; Webapp mantido: $([ -f $APP/webapp.so ] && echo sim || echo NÃO)"
fi

echo "== 3/5 RPO padrão"
if [ -n "$RPO" ]; then
  if cmp -s "$RPO" $APO/tttm120.rpo; then echo "tttm120.rpo já é $(basename $RPO)"
  else
    rm -f $APO/tttm120.rpo
    cp -f "$RPO" $APO/tttm120.rpo
    echo "tttm120.rpo = $(basename $RPO) ($(du -h $APO/tttm120.rpo | cut -f1))"
  fi
else echo "AVISO: RPO 12.1.2510 não encontrado em $INST; mantido o atual"; fi
[ -f $APO/custom.rpo ] || : > $APO/custom.rpo
chown -R totvs:totvs $APO 2>/dev/null || true

echo "== 4/5 limite de memória do serviço (3 GB)"
sed -i 's/^MemoryMax=.*/MemoryMax=3G/' /etc/systemd/system/totvsappserver.service
systemctl daemon-reload

echo "== 5/5 subindo a pilha (PostgreSQL, License, DBAccess, AppServer)"
for u in postgresql@16-main licenseVirtual totvsdbaccess; do
  systemctl is-active -q $u || { systemctl start $u; sleep 3; }
  echo "$u: $(systemctl is-active $u)"
done
cp -f $APP/console.log $APP/console.anterior.log 2>/dev/null || true
: > $APP/console.log
systemctl start totvsappserver
for i in $(seq 1 40); do ss -tlnH | grep -q ':8089 ' && break; sleep 3; done
echo "totvsappserver: $(systemctl is-active totvsappserver) | porta 8089: $(ss -tlnH | grep -q ':8089 ' && echo aberta || echo FECHADA) | 1234: $(ss -tlnH | grep -q ':1234 ' && echo aberta || echo FECHADA)"
grep -aiE 'build|version|error|fatal|invalid|rpo' $APP/console.log | grep -viE 'senha|password' | head -25
