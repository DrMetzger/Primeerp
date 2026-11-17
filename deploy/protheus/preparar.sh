#!/bin/bash
# Protheus na VM, etapa 1: PostgreSQL + banco, unixODBC/psqlODBC e Syncthing
# (fontes e instaladores vindos do PC do Kleber). Idempotente: pode rodar de
# novo sem estragar nada. Não imprime senha (o log do Actions é público).
set -euo pipefail
BASE=/opt/totvs
umask 022

echo "== 1/5 pacotes"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq postgresql postgresql-contrib unixodbc odbc-postgresql syncthing jq unzip >/dev/null
echo "PostgreSQL $(psql --version | awk '{print $3}') | unixODBC $(odbcinst --version | awk '{print $2}') | Syncthing $(syncthing --version | awk '{print $2}')"

echo "== 2/5 usuário e pastas"
id totvs >/dev/null 2>&1 || useradd --system --create-home --home-dir /home/totvs --shell /bin/bash totvs
mkdir -p $BASE/fontes/FONTES_PROTHEUS $BASE/fontes/Fontes_2510 $BASE/instaladores $BASE/protheus
chown -R totvs:totvs $BASE
[ -f $BASE/.env ] || ( umask 077; printf 'PG_SENHA=%s\n' "$(openssl rand -hex 24)" > $BASE/.env )
chmod 600 $BASE/.env; chown root:root $BASE/.env
set -a; . $BASE/.env; set +a

echo "== 3/5 PostgreSQL (só local, memória contida)"
PGV=$(ls /etc/postgresql | sort -V | tail -1)
CONF=/etc/postgresql/$PGV/main/conf.d/protheus.conf
mkdir -p "$(dirname $CONF)"
cat > $CONF <<CONFPG
# Ajuste para o Protheus de desenvolvimento (1 pessoa) dividindo a VM.
listen_addresses = 'localhost'
max_connections = 60
shared_buffers = 512MB
effective_cache_size = 1536MB
work_mem = 8MB
maintenance_work_mem = 128MB
standard_conforming_strings = on
CONFPG
chmod 644 $CONF; chown postgres:postgres $CONF
systemctl enable postgresql >/dev/null 2>&1
pg_ctlcluster "$PGV" main restart 2>&1 || true
for i in $(seq 1 30); do pg_isready -q && break; sleep 2; done
if ! pg_isready -q; then
  echo "ERRO: PostgreSQL não subiu"; pg_lsclusters
  tail -20 "/var/log/postgresql/postgresql-$PGV-main.log" 2>/dev/null | grep -viE 'password|senha'
  exit 1
fi
echo "PostgreSQL $PGV no ar"
sudo -u postgres psql -qtAc "SELECT 1 FROM pg_roles WHERE rolname='protheus'" | grep -q 1 \
  || sudo -u postgres psql -qc "CREATE ROLE protheus LOGIN PASSWORD '${PG_SENHA}'"
sudo -u postgres psql -qc "ALTER ROLE protheus PASSWORD '${PG_SENHA}'" >/dev/null
# Padrão TOTVS para Protheus em PostgreSQL: WIN1252 e collate/ctype C.
sudo -u postgres psql -qtAc "SELECT 1 FROM pg_database WHERE datname='protheus'" | grep -q 1 \
  || sudo -u postgres psql -qc "CREATE DATABASE protheus OWNER protheus ENCODING 'WIN1252' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0"
sudo -u postgres psql -qtAc "SELECT 'banco protheus: ' || pg_encoding_to_char(encoding) || ', collate ' || datcollate FROM pg_database WHERE datname='protheus'"

echo "== 4/5 ODBC (DSN protheus para o DBAccess)"
DRV=$(odbcinst -q -d | tr -d '[]' | grep -i 'postgresql ansi' | head -1)
cat > /etc/odbc.ini <<ODBC
[protheus]
Description = Protheus 12.1.2510 (PostgreSQL local)
Driver      = ${DRV}
Servername  = localhost
Port        = 5432
Database    = protheus
Username    = protheus
UseDeclareFetch = 1
Fetch       = 2048
ODBC
chmod 644 /etc/odbc.ini
echo "SELECT 'conexao ODBC ok';" | isql -b protheus protheus "$PG_SENHA" 2>&1 | grep -q 'conexao ODBC ok' && echo "ODBC: conectou no banco protheus" || { echo "ERRO: ODBC não conectou"; exit 1; }

echo "== 5/5 Syncthing (PC do Kleber <-> VM)"
unset PG_SENHA
ls /lib/systemd/system/ | grep -i syncthing || echo "sem unidade systemd do syncthing"
systemctl enable syncthing@totvs 2>&1 || echo "aviso: enable falhou"
systemctl restart syncthing@totvs 2>&1 || { echo "ERRO ao iniciar"; systemctl --no-pager status syncthing@totvs 2>&1 | tail -15; journalctl -u syncthing@totvs -n 20 --no-pager 2>&1 | tail -20; exit 1; }
ST() { sudo -u totvs -H syncthing cli "$@"; }
for i in $(seq 1 45); do ST show system >/dev/null 2>&1 && break; sleep 2; done
if ! ST show system >/dev/null 2>&1; then
  echo "ERRO: Syncthing não respondeu"; systemctl --no-pager status syncthing@totvs | tail -15; ST show system 2>&1 | tail -3; exit 1
fi
ST config options relays-enabled set true 2>&1 | grep -v '^$' || true
PASTAS=$(ST config folders list 2>&1 || true)
for p in "fontes-proprios:FONTES_PROTHEUS:$BASE/fontes/FONTES_PROTHEUS" "fontes-2510:Fontes_2510:$BASE/fontes/Fontes_2510" "instaladores:instaladores:$BASE/instaladores"; do
  IFS=: read -r id rotulo caminho <<<"$p"
  echo "$PASTAS" | grep -qx "$id" || ST config folders add --id "$id" --label "$rotulo" --path "$caminho"
done
# Dispositivos autorizados (só os listados no repositório; ID do Syncthing não é segredo).
LISTA="$(dirname "$0")/syncthing-dispositivos.txt"
if [ -f "$LISTA" ]; then
  { grep -v '^#' "$LISTA" || true; } | while read -r dev nome; do
    [ -n "${dev:-}" ] || continue
    ST config devices list | grep -qx "$dev" || ST config devices add --device-id "$dev" --name "${nome:-PC}"
    for id in fontes-proprios fontes-2510 instaladores; do
      ST config folders "$id" devices list | grep -qx "$dev" || ST config folders "$id" devices add --device-id "$dev"
    done
    echo "dispositivo autorizado: ${nome:-PC}"
  done
fi
ufw allow 22000/tcp >/dev/null; ufw allow 22000/udp >/dev/null
echo "ID da VM no Syncthing: $(ST show system | jq -r .myID)"
echo "pastas: $(ST config folders list | tr '\n' ' ')"
echo "arquivos já na VM: instaladores=$(find $BASE/instaladores -type f | wc -l) FONTES_PROTHEUS=$(find $BASE/fontes/FONTES_PROTHEUS -type f | wc -l) Fontes_2510=$(find $BASE/fontes/Fontes_2510 -type f | wc -l)"
echo "== memória agora"; free -h | head -2
