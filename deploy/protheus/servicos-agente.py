#!/usr/bin/env python3
"""
Agente de serviços da VM para o painel do suporte (Prime Hub).

Escuta SÓ num socket local (/run/primehub/servicos.sock), montado dentro do
container da API. Conhece apenas a lista fechada abaixo e só faz: ver
status, iniciar, parar, reiniciar e ler o fim do log. Tudo registrado em
/var/log/primehub-servicos.log. Nunca devolve senha.
"""
import json, os, re, socketserver, subprocess, time
from http.server import BaseHTTPRequestHandler

SOCKET = "/run/primehub/servicos.sock"
REGISTRO = "/var/log/primehub-servicos.log"
APP_LOG = "/opt/totvs/protheus12/protheus/bin/appserver/console.log"
VERSOES = "/opt/totvs/versoes.json"

SERVICOS = [
    {"id": "appserver", "unidade": "totvsappserver", "nome": "AppServer", "descricao": "Protheus (Webapp e TDS)", "portas": [1234, 8089], "critico": True},
    {"id": "dbaccess", "unidade": "totvsdbaccess", "nome": "DBAccess", "descricao": "Ponte do Protheus com o banco", "portas": [7890], "critico": True},
    {"id": "license", "unidade": "licenseVirtual", "nome": "License Server", "descricao": "Licenças TOTVS", "portas": [5555], "critico": True},
    {"id": "postgresql", "unidade": "postgresql@16-main", "nome": "PostgreSQL", "descricao": "Banco de dados do Protheus", "portas": [5432], "critico": True},
    {"id": "syncthing", "unidade": "syncthing@totvs", "nome": "Syncthing", "descricao": "Fontes sincronizados com o PC", "portas": [22000], "critico": False},
]
ACOES = {"iniciar": "start", "parar": "stop", "reiniciar": "restart"}
SENSIVEL = re.compile(r"senha|password|psw|passwd|secret|token", re.I)


def sh(*args, timeout=30):
    r = subprocess.run(list(args), capture_output=True, text=True, timeout=timeout)
    return r.returncode, r.stdout, r.stderr


def portas_abertas():
    _, out, _ = sh("ss", "-tlnH")
    return {int(m.group(1)) for m in re.finditer(r":(\d+)\s", out + " ")}


def status(s, abertas=None):
    _, out, _ = sh("systemctl", "show", s["unidade"], "-p", "ActiveState,SubState,ActiveEnterTimestamp,MemoryCurrent,CPUUsageNSec,MainPID,NRestarts,UnitFileState")
    p = dict(l.split("=", 1) for l in out.strip().splitlines() if "=" in l)
    mem = p.get("MemoryCurrent", "")
    cpu = p.get("CPUUsageNSec", "")
    abertas = abertas if abertas is not None else portas_abertas()
    return {
        "id": s["id"], "nome": s["nome"], "descricao": s["descricao"], "unidade": s["unidade"], "critico": s["critico"],
        "estado": p.get("ActiveState", "unknown"), "subestado": p.get("SubState", ""),
        "desde": p.get("ActiveEnterTimestamp", "") or None,
        "memoriaMB": round(int(mem) / 1048576, 1) if mem.isdigit() else None,
        "cpuSegundos": round(int(cpu) / 1e9, 1) if cpu.isdigit() else None,
        "pid": int(p["MainPID"]) if p.get("MainPID", "0").isdigit() and p.get("MainPID") != "0" else None,
        "reinicios": int(p["NRestarts"]) if p.get("NRestarts", "").isdigit() else 0,
        "iniciaComVM": p.get("UnitFileState") == "enabled",
        "portas": [{"porta": n, "aberta": n in abertas} for n in s["portas"]],
    }


