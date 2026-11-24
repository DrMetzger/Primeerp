#!/usr/bin/env python3
# Restaura o registro do Administrador em sys_usr para o estado ORIGINAL,
# copiando todas as colunas de texto/char da tupla mais antiga (tupla morta,
# ainda no heap pois vacuum nunca rodou) via pageinspect.
# Conserta o erro de checkSum do registro introduzido por UPDATE manual.
import subprocess

def psql(sql, db="protheus"):
    r = subprocess.run(["sudo", "-u", "postgres", "psql", "-At", "-d", db, "-c", sql],
                       capture_output=True, text=True)
    if r.returncode != 0:
        return "__ERR__" + (r.stderr or "")
    return r.stdout

ITEMS = ("SELECT t_xmin x, tuple_data_split('sys_usr'::regclass,t_data,t_infomask,"
         "t_infomask2,t_bits) cols "
         "FROM generate_series(0,(pg_relation_size('sys_usr')/8192)::int-1) p, "
         "heap_page_items(get_raw_page('sys_usr',p)) WHERE t_data IS NOT NULL")

def dec(n):
    c = f"o.cols[{n}]"
    return (f"convert_from(substring({c} from (CASE WHEN get_byte({c},0)&1=1 "
            f"THEN 2 ELSE 5 END)),'SQL_ASCII')")

# colunas de texto/char (sem as colunas de controle do Protheus/ctid)
raw = psql("SELECT attnum||'~'||attname FROM pg_attribute WHERE "
           "attrelid='sys_usr'::regclass AND attnum>0 AND NOT attisdropped AND "
           "format_type(atttypid,atttypmod) ~ '^character' AND "
           "attname !~ '^(d_e_l|r_e_c)' ORDER BY attnum")
if raw.startswith("__ERR__"):
    print("ERRO listando colunas:", raw); raise SystemExit(1)
cols = [r.split("~", 1) for r in raw.strip().split("\n") if r]
print(f"colunas de texto a restaurar: {len(cols)}")

sets = []
restored = {}
for n, name in cols:
    v = psql(f"SELECT btrim({dec(n)}) FROM ({ITEMS}) o ORDER BY x::text::bigint LIMIT 1")
    if v.startswith("__ERR__"):
        print(f"ERRO coluna {name}:", v[:120]); raise SystemExit(1)
    v = v.rstrip("\n")
    restored[name] = v
    sets.append(name + "='" + v.replace("'", "''") + "'")

print("pswmd5 original len:", len(restored.get("usr_pswmd5", "")),
      "| chksum original len:", len(restored.get("usr_chksum", "")))

upd = "UPDATE sys_usr SET " + ", ".join(sets) + " WHERE btrim(usr_codigo)='Administrador'"
r = psql(upd)
print("UPDATE:", r.strip() if not r.startswith("__ERR__") else r[:200])
