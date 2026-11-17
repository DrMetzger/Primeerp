#!/bin/bash
# Protheus na VM, etapa 3: DBAccess 26.1.0.1 -> PostgreSQL, AppServer
# 26.3.0.1 com o ambiente no DBAccess, serviço com limite de memória e
# firewall só para o Caddy local. Idempotente. Não imprime senha.
set -eu
set +o pipefail
BASE=/opt/totvs
P=$BASE/protheus12
DBA=$P/dbaccess
APP=$P/protheus/bin/appserver
set -a; . $BASE/.env; set +a
[ -f $APP/appserver.ini ] || { echo "Protheus ainda não instalado"; exit 0; }

echo "== 1/4 DBAccess 26.1.0.1 + PostgreSQL"
if [ ! -f $DBA/.build-26.1.0.1 ]; then
  systemctl stop totvsdbaccess || true
  cp -a $DBA $DBA.original-$(date +%Y%m%d) 2>/dev/null || true
  cp -f $BASE/dbaccess/multi/dbaccess64 $BASE/dbaccess/library/dbaccess64.so $DBA/ 2>/dev/null || true
  cp -rf $BASE/dbaccess/tools/dbaccesscfg $DBA/ 2>/dev/null || true
  chmod +x $DBA/dbaccess64 $DBA/dbaccesscfg 2>/dev/null || true
  touch $DBA/.build-26.1.0.1
fi
ODBCLIB=$(ls /usr/lib/x86_64-linux-gnu/libodbc.so* 2>/dev/null | sort | head -1)
( cd $DBA && ./dbaccesscfg -u protheus -p "$PG_SENHA" -d postgres -a protheus -c "$ODBCLIB" -g "LicenseServer=localhost;LicensePort=5555;ByYouProc=0" 2>&1 | grep -viE 'senha|password|-p ' | tail -5 ) || true
grep -q '^\[POSTGRES\]' $DBA/dbaccess.ini && echo "dbaccess.ini: seção POSTGRES ok" || echo "AVISO: dbaccess.ini sem POSTGRES"
grep -viE 'password|senha' $DBA/dbaccess.ini | grep -v '^\s*$'
systemctl restart totvsdbaccess; sleep 5
echo "totvsdbaccess: $(systemctl is-active totvsdbaccess)"
journalctl -u totvsdbaccess -n 12 --no-pager 2>/dev/null | grep -iE 'error|erro|fail|connect|postgres|license' | grep -viE 'senha|password' | tail -8 || true

ALVO=$(grep -m1 '^APPSERVER_ALVO=' "$(dirname "$0")/versoes.conf" 2>/dev/null | cut -d= -f2)
ALVO=${ALVO:-instalador}
FORCAR=0
echo "== 2/4 AppServer ($ALVO) + ambiente no DBAccess"
ORIG=$(ls -d $APP.original-* 2>/dev/null | sort | head -1)
if [ "$ALVO" = "instalador" ] && [ ! -f $APP/.build-instalador ] && [ -n "$ORIG" ]; then
  # Volta o par AppServer+Webapp do instalador (mantém o appserver.ini atual).
  cp -f $APP/appserver.ini /tmp/appserver.ini.manter
  find $ORIG -mindepth 1 -maxdepth 1 ! -name 'appserver.ini' -exec cp -rf {} $APP/ \;
  cp -f /tmp/appserver.ini.manter $APP/appserver.ini
  rm -f $APP/.build-26.3.0.1 $APP/.webapp-*; touch $APP/.build-instalador
  echo "AppServer e Webapp do instalador restaurados (combinam com o RPO de 06/10/2025)"
  FORCAR=1
