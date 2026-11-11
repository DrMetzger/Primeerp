#!/bin/bash
# Instala/atualiza o Prime Hub na VM (rodado pelo workflow "VM - implantar
# Prime Hub", como root). Variáveis recebidas pelo stdin do workflow:
#   FTP_HOST FTP_USR FTP_PSW  -- pra buscar o banco/uploads da Hostinger
#   IMPORTAR_BANCO=1          -- baixa e importa o .sql.gz exportado na Hostinger
#   DUMP_SHA256               -- confere se o arquivo chegou inteiro
set -euo pipefail
BASE=/opt/primehub
cd "$BASE"

echo "== 1/6 código"
rm -rf app.novo && mkdir app.novo && tar -xzf /tmp/primehub-app.tar.gz -C app.novo && rm -f /tmp/primehub-app.tar.gz
rm -rf app.anterior && { [ -d app ] && mv app app.anterior || true; } && mv app.novo app
cp app/deploy/vm/docker-compose.yml docker-compose.yml
mkdir -p dados/uploads dados/avatars dados/mysql migracao

if [ ! -f .env ]; then
  echo "== .env novo (senhas do banco geradas aqui, nunca saem da VM)"
  umask 077
  printf 'DB_PASS=%s\nDB_ROOT_PASS=%s\nSEM_NOTIFICACOES=1\nPRIMEHUB_CRON=0\n' "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" > .env
fi
chmod 600 .env
set -a; . ./.env; set +a

echo "== 2/6 containers"
docker compose version --short
docker compose build --pull -q web
docker compose up -d
for i in $(seq 1 60); do docker inspect -f '{{.State.Health.Status}}' primehub-db 2>/dev/null | grep -q healthy && break; sleep 2; done
docker inspect -f 'banco: {{.State.Health.Status}}' primehub-db

if [ "${IMPORTAR_BANCO:-0}" = "1" ]; then
  echo "== 3/6 banco: baixando da Hostinger"
  H="${FTP_HOST#ftp://}"; H="${H%/}"
  printf 'user = "%s:%s"\n' "$FTP_USR" "$FTP_PSW" > /root/.primehub-ftp && chmod 600 /root/.primehub-ftp
  curl -sS --fail -K /root/.primehub-ftp "ftp://$H/AdminPrime/config/migracao/primehub.sql.gz" -o migracao/primehub.sql.gz
  echo "$DUMP_SHA256  migracao/primehub.sql.gz" | sha256sum -c --quiet && echo "arquivo conferido ($(du -h migracao/primehub.sql.gz | cut -f1))"
  echo "== importando"
  zcat migracao/primehub.sql.gz | docker exec -i -e MYSQL_PWD="$DB_ROOT_PASS" primehub-db mariadb -uroot primehub
  docker exec -e MYSQL_PWD="$DB_ROOT_PASS" primehub-db mariadb -uroot -N primehub -e "SELECT CONCAT('chamados: ', COUNT(*)) FROM chamados; SELECT CONCAT('clientes: ', COUNT(*)) FROM clientes; SELECT CONCAT('respostas: ', COUNT(*)) FROM chamado_respostas;"
  rm -f migracao/primehub.sql.gz

  echo "== 4/6 arquivos (uploads e fotos) da Hostinger"
  docker run --rm -e LFTP_PASSWORD="$FTP_PSW" -v "$BASE/dados/uploads:/u" -v "$BASE/dados/avatars:/a" alpine:3.20 sh -c \
    "apk add -q lftp >/dev/null && lftp -u '$FTP_USR' --env-password -e 'set ssl:verify-certificate no; set ftp:ssl-allow no; mirror --only-newer --parallel=4 AdminPrime/uploads /u; mirror --only-newer AdminPrime/assets/avatars /a; quit' '$H'" \
    && echo "arquivos copiados: $(find dados/uploads dados/avatars -type f | wc -l)"
  rm -f /root/.primehub-ftp
  docker restart primehub-web >/dev/null
fi

echo "== 5/6 Caddy do vanguard: suporte.primeerp.com.br -> Prime Hub"
BLOCO='
# Prime Hub (adicionado pela migração, ver /opt/primehub)
suporte.primeerp.com.br {
	encode gzip
	reverse_proxy primehub-web:80
}'
docker cp vanguard-web-1:/etc/caddy/Caddyfile migracao/Caddyfile.atual
if ! grep -q 'suporte.primeerp.com.br' migracao/Caddyfile.atual; then
  [ -f migracao/Caddyfile.original ] || cp migracao/Caddyfile.atual migracao/Caddyfile.original
  { cat migracao/Caddyfile.atual; echo "$BLOCO"; } > migracao/Caddyfile.novo
  docker cp migracao/Caddyfile.novo vanguard-web-1:/etc/caddy/Caddyfile
  if docker exec vanguard-web-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 \
     && docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile; then
    echo "Caddy recarregado sem reiniciar (vanguard não parou)"
  else
    echo "ERRO no Caddy -- voltando a configuração original"
    docker cp migracao/Caddyfile.atual vanguard-web-1:/etc/caddy/Caddyfile
    docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
    exit 1
  fi
  # Também no código-fonte do vanguard, pra sobreviver a um rebuild dele.
  if [ -f /opt/vanguard/app/web/Caddyfile ] && ! grep -q 'suporte.primeerp.com.br' /opt/vanguard/app/web/Caddyfile; then
    cp /opt/vanguard/app/web/Caddyfile /opt/vanguard/app/web/Caddyfile.antes-primehub
    echo "$BLOCO" >> /opt/vanguard/app/web/Caddyfile
    echo "bloco também gravado em /opt/vanguard/app/web/Caddyfile (backup .antes-primehub)"
  fi
else
  echo "já configurado"
fi

echo "== 6/6 conferindo"
sleep 5
for u in login.php portal/login.php; do
  printf '%s: ' "$u"; curl -sS -o /dev/null -w '%{http_code}\n' --max-time 60 "https://suporte.primeerp.com.br/$u" || true
done
printf 'vanguard (admin): '; curl -sS -o /dev/null -w '%{http_code}\n' --max-time 30 "https://${ADMIN_DOMAIN_TESTE:-admin.erpvanguard.com.br}/" || true
