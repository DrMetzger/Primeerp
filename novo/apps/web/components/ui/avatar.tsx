import { arquivoPublico } from '@/lib/api';

const CORES = ['from-sky-500 to-indigo-600', 'from-emerald-500 to-teal-600', 'from-fuchsia-500 to-purple-600', 'from-amber-500 to-orange-600', 'from-rose-500 to-pink-600', 'from-cyan-500 to-blue-600'];

/** Foto da pessoa; sem foto, iniciais numa cor fixa por nome. */
export function Avatar({ nome, foto, tamanho = 36, anel }: { nome: string; foto?: string | null; tamanho?: number; anel?: string }) {
  const src = arquivoPublico(foto);
  const estilo = { width: tamanho, height: tamanho };
  const classeAnel = anel ? `ring-2 ${anel}` : 'ring-2 ring-borda';
  if (src) return <img src={src} alt="" style={estilo} className={`shrink-0 rounded-full bg-superficie-alta object-cover ${classeAnel}`} />;
  const iniciais = nome.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0] ?? '').join('').toUpperCase() || '?';
  const cor = CORES[[...nome].reduce((s, c) => s + c.charCodeAt(0), 0) % CORES.length];
  return <span aria-hidden="true" style={{ ...estilo, fontSize: tamanho * 0.38 }} className={`flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br font-bold text-white ${cor} ${classeAnel}`}>{iniciais}</span>;
}
