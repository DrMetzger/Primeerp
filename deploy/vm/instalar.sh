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
# Depois do corte (arquivo deploy/vm/PRODUCAO no repositório): e-mail,
# WhatsApp e cron ligados -- o servidor novo é o oficial.
if [ -f app/deploy/vm/PRODUCAO ]; then
  sed -i 's/^SEM_NOTIFICACOES=.*/SEM_NOTIFICACOES=0/; s/^PRIMEHUB_CRON=.*/PRIMEHUB_CRON=1/' .env
  grep -q '^SEM_NOTIFICACOES=' .env || echo 'SEM_NOTIFICACOES=0' >> .env
  grep -q '^PRIMEHUB_CRON=' .env || echo 'PRIMEHUB_CRON=1' >> .env
  echo "== PRODUÇÃO: notificações e cron ligados"
fi
# Segredos do sistema novo (login da API e ponte com o PHP), gerados uma vez.
grep -q '^JWT_SECRET=' .env || echo "JWT_SECRET=$(openssl rand -hex 32)" >> .env
grep -q '^SSO_SECRET=' .env || echo "SSO_SECRET=$(openssl rand -hex 32)" >> .env
# Chave da cifra das senhas do cofre de acessos: a MESMA que já cifrou as
# senhas gravadas (lida da configuração de produção do sistema de sempre),
# guardada só no .env da VM -- o servidor novo e o antigo leem daqui.
if ! grep -q '^CREDENCIAIS_CIFRA_CHAVE=' .env; then
  CHAVE_COFRE=$(grep -A2 "define('CREDENCIAIS_CIFRA_CHAVE'" app/config/config.php | grep -o "'[A-Za-z0-9+/=]\{44\}'" | tail -1 | tr -d "'")
  [ -n "$CHAVE_COFRE" ] && echo "CREDENCIAIS_CIFRA_CHAVE=$CHAVE_COFRE" >> .env && echo "== chave do cofre configurada"
fi
chmod 600 .env
set -a; . ./.env; set +a
mkdir -p dados/downloads

echo "== 2/6 containers"
docker compose version --short
docker compose build --pull -q web api next
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
# Sistema novo (site + /api) e telas clássicas (*.php, arquivos) no mesmo endereço.
BLOCO='# >>> Prime Hub (gerenciado por /opt/primehub -- não editar à mão)
suporte.primeerp.com.br {
	encode gzip
	@classico {
		path *.php /assets/* /uploads/* /vendor/*
		not path /webhook_whatsapp.php
	}
	handle /webhook_whatsapp.php {
		rewrite * /api/whatsapp/webhook?{query}
		reverse_proxy primehub-api:3000
	}
	handle /api/* {
		reverse_proxy primehub-api:3000
	}
	handle /downloads/* {
		reverse_proxy primehub-api:3000
	}
	handle @classico {
		reverse_proxy primehub-web:80
	}
	handle {
		reverse_proxy primehub-next:3000
	}
}
# <<< Prime Hub'
# Remove o bloco anterior (formato antigo ou novo) e põe o atual.
tirar_bloco() { awk '/^# (>>> )?Prime Hub/{s=1;next} s==1{if(/^}/)s=2;next} s==2&&/^# <<< Prime Hub/{s=0;next} {s=0;print}' "$1"; }
docker cp vanguard-web-1:/etc/caddy/Caddyfile migracao/Caddyfile.atual
[ -f migracao/Caddyfile.original ] || tirar_bloco migracao/Caddyfile.atual > migracao/Caddyfile.original
{ tirar_bloco migracao/Caddyfile.atual; echo; echo "$BLOCO"; } > migracao/Caddyfile.novo
if ! cmp -s migracao/Caddyfile.novo migracao/Caddyfile.atual; then
  docker cp migracao/Caddyfile.novo vanguard-web-1:/etc/caddy/Caddyfile
  if docker exec vanguard-web-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 \
     && docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile; then
    echo "Caddy recarregado sem reiniciar (vanguard não parou)"
  else
    echo "ERRO no Caddy -- voltando a configuração anterior"
    docker cp migracao/Caddyfile.atual vanguard-web-1:/etc/caddy/Caddyfile
    docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
    exit 1
  fi
  if [ -f /opt/vanguard/app/web/Caddyfile ]; then
    [ -f /opt/vanguard/app/web/Caddyfile.antes-primehub ] || cp /opt/vanguard/app/web/Caddyfile /opt/vanguard/app/web/Caddyfile.antes-primehub
    { tirar_bloco /opt/vanguard/app/web/Caddyfile; echo; echo "$BLOCO"; } > /tmp/Caddyfile.fonte && cat /tmp/Caddyfile.fonte > /opt/vanguard/app/web/Caddyfile
    echo "bloco também atualizado em /opt/vanguard/app/web/Caddyfile"
  fi
else
  echo "Caddy já estava atualizado"
fi

echo "== 6/6 conferindo"
sleep 5
for u in login portal login.php portal/login.php api/saude api/app/versao; do
  printf '%s: ' "$u"; curl -sS -o /dev/null -w '%{http_code}\n' --max-time 60 "https://suporte.primeerp.com.br/$u" || true
done
printf 'vanguard (admin): '; curl -sS -o /dev/null -w '%{http_code}\n' --max-time 30 "https://${ADMIN_DOMAIN_TESTE:-admin.erpvanguard.com.br}/" || true
