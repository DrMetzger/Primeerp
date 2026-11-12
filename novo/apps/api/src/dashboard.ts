import type { ChamadoResumo, Dashboard, ItemRanking, Kpi, PontoSemana, StatusChamado, UsuarioLogado } from '@primehub/shared';
import { filtroEscopoEquipe } from './auth';
import { consulta, primeira } from './db';
import { ultimosAutores } from './pessoas';

function variacao(atual: number, anterior: number): number | null {
  if (!anterior) return null;
  return Math.round(((atual - anterior) / anterior) * 100);
}

function kpi(rotulo: string, valor: number, anterior: number | null, subirEhBom: boolean, sufixo?: string): Kpi {
  return { rotulo, valor, variacao: anterior === null ? null : variacao(valor, anterior), subirEhBom, ...(sufixo ? { sufixo } : {}) };
}

/** Linha de chamado + horas esperando resposta nossa (última msg do cliente sem resposta). */
export const SQL_CHAMADO_RESUMO = `
  SELECT ch.id, ch.titulo, ch.status, ch.prioridade, c.nome AS cliente, ch.cliente_id AS clienteId, c.logo AS clienteLogo, u.nome AS responsavel, u.foto_perfil AS responsavelFoto,
         ch.criado_em AS criadoEm, ch.atualizado_em AS atualizadoEm,
         CASE WHEN ch.status IN ('aberto','aguardando_equipe') THEN
           TIMESTAMPDIFF(HOUR, COALESCE((SELECT MAX(r.criado_em) FROM chamado_respostas r WHERE r.chamado_id = ch.id AND r.autor_tipo = 'cliente'), ch.criado_em), NOW())
         END AS horasEsperando
    FROM chamados ch
    LEFT JOIN clientes c ON c.id = ch.cliente_id
    LEFT JOIN usuarios u ON u.id = ch.responsavel_id`;

