#!/usr/bin/env python3
"""
Agente de serviços da VM para o painel do suporte (Prime Hub).

Escuta SÓ num socket local (/run/primehub/servicos.sock), montado dentro do
container da API. Conhece apenas a lista fechada abaixo e só faz: ver
status, iniciar, parar, reiniciar e ler o fim do log. Tudo registrado em
/var/log/primehub-servicos.log. Nunca devolve senha.
"""
import json, os, re, socketserver, subprocess, threading, time, uuid
from http.server import BaseHTTPRequestHandler

SOCKET = "/run/primehub/servicos.sock"
REGISTRO = "/var/log/primehub-servicos.log"
APP_LOG = "/opt/totvs/protheus12/protheus/bin/appserver/console.log"
VERSOES = "/opt/totvs/versoes.json"
# Pasta OFICIAL dos fontes 2510 (decidido com a equipe). Buscar/ler SO aqui:
# o que estiver fora conta como "nao tenho o fonte". Nao pegar copia de outra
# pasta (pode estar errada). Pode ser trocado pela env PRM_FONTES_DIR.
FONTES_OFICIAL = "/opt/totvs/fontes/Fontes_2510"

SERVICOS = [
    {"id": "appserver", "unidade": "totvsappserver", "nome": "AppServer", "descricao": "Protheus (Webapp e TDS)", "portas": [1234, 8089], "critico": True},
    {"id": "appserver_rest", "unidade": "totvsappserver-rest", "nome": "AppServer REST", "descricao": "API REST do Protheus (reset de senha automatico)", "portas": [8095], "critico": False},
    {"id": "dbaccess", "unidade": "totvsdbaccess", "nome": "DBAccess", "descricao": "Ponte do Protheus com o banco", "portas": [7890], "critico": True},
    {"id": "license", "unidade": "licenseVirtual", "nome": "License Server", "descricao": "Licenças TOTVS", "portas": [5555], "critico": True},
    {"id": "postgresql", "unidade": "postgresql@16-main", "nome": "PostgreSQL", "descricao": "Banco de dados do Protheus", "portas": [5432], "critico": True},
    {"id": "syncthing", "unidade": "syncthing@totvs", "nome": "Syncthing", "descricao": "Fontes sincronizados com o PC", "portas": [22000], "critico": False},
]
ACOES = {"iniciar": "start", "parar": "stop", "reiniciar": "restart"}
# "Todos": a pilha do Protheus, na ordem certa (para de cima para baixo, sobe de baixo para cima).
PILHA = ["appserver", "dbaccess", "license", "postgresql"]
TAREFAS = []  # últimas tarefas (pipeline), mais nova primeiro
TRAVA = threading.Lock()
SENSIVEL = re.compile(r"senha|password|psw|passwd|secret|token", re.I)


def sh(*args, timeout=30):
    r = subprocess.run(list(args), capture_output=True, text=True, timeout=timeout)
    return r.returncode, r.stdout, r.stderr


def parar_rest_para_compilar(slug):
    """O AppServer REST da VM (totvsappserver-rest) mantem o custom.rpo da BASE
    aberto e isso trava o build do tdscli (COMPILEERROR-300 Failed to open
    repository). Antes de compilar NA BASE, paramos o REST; religamos depois.
    Para clientes (compilacao remota no servidor deles) nao mexe em nada."""
    if slug != "base":
        return False
    sh("systemctl", "stop", "totvsappserver-rest", timeout=30)
    for _ in range(8):
        rc, _, _ = sh("pgrep", "-f", "appserver_rest.ini", timeout=10)
        if rc != 0:
            break
        time.sleep(1)
    sh("pkill", "-f", "appserver_rest.ini", timeout=10)
    return True


def religar_rest():
    sh("systemctl", "start", "totvsappserver-rest", timeout=30)


def portas_abertas():
    _, out, _ = sh("ss", "-tlnH")
    return {int(m.group(1)) for m in re.finditer(r":(\d+)\s", out + " ")}


