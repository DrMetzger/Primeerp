#!/bin/bash
# Protheus na VM, etapa 2: License Server Virtual, Protheus 12.1.2510
# (instalador IzPack sem tela), DBAccess e AppServer no build baixado.
# Idempotente. Não imprime senha.
set -euo pipefail
BASE=/opt/totvs
TRAB=$BASE/trabalho
umask 022
[ -f $TRAB/protheus/installer_lnx.jar ] && [ -f $TRAB/license/installer.jar ] || { echo "instaladores ainda não preparados"; exit 0; }

# Serviço systemd para um AppServer (o instalador TOTVS só registra serviço em Red Hat/Oracle/SUSE).
servico() {  # nome  pasta_do_binário  memória_máx  descrição
  local nome=$1 dir=$2 mem=$3 desc=$4 bin
  bin=""
  for b in "$dir"/appsrvlinux "$dir"/dbaccess64; do [ -f "$b" ] && { bin=$b; break; }; done
  [ -n "$bin" ] || { echo "AVISO: binário não encontrado em $dir"; return 0; }
  chmod +x "$bin"
  cat > /etc/systemd/system/$nome.service <<UNIT
[Unit]
Description=$desc
After=network.target postgresql.service

[Service]
Type=simple
WorkingDirectory=$dir
Environment=LD_LIBRARY_PATH=$dir
ExecStart=$bin
Restart=on-failure
RestartSec=10
LimitNOFILE=65536
LimitSTACK=infinity
MemoryMax=$mem
TasksMax=4096

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
  systemctl enable $nome >/dev/null 2>&1
  systemctl restart $nome
  sleep 5
  echo "$nome: $(systemctl is-active $nome) ($(basename $bin) em $dir)"
}

echo "== A/4 License Server Virtual (porta 5555)"
if [ ! -d $BASE/licenseserver ]; then
  cat > $TRAB/license/installer.properties <<P
INSTALL_PATH=$BASE/licenseserver
LICENSE_INSTALL=install
SERVICE_PORT=2234
LOG_PORT=4000
LICENSE_PORT=5555
SERVICE_NAME=licenseVirtual
PROXY_ENABLE=0
PROXY_SERVER=
PROXY_PORT=
PROXY_USER=
P
  # "S" = seguir adiante quando o instalador não conseguir registrar o serviço (Ubuntu).
  ( cd $TRAB/license && yes S | timeout 900 java -Djava.awt.headless=true -jar installer.jar -options installer.properties -language bra 2>&1 | grep -viE 'senha|password' | tail -8 ) || true
fi
echo "--- arquivos"; find $BASE/licenseserver -maxdepth 3 \( -name appsrvlinux -o -iname '*.ini' \) 2>/dev/null
LDIR=$(dirname "$(find $BASE/licenseserver -name appsrvlinux 2>/dev/null | head -1)")
[ "$LDIR" != "." ] && servico licenseVirtual "$LDIR" 512M "TOTVS License Server Virtual"
(ss -tlnH | awk '{print $4}' | grep -E ':(5555|2234|4000)$' | sed 's/^/  ouvindo /') || echo "  License ainda não está ouvindo"
[ "$LDIR" != "." ] && journalctl -u licenseVirtual -n 8 --no-pager 2>/dev/null | grep -viE 'senha|password' | tail -8

echo "== B/4 Protheus 12.1.2510 (RPO, dicionário, Webapp)"
if [ ! -d $BASE/protheus12 ]; then
  cat > $TRAB/protheus/opcoes.properties <<P
INSTALL_PATH=$BASE/protheus12
REGIONAL_LANGUAGE=bra
SERVICE_PORT=1234
APPSERVER_SNAME=totvsappserver
APPSERVER_SDISP=Protheus12
WEBAPP_PORT=8089
LICENSE_SERVER=localhost
LICENSE_PORT=5555
P
  ( cd $TRAB/protheus && yes S | timeout 2400 java -Djava.awt.headless=true -Xmx768m -jar installer_lnx.jar -options opcoes.properties 2>&1 | grep -viE 'senha|password' | tail -20 ) || true
fi
echo "--- estrutura"; find $BASE/protheus12 -maxdepth 3 -type d 2>/dev/null | head -40
echo "--- RPO e INIs"; find $BASE/protheus12 -maxdepth 5 \( -iname '*.rpo' -o -iname '*.ini' \) 2>/dev/null | head -20

echo "== C/4 DBAccess 26.1.0.1"
if [ ! -d $BASE/dbaccess ]; then
  mkdir -p $BASE/dbaccess
  tar -xzf "$(ls $BASE/instaladores/*DBACCESS*LINUX*.TAR.GZ | head -1)" -C $BASE/dbaccess
fi
ls $BASE/dbaccess
echo "--- dbaccesscfg (ajuda)"; ( cd $BASE/dbaccess/tools && timeout 20 ./dbaccesscfg -h 2>&1 | head -40 ) || true

echo "== D/4 serviços criados e INIs (sem senhas)"
systemctl list-unit-files 2>/dev/null | grep -iE 'totvs|license|appserver|dbaccess' || echo "nenhum serviço systemd TOTVS"
ls /etc/init.d 2>/dev/null | grep -iE 'totvs|license|appserver|dbaccess' || true
for ini in $(find $BASE/protheus12 $BASE/licenseserver -maxdepth 5 -iname 'appserver.ini' 2>/dev/null | head -3); do
  echo "--- $ini"; grep -viE 'senha|password|pass=' "$ini" | head -60
done
echo "== disco"; df -h / | tail -1; du -sh $BASE/protheus12 $BASE/licenseserver $BASE/dbaccess 2>/dev/null
