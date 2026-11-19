#!/bin/bash
# TDS-CLI LS (advpls) na VM. Modos:
#   instalar         - baixa o advpls (binário Linux oficial) se faltar
#   iniciar <AMB>    - instala e dispara um teste (compilar+patch) DESTACADO,
#                      gravando tudo em $CLI/smoke/resultado.txt; volta na hora
#   _executar <AMB>  - uso interno (roda o teste de fato)
# Nunca toca em cliente nenhum: o alvo é a base local (localhost:1234).
set -u
BASE=/opt/totvs
CLI=$BASE/tdscli
BIN=$CLI/bin/linux/advpls
mkdir -p $CLI

instalar() {
  if [ -x "$BIN" ]; then echo "advpls ok ($($BIN --version 2>/dev/null | head -1))"; return; fi
  echo "baixando @totvs/tds-ls do npm"
  local URL VER
  URL=$(curl -s https://registry.npmjs.org/@totvs/tds-ls/latest | grep -oE '"tarball":"[^"]+"' | head -1 | cut -d'"' -f4)
  VER=$(curl -s https://registry.npmjs.org/@totvs/tds-ls/latest | grep -oE '"version":"[^"]+"' | head -1 | cut -d'"' -f4)
  echo "versão $VER"
  curl -sL "$URL" -o $CLI/tds-ls.tgz
  tar -xzf $CLI/tds-ls.tgz -C $CLI --strip-components=1 package/bin package/legado 2>/dev/null || tar -xzf $CLI/tds-ls.tgz -C $CLI
  chmod +x $CLI/bin/linux/* 2>/dev/null || true
  [ -x "$BIN" ] && echo "instalado" || echo "FALHOU instalar"
}

executar() {
  local SLUG=${1:-base} S=$CLI/smoke
  rm -rf $S; mkdir -p $S/fontes $S/patch $S/includes
  cat > $S/fontes/xsmoke01.prw <<'PRW'
User Function XSMOKE01()
Return .T.
PRW
  echo "== alvo '$SLUG' (do cofre do app)"
  local ALVO; ALVO=$(docker exec primehub-api node /app/index.mjs --alvo-compilacao "$SLUG" 2>/dev/null)
  if [ -z "$ALVO" ]; then echo "ERRO: alvo '$SLUG' não encontrado — cadastre no painel do Protheus → Compilação"; return 1; fi
  eval "$(printf '%s' "$ALVO" | python3 -c 'import sys,json;a=json.load(sys.stdin);print("AHOST=%r;APORT=%d;AENV=%r;AUSER=%r;ABUILD=%r;ASEC=%d;ATEM=%d;AINC=%r"%(a["host"],a["porta"],a["ambiente"],a["usuario"],a.get("build") or "AUTO",1 if a.get("secure") else 0,1 if a.get("senha") else 0,a.get("includes") or ""))')"
  # includes: usa a pasta cadastrada; senão a da própria instalação do Protheus na VM
  [ -n "$AINC" ] || AINC=/opt/totvs/protheus12/protheus/include
  echo "host=$AHOST porta=$APORT ambiente=$AENV usuario=$AUSER build=$ABUILD secure=$ASEC includes=$AINC senha=$([ "$ATEM" = 1 ] && echo definida || echo VAZIA)"
  if [ "$ATEM" != 1 ]; then echo "ERRO: alvo '$SLUG' está SEM SENHA — cadastre no painel do Protheus → Compilação"; return 1; fi
  local ASENHA; ASENHA=$(printf '%s' "$ALVO" | python3 -c 'import sys,json;sys.stdout.write(json.load(sys.stdin)["senha"])')
  { cat > $S/exec.ini <<INI
logToFile=$S/exec.log
showConsoleOutput=True

[auth]
action=authentication
server=$AHOST
port=$APORT
secure=$ASEC
build=$ABUILD
environment=$AENV
user=$AUSER
psw=$ASENHA

[compila]
action=compile
includes=$AINC
program=$S/fontes/xsmoke01.prw
recompile=T

[patch]
action=patchGen
fileResource=xsmoke01.prw
patchType=PTM
saveLocal=$S/patch/
INI
  } 2>/dev/null
  unset ASENHA
  echo "== teste compilar+patch em $AENV ($AHOST:$APORT) — $(date '+%H:%M:%S')"
  ( cd $S && timeout --kill-after=20 160 $BIN --verbose 1 --log-file $S/cli.log cli $S/exec.ini > $S/saida.txt 2>&1 < /dev/null; echo "EXIT=$?" >> $S/saida.txt )
  pkill -9 -f advpls 2>/dev/null || true
  shred -u $S/exec.ini 2>/dev/null || rm -f $S/exec.ini
  echo "--- EXIT ---"; grep -a '^EXIT=' $S/saida.txt
  echo "--- saida ---"; grep -aviE 'psw=|password|senha' $S/saida.txt | grep -av '^EXIT=' | tail -90
  echo "--- cli.log ---"; grep -aviE 'psw=|password|senha' $S/cli.log 2>/dev/null | tail -90
  echo "--- exec.log ---"; grep -aviE 'psw=|password|senha' $S/exec.log 2>/dev/null | tail -20
  echo "--- patch gerado? ---"; ls -la $S/patch/ 2>/dev/null
  echo "FIM-TDSCLI $(date '+%H:%M:%S')"
}

# Teste rápido de conexão/autenticação de um alvo (pro "verde" do painel).
# Só autentica, não compila. Imprime UMA linha JSON.
validar() {
  local SLUG=${1:-base} S=$CLI/val
  rm -rf $S; mkdir -p $S
  local ALVO; ALVO=$(docker exec primehub-api node /app/index.mjs --alvo-compilacao "$SLUG" 2>/dev/null)
  [ -z "$ALVO" ] && { echo '{"conectado":false,"detalhe":"alvo não encontrado"}'; return 0; }
  eval "$(printf '%s' "$ALVO" | python3 -c 'import sys,json;a=json.load(sys.stdin);print("AHOST=%r;APORT=%d;AENV=%r;AUSER=%r;ABUILD=%r;ASEC=%d;ATEM=%d"%(a["host"],a["porta"],a["ambiente"],a["usuario"],a.get("build") or "AUTO",1 if a.get("secure") else 0,1 if a.get("senha") else 0))')"
  [ "$ATEM" != 1 ] && { echo '{"conectado":false,"detalhe":"sem senha cadastrada"}'; return 0; }
  [ -z "$AENV" ] && { echo '{"conectado":false,"detalhe":"sem ambiente cadastrado"}'; return 0; }
  local ASENHA; ASENHA=$(printf '%s' "$ALVO" | python3 -c 'import sys,json;sys.stdout.write(json.load(sys.stdin)["senha"])')
  { cat > $S/val.ini <<INI
logToFile=$S/val.log
showConsoleOutput=True

[auth]
action=authentication
server=$AHOST
port=$APORT
secure=$ASEC
build=$ABUILD
environment=$AENV
user=$AUSER
psw=$ASENHA
INI
  } 2>/dev/null
  unset ASENHA
  ( cd $S && timeout --kill-after=10 70 $BIN --verbose 1 --log-file $S/val.log cli $S/val.ini > $S/val.out 2>&1 < /dev/null )
  pkill -9 -f advpls 2>/dev/null || true
  shred -u $S/val.ini 2>/dev/null || rm -f $S/val.ini
  python3 - "$S/val.log" <<'PY'
import sys, re, json, os
log = open(sys.argv[1], errors='ignore').read() if os.path.exists(sys.argv[1]) else ''
m = re.search(r'build:\s*([0-9.A-Za-z]+)', log) or re.search(r'Build Version:\s*([0-9.A-Za-z]+)', log)
build = m.group(1) if m else ''
reached = ('Build Version' in log) or ('connected status: 0' in log)
attempted = ('PSWADMIN' in log) or ('Authenticating' in log) or ('authentication' in log.lower())
mu = re.search(r'userCode:\s*(\d+)', log)
auth_failed = bool(mu) and mu.group(1) != '0'
if not reached:
    r = {"conectado": False, "build": build, "detalhe": "não conectou (porta/host/rede)"}
elif auth_failed:
    r = {"conectado": False, "build": build, "detalhe": "usuário ou senha recusada"}
elif attempted:
    r = {"conectado": True, "build": build, "detalhe": "conectado"}
else:
    r = {"conectado": False, "build": build, "detalhe": "conectou, mas a autenticação não respondeu"}
print(json.dumps(r, ensure_ascii=False))
PY
}

# Compila os fontes de um diretório em um alvo e gera um .ptm.
# Uso: compilar <slug> <dir_com_fontes>. Imprime UMA linha JSON.
compilar() {
  local SLUG=${1:?} SRC=${2:?} S=$CLI/compilar
  rm -rf $S; mkdir -p $S/patch
  local ALVO; ALVO=$(docker exec primehub-api node /app/index.mjs --alvo-compilacao "$SLUG" 2>/dev/null)
  [ -z "$ALVO" ] && { echo '{"ok":false,"erro":"alvo não encontrado"}'; return 0; }
  eval "$(printf '%s' "$ALVO" | python3 -c 'import sys,json;a=json.load(sys.stdin);print("AHOST=%r;APORT=%d;AENV=%r;AUSER=%r;ABUILD=%r;ASEC=%d;ATEM=%d;AINC=%r"%(a["host"],a["porta"],a["ambiente"],a["usuario"],a.get("build") or "AUTO",1 if a.get("secure") else 0,1 if a.get("senha") else 0,a.get("includes") or ""))')"
  [ -n "$AINC" ] || AINC=/opt/totvs/protheus12/protheus/include
  [ "$ATEM" != 1 ] && { echo '{"ok":false,"erro":"alvo sem senha cadastrada"}'; return 0; }
  [ -z "$AENV" ] && { echo '{"ok":false,"erro":"alvo sem ambiente cadastrado"}'; return 0; }
  find "$SRC" -type f \( -iname '*.prw' -o -iname '*.tlpp' -o -iname '*.prx' \) > $S/progs.txt
  [ -s $S/progs.txt ] || { echo '{"ok":false,"erro":"nenhum fonte .prw/.tlpp enviado"}'; return 0; }
  awk -F/ '{print $NF}' $S/progs.txt > $S/recursos.txt
  local ASENHA; ASENHA=$(printf '%s' "$ALVO" | python3 -c 'import sys,json;sys.stdout.write(json.load(sys.stdin)["senha"])')
  { cat > $S/exec.ini <<INI
logToFile=$S/exec.log
showConsoleOutput=True

[auth]
action=authentication
server=$AHOST
port=$APORT
secure=$ASEC
build=$ABUILD
environment=$AENV
user=$AUSER
psw=$ASENHA

[compila]
action=compile
includes=$AINC
programList=$S/progs.txt
recompile=T

[patch]
action=patchGen
fileResourceList=$S/recursos.txt
patchType=PTM
saveLocal=$S/patch/
INI
  } 2>/dev/null
  unset ASENHA
  ( cd $S && timeout --kill-after=20 200 $BIN --verbose 1 --log-file $S/cli.log cli $S/exec.ini > $S/saida.txt 2>&1 < /dev/null )
  pkill -9 -f advpls 2>/dev/null || true
  shred -u $S/exec.ini 2>/dev/null || rm -f $S/exec.ini
  local PTM; PTM=$(ls -t $S/patch/*.ptm $S/patch/*.PTM 2>/dev/null | head -1)
  python3 - "$S/cli.log" "$S/saida.txt" "${PTM:-}" <<'PY'
import sys, re, os, json
log = ''
for f in sys.argv[1:3]:
    if os.path.exists(f): log += open(f, errors='ignore').read()
ptm = sys.argv[3]
ok = bool(ptm) and os.path.exists(ptm)
errs = re.findall(r'(Error C\d+[^\n]*|has no valid content[^\n]*|Authentication error[^\n]*|non numeric value[^\n]*|userCode:\s*[1-9]\d*)', log)
cod = ' | '.join(dict.fromkeys(e.strip() for e in errs))[:400]
print(json.dumps({"ok": ok, "patch": ptm if ok else "", "nome": os.path.basename(ptm) if ok else "",
                  "erro": "" if ok else (cod or "não gerou patch (ver log do AppServer)")}, ensure_ascii=False))
PY
}

case "${1:-instalar}" in
  instalar) instalar ;;
  smoke)    instalar; executar "${2:-base}" ;;
  validar)  validar "${2:-base}" ;;
  compilar) compilar "${2}" "${3}" ;;
  iniciar)  instalar; mkdir -p $CLI/smoke; : > $CLI/smoke/resultado.txt
            setsid bash -c "BASE=$BASE CLI=$CLI BIN=$BIN bash $0 _executar ${2:-base} > $CLI/smoke/resultado.txt 2>&1 < /dev/null" >/dev/null 2>&1 &
            echo "teste disparado (destacado)"; exit 0 ;;
  _executar) executar "${2:-base}" ;;
  *) echo "modo inválido"; exit 1 ;;
esac