def status(s, abertas=None):
    _, out, _ = sh("systemctl", "show", s["unidade"], "-p", "ActiveState,SubState,ActiveEnterTimestamp,MemoryCurrent,MemoryMax,CPUUsageNSec,MainPID,NRestarts,UnitFileState")
    p = dict(l.split("=", 1) for l in out.strip().splitlines() if "=" in l)
    mem = p.get("MemoryCurrent", "")
    cpu = p.get("CPUUsageNSec", "")
    abertas = abertas if abertas is not None else portas_abertas()
    return {
        "id": s["id"], "nome": s["nome"], "descricao": s["descricao"], "unidade": s["unidade"], "critico": s["critico"],
        "estado": p.get("ActiveState", "unknown"), "subestado": p.get("SubState", ""),
        "desde": p.get("ActiveEnterTimestamp", "") or None,
        "memoriaMB": round(int(mem) / 1048576, 1) if mem.isdigit() else None,
        "limiteMB": round(int(p["MemoryMax"]) / 1048576) if p.get("MemoryMax", "").isdigit() else None,
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


def por_id(sid):
    return next((s for s in SERVICOS if s["id"] == sid), None)


def nova_tarefa(titulo, quem, etapas):
    t = {"id": uuid.uuid4().hex[:12], "titulo": titulo, "quem": quem, "estado": "rodando", "inicio": time.time(), "fim": None,
         "etapas": [{"nome": e, "estado": "pendente", "inicio": None, "fim": None, "detalhe": ""} for e in etapas]}
    with TRAVA:
        TAREFAS.insert(0, t)
        del TAREFAS[30:]
    registrar(f"{quem}: tarefa {titulo}")
    return t


def esperar(s, acao, limite=90):
    """Espera o serviço chegar no estado certo (e as portas abrirem, ao subir)."""
    fim = time.time() + limite
    while time.time() < fim:
        st = status(s)
        if acao == "parar" and st["estado"] in ("inactive", "failed"):
            return True, "parado"
        if acao != "parar" and st["estado"] == "active" and all(p["aberta"] for p in st["portas"]):
            return True, "no ar, portas " + " ".join(str(p["porta"]) for p in st["portas"])
        time.sleep(1.5)
    st = status(s)
    return False, f"tempo esgotado (estado {st['estado']}, portas " + " ".join(f"{p['porta']}{'✓' if p['aberta'] else '✗'}" for p in st["portas"]) + ")"


def rodar(t, passos):
    """passos = [(indice_etapa, servico, acao)] executados em ordem; para no primeiro erro."""
    ok_geral = True
    for i, s, acao in passos:
        e = t["etapas"][i]
        if not ok_geral:
            e["estado"] = "pulada"
            continue
        e["estado"], e["inicio"] = "rodando", time.time()
        rc, _, err = sh("systemctl", ACOES[acao], s["unidade"], timeout=150)
        if rc != 0:
            ok, det = False, err.strip()[:200] or "systemctl falhou"
        else:
            ok, det = esperar(s, acao)
        e["estado"], e["fim"], e["detalhe"] = ("ok" if ok else "erro"), time.time(), det
        registrar(f"  {e['nome']}: {e['estado']} ({det})")
        ok_geral = ok_geral and ok
    t["estado"], t["fim"] = ("ok" if ok_geral else "erro"), time.time()


def iniciar_tarefa(alvo, acao, quem):
    if alvo == "todos":
        pilha = [por_id(x) for x in PILHA]
        rot = {"parar": "Parar todos", "iniciar": "Iniciar todos", "reiniciar": "Reiniciar todos"}[acao]
        if acao == "parar":
            passos = [("parar", s) for s in pilha]
        elif acao == "iniciar":
            passos = [("iniciar", s) for s in reversed(pilha)]
        else:
            passos = [("parar", s) for s in pilha] + [("iniciar", s) for s in reversed(pilha)]
    else:
        s = por_id(alvo)
        rot = f"{acao.capitalize()} {s['nome']}"
        passos = [("parar", s), ("iniciar", s)] if acao == "reiniciar" else [(acao, s)]
    verbo = {"parar": "Parar", "iniciar": "Iniciar"}
    t = nova_tarefa(rot, quem, [f"{verbo[a]} {s['nome']}" for a, s in passos])
    threading.Thread(target=rodar, args=(t, [(i, s, a) for i, (a, s) in enumerate(passos)]), daemon=True).start()
    return t


def tarefa_json(t):
    agora = time.time()
    return {**t, "duracao": round((t["fim"] or agora) - t["inicio"], 1),
            "etapas": [{**e, "duracao": round((e["fim"] or agora) - e["inicio"], 1) if e["inicio"] else None} for e in t["etapas"]]}


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
        if partes == ["tarefas"]:
            return self.responder(200, {"tarefas": [tarefa_json(t) for t in TAREFAS]})
        if len(partes) == 2 and partes[0] == "tarefas":
            t = next((x for x in TAREFAS if x["id"] == partes[1]), None)
            return self.responder(200, tarefa_json(t)) if t else self.responder(404, {"erro": "Tarefa não encontrada."})
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
        if len(partes) == 3 and partes[0] == "compilacao" and partes[1] == "testar":
            slug = re.sub(r"[^a-z0-9_-]", "", partes[2].lower())[:60]
            # roda o compilador num escopo próprio (fora do limite de memória do agente)
            _, out, _ = sh("systemd-run", "--scope", "--quiet", "-p", "MemoryMax=2G",
                           "/usr/local/bin/primehub-tdscli", "validar", slug, timeout=130)
            linha = next((l for l in out.splitlines() if l.strip().startswith("{")), "")
            try:
                res = json.loads(linha) if linha else {"conectado": False, "detalhe": "sem resposta do compilador"}
            except Exception:
                res = {"conectado": False, "detalhe": "sem resposta do compilador"}
            return self.responder(200, res)
        return self.responder(404, {"erro": "Caminho desconhecido."})

    def _corpo(self):
        n = int(self.headers.get("content-length") or 0)
        return json.loads(self.rfile.read(n) or b"{}") if n else {}

    def do_POST(self):
        partes = [p for p in self.path.split("?")[0].split("/") if p]
        if partes == ["compilacao", "compilar"]:
            try:
                corpo = self._corpo()
            except Exception:
                return self.responder(400, {"ok": False, "erro": "corpo inválido"})
            slug = re.sub(r"[^a-z0-9_-]", "", str(corpo.get("slug", "")).lower())[:60]
            arquivos = corpo.get("arquivos") or []
            if not slug or not arquivos:
                return self.responder(400, {"ok": False, "erro": "informe slug e arquivos"})
            import base64, tempfile, shutil
            d = tempfile.mkdtemp(prefix="compilar-")
            parou_rest = False
            try:
                for a in arquivos[:50]:
                    nome = os.path.basename(str(a.get("nome", "")))[:80]
                    if not re.search(r"\.(prw|tlpp|prx)$", nome, re.I):
                        continue
                    with open(os.path.join(d, nome), "wb") as fp:
                        fp.write(base64.b64decode(str(a.get("conteudo_base64", ""))))
                # Libera o lock do custom.rpo da base antes de compilar (e religa no finally).
                parou_rest = parar_rest_para_compilar(slug)
                _, out, _ = sh("systemd-run", "--scope", "--quiet", "-p", "MemoryMax=2G",
                               "/usr/local/bin/primehub-tdscli", "compilar", slug, d, timeout=260)
                linha = next((l for l in out.splitlines() if l.strip().startswith("{")), "")
                res = json.loads(linha) if linha else {"ok": False, "erro": "sem resposta do compilador"}
                if res.get("ok") and res.get("patch") and os.path.exists(res["patch"]):
                    with open(res["patch"], "rb") as fp:
                        res["patch_base64"] = base64.b64encode(fp.read()).decode()
                res.pop("patch", None)
                return self.responder(200, res)
            except Exception as e:
                return self.responder(200, {"ok": False, "erro": str(e)[:200]})
            finally:
                if parou_rest:
                    religar_rest()
                shutil.rmtree(d, ignore_errors=True)
        if partes == ["reset", "senha"]:
            # Reseta a senha de um usuario Protheus chamando a API REST do
            # proprio ambiente (endpoint publico PRMRSS + chave secreta).
            try:
                corpo = self._corpo()
            except Exception:
                return self.responder(400, {"ok": False, "erro": "corpo inválido"})
            import urllib.request, urllib.error
            base = str(corpo.get("rest_base") or "http://127.0.0.1:8095").rstrip("/")
            login = str(corpo.get("login", "")).strip()
            senha = str(corpo.get("senha", ""))
            if not login:
                return self.responder(400, {"ok": False, "erro": "informe o login"})
            chave = os.environ.get("PRM_RESET_CHAVE", "PrimeHub@2026")
            dados = json.dumps({"login": login, "senha": senha, "chave": chave}).encode()
            req = urllib.request.Request(base + "/rest/prmrss", data=dados,
                                         headers={"Content-Type": "application/json"}, method="POST")
            try:
                with urllib.request.urlopen(req, timeout=40) as r:
                    return self.responder(200, {"ok": True, "http": r.status, "resposta": r.read().decode("utf-8", "replace")})
            except urllib.error.HTTPError as e:
                # a gravacao da senha pode ocorrer mesmo com 500 na finalizacao
                return self.responder(200, {"ok": False, "http": e.code, "resposta": e.read().decode("utf-8", "replace")})
            except Exception as e:
                return self.responder(200, {"ok": False, "erro": str(e)[:200]})
        if partes == ["admin", "criar-usuario"]:
            # Cria/atualiza um usuario Protheus chamando a API REST PRMADM
            # (endpoint publico + chave secreta). Nunca toca no banco direto.
            try:
                corpo = self._corpo()
            except Exception:
                return self.responder(400, {"ok": False, "erro": "corpo inválido"})
            import urllib.request, urllib.error
            base = str(corpo.get("rest_base") or "http://127.0.0.1:8095").rstrip("/")
            login = str(corpo.get("login", "")).strip()
            if not login:
                return self.responder(400, {"ok": False, "erro": "informe o login"})
            chave = os.environ.get("PRM_RESET_CHAVE", "PrimeHub@2026")
            dados = json.dumps({
                "acao": "criar_usuario", "chave": chave, "login": login,
                "nome": str(corpo.get("nome", "")), "senha": str(corpo.get("senha", "")),
                "email": str(corpo.get("email", "")),
            }).encode()
            req = urllib.request.Request(base + "/rest/prmadm", data=dados,
                                         headers={"Content-Type": "application/json"}, method="POST")
            try:
                with urllib.request.urlopen(req, timeout=40) as r:
                    return self.responder(200, {"ok": True, "http": r.status, "resposta": r.read().decode("utf-8", "replace")})
            except urllib.error.HTTPError as e:
                return self.responder(200, {"ok": False, "http": e.code, "resposta": e.read().decode("utf-8", "replace")})
            except Exception as e:
                return self.responder(200, {"ok": False, "erro": str(e)[:200]})
        if partes == ["fontes", "buscar"]:
            # Lista fontes .prw/.tlpp pela pasta de fontes do Protheus (por nome parcial).
            try:
                corpo = self._corpo()
            except Exception:
                return self.responder(400, {"ok": False, "erro": "corpo inválido"})
            termo = re.sub(r"[^A-Za-z0-9_.\- ]", "", str(corpo.get("termo", "")).strip())[:80]
            if len(termo) < 2:
                return self.responder(400, {"ok": False, "erro": "informe ao menos 2 letras"})
            raiz = os.environ.get("PRM_FONTES_DIR", FONTES_OFICIAL)
            try:
                rc, out, _ = sh("bash", "-lc",
                                "find %s -type f \\( -iname '*%s*.prw' -o -iname '*%s*.tlpp' -o -iname '*%s*.prx' \\) 2>/dev/null | head -40"
                                % (raiz, termo, termo, termo), timeout=40)
                achados = [l for l in out.splitlines() if l.strip()]
                return self.responder(200, {"ok": True, "total": len(achados),
                                            "fontes": [{"nome": os.path.basename(p), "caminho": p} for p in achados]})
            except Exception as e:
                return self.responder(200, {"ok": False, "erro": str(e)[:200]})
        if partes == ["fontes", "ler"]:
            # Le o conteudo de um fonte .prw/.tlpp da pasta de fontes do Protheus, por nome ou caminho.
            try:
                corpo = self._corpo()
            except Exception:
                return self.responder(400, {"ok": False, "erro": "corpo inválido"})
            raiz = os.path.realpath(os.environ.get("PRM_FONTES_DIR", FONTES_OFICIAL))
            caminho = str(corpo.get("caminho", "")).strip()
            nome = re.sub(r"[^A-Za-z0-9_.\-]", "", str(corpo.get("nome", "")).strip())[:120]
            alvo = ""
            if caminho:
                rp = os.path.realpath(caminho)
                if rp.startswith(raiz + os.sep) and os.path.isfile(rp):
                    alvo = rp
            if not alvo and nome:
                base = nome if re.search(r"\.(prw|tlpp|prx)$", nome, re.I) else nome + ".prw"
                try:
                    rc, out, _ = sh("bash", "-lc",
                                    "find %s -type f -iname '%s' 2>/dev/null | head -1" % (raiz, base), timeout=40)
                    alvo = out.strip().splitlines()[0] if out.strip() else ""
                except Exception:
                    alvo = ""
            if not alvo:
                return self.responder(404, {"ok": False, "erro": "fonte nao encontrado na pasta de fontes"})
            try:
                with open(alvo, "rb") as fp:
                    bruto = fp.read(900000)
                # Binario/RPO/compilado: tem byte nulo ou muitos bytes de controle. Nao e um fonte legivel.
                if b"\x00" in bruto:
                    return self.responder(404, {"ok": False, "erro": "arquivo binario/ilegivel (nao e um fonte .prw de texto)"})
                conteudo = bruto.decode("utf-8", errors="replace")
                if len(conteudo.strip()) < 40 or conteudo.count("�") > len(conteudo) * 0.02:
                    return self.responder(404, {"ok": False, "erro": "fonte vazio ou ilegivel"})
                return self.responder(200, {"ok": True, "nome": os.path.basename(alvo), "caminho": alvo, "conteudo": conteudo})
            except Exception as e:
                return self.responder(200, {"ok": False, "erro": str(e)[:200]})
        if len(partes) != 3 or partes[0] != "servicos":
            return self.responder(404, {"erro": "Caminho desconhecido."})
        alvo, acao = partes[1], partes[2]
        if acao not in ACOES or (alvo != "todos" and not por_id(alvo)):
            return self.responder(400, {"erro": "Serviço ou ação inválida."})
        if any(t["estado"] == "rodando" for t in TAREFAS):
            return self.responder(409, {"erro": "Já tem uma tarefa rodando na VM. Espere ela terminar."})
        quem = (self.headers.get("x-quem") or "?")[:120]
        t = iniciar_tarefa(alvo, acao, quem)
        return self.responder(202, tarefa_json(t))


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