export async function montarDashboard(usuario: UsuarioLogado): Promise<Dashboard> {
  const f = await filtroEscopoEquipe(usuario);
  const w = (extra: string) => `WHERE ${f.sql} AND ${extra}`;
  const p = f.params;
  const num = async (sql: string, params: unknown[] = p) => Number((await primeira<{ n: number | null }>(sql, params))?.n ?? 0);

  const mediaPrimeiraResposta = (dias: number) => num(
    `SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, ch.criado_em, pr.primeira)) / 60, 1) n FROM chamados ch
       JOIN (SELECT chamado_id, MIN(criado_em) primeira FROM chamado_respostas WHERE autor_tipo = 'equipe' GROUP BY chamado_id) pr ON pr.chamado_id = ch.id
     ${w(`ch.criado_em >= NOW() - INTERVAL ${dias} DAY AND ch.criado_em < NOW() - INTERVAL ${dias - 30} DAY`)}`);
  const mediaResolucao = (dias: number) => num(
    `SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, ch.criado_em, ch.fechado_em)) / 60, 1) n FROM chamados ch
     ${w(`ch.status = 'fechado' AND ch.fechado_em >= NOW() - INTERVAL ${dias} DAY AND ch.fechado_em < NOW() - INTERVAL ${dias - 30} DAY`)}`);

  const [pendentes, urgentes, abertos30, abertosAnt, fechados30, fechadosAnt, resp30, respAnt, resol30, resolAnt] = await Promise.all([
    num(`SELECT COUNT(*) n FROM chamados ch ${w("ch.status IN ('aberto','aguardando_equipe')")}`),
    num(`SELECT COUNT(*) n FROM chamados ch ${w("ch.status IN ('aberto','aguardando_equipe') AND ch.prioridade IN ('alta','urgente')")}`),
    num(`SELECT COUNT(*) n FROM chamados ch ${w('ch.criado_em >= NOW() - INTERVAL 30 DAY')}`),
    num(`SELECT COUNT(*) n FROM chamados ch ${w('ch.criado_em >= NOW() - INTERVAL 60 DAY AND ch.criado_em < NOW() - INTERVAL 30 DAY')}`),
    num(`SELECT COUNT(*) n FROM chamados ch ${w("ch.status = 'fechado' AND ch.fechado_em >= NOW() - INTERVAL 30 DAY")}`),
    num(`SELECT COUNT(*) n FROM chamados ch ${w("ch.status = 'fechado' AND ch.fechado_em >= NOW() - INTERVAL 60 DAY AND ch.fechado_em < NOW() - INTERVAL 30 DAY")}`),
    mediaPrimeiraResposta(30),
    mediaPrimeiraResposta(60),
    mediaResolucao(30),
    mediaResolucao(60),
  ]);

  const statusLinhas = await consulta<{ status: StatusChamado; n: number }>(
    `SELECT ch.status, COUNT(*) n FROM chamados ch ${w("(ch.status <> 'fechado' OR ch.fechado_em >= NOW() - INTERVAL 30 DAY)")} GROUP BY ch.status`, p);
  const porStatus: Record<StatusChamado, number> = { aberto: 0, aguardando_equipe: 0, aguardando_cliente: 0, fechado: 0 };
  for (const l of statusLinhas) porStatus[l.status] = Number(l.n);

  // 12 semanas: abertos x fechados (segunda-feira de cada semana).
  const abertosSem = await consulta<{ s: string; n: number }>(
    `SELECT DATE_FORMAT(DATE_SUB(DATE(ch.criado_em), INTERVAL WEEKDAY(ch.criado_em) DAY), '%Y-%m-%d') s, COUNT(*) n FROM chamados ch
     ${w('ch.criado_em >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 77) DAY)')} GROUP BY s`, p);
  const fechadosSem = await consulta<{ s: string; n: number }>(
    `SELECT DATE_FORMAT(DATE_SUB(DATE(ch.fechado_em), INTERVAL WEEKDAY(ch.fechado_em) DAY), '%Y-%m-%d') s, COUNT(*) n FROM chamados ch
     ${w("ch.status = 'fechado' AND ch.fechado_em >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 77) DAY)")} GROUP BY s`, p);
  const semanas: PontoSemana[] = [];
  const hoje = new Date();
  const segunda = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - ((hoje.getDay() + 6) % 7));
  for (let i = 11; i >= 0; i--) {
    const d = new Date(segunda.getFullYear(), segunda.getMonth(), segunda.getDate() - i * 7);
    const chave = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    semanas.push({
      semana: chave,
      abertos: Number(abertosSem.find((x) => x.s === chave)?.n ?? 0),
      fechados: Number(fechadosSem.find((x) => x.s === chave)?.n ?? 0),
    });
  }

  const porCliente = (await consulta<ItemRanking>(
    `SELECT c.id, c.nome, COUNT(*) valor, c.logo foto FROM chamados ch JOIN clientes c ON c.id = ch.cliente_id
     ${w('ch.criado_em >= NOW() - INTERVAL 30 DAY')} GROUP BY c.id, c.nome, c.logo ORDER BY valor DESC LIMIT 8`, p))
    .map((r) => ({ ...r, valor: Number(r.valor) }));

  const porResponsavel = (await consulta<ItemRanking>(
    `SELECT COALESCE(u.id, 0) id, COALESCE(u.nome, 'Sem responsável') nome, COUNT(*) valor, u.foto_perfil foto
       FROM chamados ch LEFT JOIN usuarios u ON u.id = ch.responsavel_id
     ${w("ch.status IN ('aberto','aguardando_equipe')")} GROUP BY u.id, u.nome, u.foto_perfil ORDER BY valor DESC LIMIT 8`, p))
    .map((r) => ({ ...r, valor: Number(r.valor) }));

  const maisAntigos = (await consulta<ChamadoResumo>(
    `${SQL_CHAMADO_RESUMO} ${w("ch.status IN ('aberto','aguardando_equipe')")} ORDER BY horasEsperando DESC LIMIT 6`, p))
    .map((r) => ({ ...r, horasEsperando: r.horasEsperando === null ? null : Number(r.horasEsperando) }));
  const autoresAntigos = await ultimosAutores(maisAntigos.map((c) => c.id));
  for (const c of maisAntigos) c.ultimo = autoresAntigos.get(c.id) ?? null;

  const ia = await primeira<{ a: number; e: number; s: number; t: number }>(
    `SELECT COUNT(*) a, SUM(ia.status = 'enviada') e, SUM(ia.status = 'sugerida') s, COALESCE(SUM(ia.tokens_entrada + ia.tokens_saida), 0) t
       FROM chamado_ia_analises ia JOIN chamados ch ON ch.id = ia.chamado_id ${w('ia.atualizado_em >= NOW() - INTERVAL 30 DAY')}`, p)
    .catch(() => null);

  return {
    geradoEm: new Date().toISOString(),
    kpis: {
      pendentesNossos: kpi('Aguardando nós', pendentes, null, false),
      abertos30d: kpi('Chamados novos (30 dias)', abertos30, abertosAnt, false),
      fechados30d: kpi('Resolvidos (30 dias)', fechados30, fechadosAnt, true),
      primeiraRespostaHoras: kpi('1ª resposta (média)', resp30, respAnt || null, false, 'h'),
      resolucaoHoras: kpi('Tempo até resolver (média)', resol30, resolAnt || null, false, 'h'),
      urgentesPendentes: kpi('Urgentes/alta pendentes', urgentes, null, false),
    },
    porStatus,
    semanas,
    porCliente,
    porResponsavel,
    maisAntigos,
    ia: {
      analises30d: Number(ia?.a ?? 0),
      respondidasSozinha30d: Number(ia?.e ?? 0),
      sugestoes30d: Number(ia?.s ?? 0),
      tokens30d: Number(ia?.t ?? 0),
    },
  };
}
