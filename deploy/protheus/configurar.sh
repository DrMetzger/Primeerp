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

echo "== 2/4 AppServer 26.3.0.1 + ambiente no DBAccess"
if [ ! -f $APP/.build-26.3.0.1 ]; then
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
sed -i '1s/^\xEF\xBB\xBF//; s/\r$//' $INI  # sem BOM e sem quebra de linha do Windows
# chave=valor dentro de uma seção (cria a seção/chave se faltar)
ini() { local sec=$1 k=$2 v=$3
  grep -q "^\[$sec\]" $INI || printf '\n[%s]\n' "$sec" >> $INI
  if awk -v s="[$sec]" -v k="$k" 'BEGIN{IGNORECASE=1} $0==s{f=1;next} /^\[/{f=0} f && tolower($0) ~ "^"tolower(k)"=" {e=1} END{exit !e}' $INI; then
    awk -v s="[$sec]" -v k="$k" -v v="$v" 'BEGIN{IGNORECASE=1} $0==s{f=1;print;next} /^\[/{f=0} f && tolower($0) ~ "^"tolower(k)"=" {print k"="v;next} {print}' $INI > $INI.tmp && mv $INI.tmp $INI
  else
    awk -v s="[$sec]" -v k="$k" -v v="$v" '{print} $0==s{print k"="v}' $INI > $INI.tmp && mv $INI.tmp $INI
  fi; }
# Ambiente se chama PRIMEERP (pedido do Kleber), não "environment".
if grep -qi '^\[environment\]' $INI && ! grep -qi '^\[PRIMEERP\]' $INI; then
  sed -i 's/^\[environment\]$/[PRIMEERP]/I' $INI
fi
ini PRIMEERP DBDataBase POSTGRES
ini PRIMEERP DBServer localhost
ini PRIMEERP DBAlias protheus
ini PRIMEERP DBPort 7890
ini General MAXSTRINGSIZE 10
ini General ConsoleLog 1
ini DBAccess Database POSTGRES
ini DBAccess Server localhost
ini DBAccess Alias protheus
ini DBAccess Port 7890
# Um AppServer só (sem balanceamento): sem a seção LockServer (com ela, o build 26.3 recusa subir).
awk 'BEGIN{d=0} /^\[/{d=(toupper($0)=="[LOCKSERVER]")} !d' $INI > $INI.tmp && mv $INI.tmp $INI
echo "LockServer no ini: $(grep -ci '^\[lockserver\]' $INI) seção(ões)"
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
systemctl stop totvsappserver; sleep 3; pkill -f "$APP/appsrvlinux" || true; sleep 2
: > $APP/console.log
systemctl start totvsappserver
sleep 30
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
