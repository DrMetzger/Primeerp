#!/bin/bash
# Ajusta o site protheus.primeerp.com.br no Caddy (corrige a tela branca do
# login 12.1.2510): /app-root/* vai para a porta multiprotocolo (1234) e o
# navegador troca http por https. Não reinicia o AppServer. Se o Caddy
# recusar a configuração, volta a anterior.
set -eu
set +o pipefail
GW=$(docker network inspect vanguard_default -f '{{(index .IPAM.Config 0).Gateway}}')
ufw allow from 172.18.0.0/16 to any port 1234 proto tcp >/dev/null 2>&1 || true
echo "firewall: 1234 liberada só para a rede do Caddy"
docker cp vanguard-web-1:/etc/caddy/Caddyfile /tmp/Caddyfile.atual
python3 - "$GW" <<'PY'
import sys, re
gw = sys.argv[1]
s = open('/tmp/Caddyfile.atual').read()
bloco = f"""protheus.primeerp.com.br {{
	encode gzip
	header Content-Security-Policy "upgrade-insecure-requests"
	handle /app-root/* {{
		reverse_proxy {gw}:1234
	}}
	handle {{
		reverse_proxy {gw}:8089
	}}
}}
"""
s2, n = re.subn(r"protheus\.primeerp\.com\.br \{\n.*?\n\}\n", lambda m: bloco, s, count=1, flags=re.S)
if n == 0: sys.exit("bloco do protheus não encontrado no Caddyfile")
open('/tmp/Caddyfile.novo', 'w').write(s2)
print("bloco do protheus:", "trocado" if s2 != s else "já estava assim")
PY
docker cp /tmp/Caddyfile.novo vanguard-web-1:/etc/caddy/Caddyfile
if docker exec vanguard-web-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 && docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile; then
  [ -f /opt/vanguard/app/web/Caddyfile ] && cp /tmp/Caddyfile.novo /opt/vanguard/app/web/Caddyfile
  echo "Caddy recarregado"
else
  echo "ERRO no Caddy; voltando o anterior"; docker cp /tmp/Caddyfile.atual vanguard-web-1:/etc/caddy/Caddyfile
  docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile; exit 1
fi
sleep 3
echo "protheus: $(curl -s -o /dev/null -w '%{http_code}' https://protheus.primeerp.com.br/) | app-root: $(curl -s -o /dev/null -w '%{http_code}' https://protheus.primeerp.com.br/app-root/preindex_env_primeerp/index.html) | suporte: $(curl -s -o /dev/null -w '%{http_code}' https://suporte.primeerp.com.br/)"
grep -c '^\[SSLConfigure\]' /opt/totvs/protheus12/protheus/bin/appserver/appserver.ini | sed 's/^/SSLConfigure no ini (deve ser 0): /'