fi
if [ "$ALVO" = "26.3.0.1" ] && [ ! -f $APP/.build-26.3.0.1 ]; then
  rm -f $APP/.build-instalador
  FORCAR=1
  cp -a $APP $APP.original-$(date +%Y%m%d)
  TMPA=$(mktemp -d)
  tar -xzf "$(ls $BASE/instaladores/*APPSERVER*LINUX*.TAR.GZ | head -1)" -C $TMPA
  cp -f $APP/appserver.ini $TMPA/appserver.ini.manter
  cp -rf $TMPA/. $APP/ && mv -f $APP/appserver.ini.manter $APP/appserver.ini
  rm -rf $TMPA
  chmod +x $APP/appsrvlinux
  touch $APP/.build-26.3.0.1
fi
INI=$APP/appserver.ini
ANTES=$(md5sum $INI $APP/appsrvlinux $APP/webapp.so /etc/systemd/system/totvsappserver.service 2>/dev/null | md5sum)
# Webapp (SmartClient HTML) do mesmo nível do AppServer 26.x, quando estiver nos instaladores.
WTAR=$(ls $BASE/instaladores/*SMARTCLIENT_WEBAPP*LINUX*.TAR.GZ 2>/dev/null | sort | tail -1)
if [ -n "$WTAR" ] && [ "$ALVO" = "26.3.0.1" ]; then
  WVER=$(basename "$WTAR" | grep -oE 'WEBAPP_[0-9.]+' | cut -d_ -f2 | sed 's/\.$//')
  if [ ! -f $APP/.webapp-$WVER ]; then
    echo "--- Webapp $WVER: conteúdo do pacote"; tar -tzf "$WTAR" | head -20
    TMPW=$(mktemp -d); tar -xzf "$WTAR" -C $TMPW
    SO=$(find $TMPW -name 'webapp*.so' | head -1)
    if [ -n "$SO" ]; then
      cp -f $APP/webapp.so $APP/webapp.so.anterior 2>/dev/null || true
      cp -f "$SO" $APP/webapp.so
      # demais arquivos do pacote que acompanham o .so (ex.: pasta web/), sem sobrescrever o ini
      find $TMPW -mindepth 1 -maxdepth 1 ! -name '*.so' ! -iname '*.ini' -exec cp -rf {} $APP/ \;
      rm -f $APP/.webapp-*; touch $APP/.webapp-$WVER
      echo "Webapp $WVER instalado (o anterior ficou como webapp.so.anterior)"
    else echo "AVISO: pacote do Webapp sem webapp.so"; fi
    rm -rf $TMPW
  fi
fi
sed -i '1s/^\xEF\xBB\xBF//; s/\r$//' $INI  # sem BOM e sem quebra de linha do Windows
# chave=valor dentro de uma seção (cria a seção/chave se faltar)
# appserver.ini escrito inteiro, no modelo da PrimeERP (orientação TOTVS):
# só o ambiente PRIMEERP, banco pelo DBAccess/PostgreSQL, sem CTREE
# (o AppServer 26 não tem mais esse driver: arquivos locais em SQLITE).
[ -f $INI.instalador ] || cp -f $INI $INI.instalador
cat > $INI.novo <<'APPINI'
[PRIMEERP]
SourcePath=/opt/totvs/protheus12/protheus/apo/
RPOCustom=/opt/totvs/protheus12/protheus/apo/custom.rpo
RootPath=/opt/totvs/protheus12/protheus_data
StartPath=/system/
x2_path=
RpoDb=top
RpoLanguage=multi
RpoVersion=120
LocalFiles=SQLITE
Trace=0
localdbextension=.dtc
StartSysInDB=1
TopMemoMega=1
TopDataBase=POSTGRES
TopAlias=protheus
TopServer=localhost
TopPort=7890

[DBAccess]
PROTHEUSONLY=0

[Drivers]
Active=TCP
MULTIPROTOCOLPORT=1
MULTIPROTOCOLPORTSECURE=0

[TCP]
TYPE=TCPIP
Port=1234

[Service]
Name=totvsappserver
DisplayName=TOTVS | APPSERVER PRIMEERP

[LICENSECLIENT]
server=localhost
port=5555

[GENERAL]
consolelog=1
MaxStringSize=30
MaxQuerySize=65536
APP_ENVIRONMENT=PRIMEERP
buildkillusers=1

[WEBAPP]
Port=8089

[WebApp/webapp]
MPP=
APPINI
cmp -s $INI.novo $INI || cp -f $INI.novo $INI
rm -f $INI.novo
# DBAccess com o mesmo MaxStringSize do AppServer.
if grep -qi '^MAXSTRINGSIZE=' $DBA/dbaccess.ini && ! grep -qi '^MAXSTRINGSIZE=30$' $DBA/dbaccess.ini; then
  sed -i 's/^MAXSTRINGSIZE=.*/MAXSTRINGSIZE=30/I' $DBA/dbaccess.ini && systemctl restart totvsdbaccess && echo "DBAccess: MaxStringSize=30"
fi
grep -viE 'password|senha' $INI | grep -v '^\s*$' | head -60

echo "== 3/4 serviço do AppServer (limite 2 GB)"
cat > /etc/systemd/system/totvsappserver.service <<UNIT
[Unit]
Description=TOTVS AppServer Protheus 12.1.2510 (desenvolvimento)
After=network.target totvsdbaccess.service licenseVirtual.service

[Service]
Type=simple
WorkingDirectory=$APP
Environment=LD_LIBRARY_PATH=$APP
ExecStart=$APP/appsrvlinux
Restart=on-failure
RestartSec=15
LimitNOFILE=65536
LimitSTACK=1048576
MemoryMax=2G
TasksMax=4096

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload; systemctl enable totvsappserver >/dev/null 2>&1
DEPOIS=$(md5sum $INI $APP/appsrvlinux $APP/webapp.so /etc/systemd/system/totvsappserver.service 2>/dev/null | md5sum)
# Só reinicia se algo mudou (ou se estiver parado): o log do AppServer fica guardado para diagnóstico.
if [ "$FORCAR" = 1 ] || [ "$ANTES" != "$DEPOIS" ] || ! systemctl is-active -q totvsappserver; then
  echo "AppServer: configuração mudou, reiniciando"
  systemctl stop totvsappserver; sleep 3; pkill -f "$APP/appsrvlinux" || true; sleep 2
  cp -f $APP/console.log $APP/console.anterior.log 2>/dev/null || true
  : > $APP/console.log
  systemctl start totvsappserver
  sleep 30
else
  echo "AppServer: nada mudou, segue rodando (sem reiniciar)"
fi
echo "totvsappserver: $(systemctl is-active totvsappserver)"
echo "--- log do serviço"; journalctl -u totvsappserver -n 40 --no-pager -o cat 2>/dev/null | grep -viE 'senha|password' | tail -30 || true
echo "--- console.log (início desta partida)"
grep -vE 'Memory|memory|^\s*$|\[\.|\[=|PID +MEM|^ *[0-9]+\*? +[0-9]+ +[0-9]+d|tot +lim|Percentages|Turn on' $APP/console.log 2>/dev/null | grep -viE 'senha|password' | head -70 || true
echo "--- erros"; grep -iE 'error|erro|fatal|invalid|cannot|unable|fail|abort|denied|not found|nao encontrad' $APP/console.log 2>/dev/null | grep -viE 'senha|password' | head -20 || true
curl -s -o /dev/null -w "webapp local (8089): HTTP %{http_code}\n" --max-time 10 http://127.0.0.1:8089/ || echo "webapp local: sem resposta"

echo "== 4/4 firewall: Webapp só para o Caddy local"
SUB=$(docker network inspect vanguard_default -f '{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null || true)
[ -n "$SUB" ] && ufw allow from "$SUB" to any port 8089 proto tcp >/dev/null && echo "8089 liberada só para $SUB (rede do Caddy)"
echo "gateway para o Caddy: $(docker network inspect vanguard_default -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null)"
echo "== portas e memória"; ss -tlnH | awk '{print $4}' | sed 's/.*://' | sort -n | uniq | tr '\n' ' '; echo; free -h | head -2
systemctl --no-pager --type=service --state=running | grep -iE 'totvs|license|postgres' | awk '{print $1, $4}'
