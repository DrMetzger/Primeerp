#!/bin/bash
# Liga o https no Webapp do AppServer (porta 8089) e faz o Caddy repassar em
# https. Motivo: atrás do Caddy o Webapp recebia http e o login do 12.1.2510
# abria o quadro interno em http dentro da página https -> bloqueado -> tela
# branca. Se o Webapp não responder em https, desfaz tudo.
set -eu
set +o pipefail
BASE=/opt/totvs
APP=$BASE/protheus12/protheus/bin/appserver
INI=$APP/appserver.ini
mkdir -p $BASE/ssl
[ -f $BASE/ssl/webapp.key ] || openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=protheus.primeerp.com.br" -keyout $BASE/ssl/webapp.key -out $BASE/ssl/webapp.crt 2>/dev/null
chmod 600 $BASE/ssl/webapp.key
grep -q '^\[SSLConfigure\]' $INI || cp -f $INI $INI.antes-https
if ! grep -q '^\[SSLConfigure\]' $INI; then
  cat >> $INI <<INI

[SSLConfigure]
CertificateServer=$BASE/ssl/webapp.crt
KeyServer=$BASE/ssl/webapp.key
PassPhrase=
SSL2=0
SSL3=0
TLS1_0=0
TLS1_1=0
TLS1_2=1
Verbose=0
INI
fi
# a porta multiprotocolo (1234) é a que aceita SSL e também serve o Webapp
sed -i 's/^MULTIPROTOCOLPORTSECURE=.*/MULTIPROTOCOLPORTSECURE=1/I' $INI
subir() {
  systemctl stop totvsappserver; sleep 3; pkill -f "$APP/appsrvlinux" || true; sleep 1
  cp -f $APP/console.log $APP/console.anterior.log 2>/dev/null || true; : > $APP/console.log
  systemctl start totvsappserver
  for i in $(seq 1 40); do ss -tlnH | grep -q ':8089 ' && break; sleep 3; done; sleep 3
}
echo "== reiniciando o AppServer com SSL"
subir
PORTA=""
for pt in 8089 1234; do
  c=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 https://127.0.0.1:$pt/ || true)
  echo "https na $pt: $c"; [ -z "$PORTA" ] && [ "$c" = 200 ] && PORTA=$pt
done
if [ -n "$PORTA" ]; then
  echo "Webapp respondendo em https na $PORTA"
  ufw allow from 172.18.0.0/16 to any port $PORTA proto tcp >/dev/null 2>&1 || true
else
  echo "ERRO: Webapp não respondeu em https; desfazendo"
  grep -aiE 'ssl|certif|error|mpport|webapp' $APP/console.log | head -15
  cp -f $INI.antes-https $INI; subir
  echo "voltou ao http: $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8089/)"; exit 1
fi

echo "== Caddy repassando em https"
GW=$(docker network inspect vanguard_default -f '{{(index .IPAM.Config 0).Gateway}}')
docker cp vanguard-web-1:/etc/caddy/Caddyfile /tmp/Caddyfile.atual
python3 - "$GW" "$PORTA" <<'PY'
import sys, re
gw = sys.argv[1]
s = open('/tmp/Caddyfile.atual').read()
porta = sys.argv[2]
novo = f"reverse_proxy https://{gw}:{porta} {{\n\t\ttransport http {{\n\t\t\ttls_insecure_skip_verify\n\t\t}}\n\t}}"
s2 = re.sub(r"reverse_proxy (https://)?" + re.escape(gw) + r":\d+( \{\n\t\ttransport http \{\n\t\t\ttls_insecure_skip_verify\n\t\t\}\n\t\})?", lambda m: novo, s, count=1)
open('/tmp/Caddyfile.novo', 'w').write(s2)
print("bloco trocado" if s2 != s else "bloco já estava em https")
PY
docker cp /tmp/Caddyfile.novo vanguard-web-1:/etc/caddy/Caddyfile
if docker exec vanguard-web-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 && docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile; then
  [ -f /opt/vanguard/app/web/Caddyfile ] && cp /tmp/Caddyfile.novo /opt/vanguard/app/web/Caddyfile
  echo "Caddy recarregado"
else
  echo "ERRO no Caddy; voltando o anterior"; docker cp /tmp/Caddyfile.atual vanguard-web-1:/etc/caddy/Caddyfile
  docker exec vanguard-web-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
fi
sleep 3
echo "externo: $(curl -s -o /dev/null -w '%{http_code}' https://protheus.primeerp.com.br/) | suporte: $(curl -s -o /dev/null -w '%{http_code}' https://suporte.primeerp.com.br/)"
