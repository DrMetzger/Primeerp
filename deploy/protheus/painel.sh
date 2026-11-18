#!/bin/bash
# Instala/atualiza o agente de serviços (painel do suporte). Idempotente.
set -eu
install -m 755 "$(dirname "$0")/servicos-agente.py" /usr/local/bin/primehub-servicos
# Compilador (TDS-CLI/advpls) num caminho fixo, pro agente testar conexão e compilar.
install -m 755 "$(dirname "$0")/tdscli.sh" /usr/local/bin/primehub-tdscli
bash /usr/local/bin/primehub-tdscli instalar >/dev/null 2>&1 || true
cat > /etc/systemd/system/primehub-servicos.service <<UNIT
[Unit]
Description=Prime Hub - agente de serviços da VM (painel do suporte)
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/bin/primehub-servicos
Restart=always
RestartSec=5
MemoryMax=64M

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable primehub-servicos >/dev/null 2>&1
systemctl restart primehub-servicos
sleep 2
echo "agente: $(systemctl is-active primehub-servicos)"
python3 - <<'PY'
import socket, json
s = socket.socket(socket.AF_UNIX); s.connect("/run/primehub/servicos.sock")
s.sendall(b"GET /servicos HTTP/1.0\r\n\r\n"); d = b""
while True:
    b = s.recv(65536)
    if not b: break
    d += b
corpo = json.loads(d.split(b"\r\n\r\n", 1)[1])
for x in corpo["servicos"]:
    print(f"  {x['nome']:15} {x['estado']:9} mem {x['memoriaMB']} MB  portas " + " ".join(f"{p['porta']}{'+' if p['aberta'] else '-'}" for p in x['portas']))
print("  versões:", corpo["versoes"])
PY