def vm():
    _, mem, _ = sh("free", "-m")
    l = mem.splitlines()[1].split()
    _, disco, _ = sh("df", "-BG", "/")
    d = disco.splitlines()[1].split()
    carga = os.getloadavg()
    return {"memoriaTotalMB": int(l[1]), "memoriaUsadaMB": int(l[2]), "memoriaDisponivelMB": int(l[6]),
            "discoTotalGB": int(d[1].rstrip("G")), "discoUsadoGB": int(d[2].rstrip("G")), "carga": [round(x, 2) for x in carga], "nucleos": os.cpu_count()}


def versoes():
    try:
        with open(VERSOES) as f:
            return json.load(f)
    except Exception:
        return {}


def registrar(texto):
    with open(REGISTRO, "a") as f:
        f.write(time.strftime("%Y-%m-%d %H:%M:%S ") + texto + "\n")


class Agente(BaseHTTPRequestHandler):
    def address_string(self):
        return "socket"

    def log_message(self, *a):
        pass

    def responder(self, codigo, corpo):
        dados = json.dumps(corpo, ensure_ascii=False).encode()
        self.send_response(codigo)
        self.send_header("content-type", "application/json; charset=utf-8")
        self.send_header("content-length", str(len(dados)))
        self.end_headers()
        self.wfile.write(dados)

    def servico(self, sid):
        return next((s for s in SERVICOS if s["id"] == sid), None)

    def do_GET(self):
        partes = [p for p in self.path.split("?")[0].split("/") if p]
        if partes == ["servicos"]:
            abertas = portas_abertas()
            return self.responder(200, {"servicos": [status(s, abertas) for s in SERVICOS], "vm": vm(), "versoes": versoes(), "agora": time.strftime("%Y-%m-%dT%H:%M:%S")})
        if partes == ["versoes"]:
            return self.responder(200, versoes())
        if len(partes) == 3 and partes[0] == "servicos" and partes[2] == "log":
            s = self.servico(partes[1])
            if not s:
                return self.responder(404, {"erro": "Serviço desconhecido."})
            if s["id"] == "appserver" and os.path.exists(APP_LOG):
                _, out, _ = sh("tail", "-n", "300", APP_LOG)
                linhas = [l for l in out.splitlines() if l.strip() and not re.search(r"Memory|\[\.|\[=|PID +MEM|tot +lim", l)][-80:]
            else:
                _, out, _ = sh("journalctl", "-u", s["unidade"], "-n", "80", "--no-pager", "-o", "short-iso")
                linhas = out.splitlines()
            return self.responder(200, {"linhas": [("[linha omitida: dado sensível]" if SENSIVEL.search(l) else l) for l in linhas]})
        return self.responder(404, {"erro": "Caminho desconhecido."})

    def do_POST(self):
        partes = [p for p in self.path.split("?")[0].split("/") if p]
        if len(partes) != 3 or partes[0] != "servicos":
            return self.responder(404, {"erro": "Caminho desconhecido."})
        s, acao = self.servico(partes[1]), partes[2]
        if not s or acao not in ACOES:
            return self.responder(400, {"erro": "Serviço ou ação inválida."})
        quem = (self.headers.get("x-quem") or "?")[:120]
        registrar(f"{quem}: {acao} {s['unidade']}")
        rc, _, err = sh("systemctl", ACOES[acao], s["unidade"], timeout=120)
        if rc != 0:
            registrar(f"  falhou: {err.strip()[:200]}")
            return self.responder(500, {"erro": f"Não deu para {acao} o {s['nome']}: {err.strip()[:200]}"})
        for _ in range(20):
            atual = status(s)
            if (acao == "parar" and atual["estado"] in ("inactive", "failed")) or (acao != "parar" and atual["estado"] == "active"):
                break
            time.sleep(1)
        return self.responder(200, {"servico": status(s), "mensagem": f"{s['nome']}: {acao} feito."})


class Servidor(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True


if __name__ == "__main__":
    os.makedirs(os.path.dirname(SOCKET), exist_ok=True)
    if os.path.exists(SOCKET):
        os.remove(SOCKET)
    srv = Servidor(SOCKET, Agente)
    os.chmod(SOCKET, 0o666)
    registrar("agente iniciado")
    srv.serve_forever()
