'use client';

import type { Perfil } from '@primehub/shared';
import { Camera, KeyRound, Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Casca } from './casca';
import { Esqueleto } from './esqueleto';
import { CampoSenha } from './ui/campo-senha';
import { Aviso, BotaoPrimario, CLASSE_ENTRADA, Campo, EscolherArquivo, Painel } from './ui/basico';
import { api, arquivoPublico, enviarAcao } from '@/lib/api';

const AVATARES = Array.from({ length: 24 }, (_, i) => i + 1);

/** Atualiza a foto guardada no navegador (menu lateral/cabeçalho). */
function atualizarFotoSalva(foto: string | null) {
  try {
    const u = JSON.parse(localStorage.getItem('ph_usuario') ?? 'null') as { foto: string | null } | null;
    if (u) localStorage.setItem('ph_usuario', JSON.stringify({ ...u, foto }));
  } catch { /* sem armazenamento: só não atualiza o cache */ }
}

export function PaginaPerfil({ visao }: { visao: 'equipe' | 'cliente' }) {
  const arquivo = visao === 'equipe' ? 'perfil.php' : 'portal/perfil.php';
  const [p, setP] = useState<Perfil | null>(null);
  const [dados, setDados] = useState({ nome: '', email: '', telefone: '', documento: '' });
  const [senha, setSenha] = useState({ senha_atual: '', nova_senha: '', confirmar_senha: '' });
  const [aviso, setAviso] = useState<{ onde: string; texto: string; erro: boolean }>({ onde: '', texto: '', erro: false });
  const [ocupado, setOcupado] = useState('');

  const carregar = () => api<Perfil>('/api/perfil').then((x) => {
    setP(x); setDados({ nome: x.nome ?? '', email: x.email ?? '', telefone: x.telefone ?? '', documento: x.documento ?? '' }); atualizarFotoSalva(x.foto);
  });
  useEffect(() => { void carregar(); }, []);

  async function agir(onde: string, campos: Record<string, string>, arquivos: Record<string, File | null> = {}) {
    setOcupado(onde); setAviso({ onde, texto: '', erro: false });
    try {
      const r = await enviarAcao(arquivo, campos, arquivos);
      setAviso({ onde, texto: r.mensagens.sucesso ?? 'Pronto!', erro: false });
      await carregar();
      if (onde === 'senha') setSenha({ senha_atual: '', nova_senha: '', confirmar_senha: '' });
    } catch (e) { setAviso({ onde, texto: e instanceof Error ? e.message : 'Não deu certo.', erro: true }); }
    setOcupado('');
  }

  const foto = arquivoPublico(p?.foto);
  return (
    <Casca tipo={visao} titulo="Meu perfil">
      {!p ? <div role="status" aria-label="Carregando" className="space-y-4"><Esqueleto className="h-48" /><Esqueleto className="h-64" /></div> : (
        <div className="grid gap-4 lg:grid-cols-2">
          <Painel titulo="Foto ou avatar" className="lg:col-span-2">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
              {foto ? <img src={foto} alt="Sua foto" className="h-24 w-24 rounded-full object-cover ring-4 ring-primaria/30" />
                : <span className="gradiente-marca flex h-24 w-24 items-center justify-center rounded-full text-3xl font-bold text-white">{p.nome.slice(0, 1)}</span>}
              <div className="space-y-2">
                <EscolherArquivo rotulo="Enviar minha foto" aceita="image/jpeg,image/png,image/webp" aoEscolher={(f) => { if (f[0]) void agir('foto', { acao: 'atualizar_foto' }, { foto: f[0] }); }} />
                <p className="flex items-center gap-1 text-xs text-suave"><Camera size={14} aria-hidden="true" />Ou escolha um avatar pronto:</p>
              </div>
            </div>
            <ul className="mt-4 grid grid-cols-6 gap-2 sm:grid-cols-8 lg:grid-cols-12">
              {AVATARES.map((n) => {
                const caminho = `assets/img/avatares/avatar-${n}.svg`;
                return (
                  <li key={n}>
                    <button type="button" disabled={!!ocupado} onClick={() => void agir('foto', { acao: 'escolher_avatar_predefinido', avatar: String(n) })} aria-label={`Usar avatar ${n}`} aria-pressed={p.foto === caminho}
                      className={`aspect-square w-full overflow-hidden rounded-full border-2 transition hover:scale-105 ${p.foto === caminho ? 'border-primaria' : 'border-transparent'}`}>
                      <img src={`/${caminho}`} alt="" className="h-full w-full" loading="lazy" />
                    </button>
                  </li>
                );
              })}
            </ul>
            {aviso.onde === 'foto' && <div className="mt-3"><Aviso texto={aviso.texto} erro={aviso.erro} /></div>}
          </Painel>

          {visao === 'equipe' ? (
            <>
              <Painel titulo="Meus dados">
                <form onSubmit={(e) => { e.preventDefault(); void agir('dados', { acao: 'atualizar_dados', ...dados }); }} className="space-y-4">
                  <Campo rotulo="Nome *"><input required value={dados.nome} onChange={(e) => setDados({ ...dados, nome: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                  <Campo rotulo="E-mail *"><input type="email" required value={dados.email} onChange={(e) => setDados({ ...dados, email: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                  <Campo rotulo="Telefone / WhatsApp"><input type="tel" value={dados.telefone} onChange={(e) => setDados({ ...dados, telefone: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                  <Campo rotulo="CPF/RG"><input inputMode="numeric" value={dados.documento} onChange={(e) => setDados({ ...dados, documento: e.target.value })} className={CLASSE_ENTRADA} /></Campo>
                  {aviso.onde === 'dados' && <Aviso texto={aviso.texto} erro={aviso.erro} />}
                  <BotaoPrimario type="submit" disabled={!!ocupado} className="w-full"><Save size={18} aria-hidden="true" /> Salvar dados</BotaoPrimario>
                </form>
              </Painel>
              <Painel titulo="Trocar senha">
                <form onSubmit={(e) => { e.preventDefault(); void agir('senha', { acao: 'trocar_senha', ...senha }); }} className="space-y-4">
                  <CampoSenha rotulo="Senha atual" valor={senha.senha_atual} aoMudar={(v) => setSenha({ ...senha, senha_atual: v })} autoComplete="current-password" />
                  <CampoSenha rotulo="Nova senha" valor={senha.nova_senha} aoMudar={(v) => setSenha({ ...senha, nova_senha: v })} dica="Mínimo 8 caracteres, com maiúscula, minúscula, número e símbolo." />
                  <CampoSenha rotulo="Confirmar nova senha" valor={senha.confirmar_senha} aoMudar={(v) => setSenha({ ...senha, confirmar_senha: v })} />
                  {aviso.onde === 'senha' && <Aviso texto={aviso.texto} erro={aviso.erro} />}
                  <BotaoPrimario type="submit" disabled={!!ocupado} className="w-full"><KeyRound size={18} aria-hidden="true" /> Trocar senha</BotaoPrimario>
                </form>
              </Painel>
            </>
          ) : (
            <Painel titulo="Meus dados" className="lg:col-span-2">
              <dl className="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt className="text-suave">Nome</dt><dd className="font-semibold text-texto">{p.nome}</dd></div>
                <div><dt className="text-suave">E-mail</dt><dd className="font-semibold text-texto">{p.email ?? '—'}</dd></div>
                <div><dt className="text-suave">Telefone</dt><dd className="font-semibold text-texto">{p.telefone ?? '—'}</dd></div>
              </dl>
              <p className="mt-3 text-xs text-suave">Para mudar nome, e-mail ou senha, fale com a nossa equipe por um chamado.</p>
            </Painel>
          )}
        </div>
      )}
    </Casca>
  );
}
