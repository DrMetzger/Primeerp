'use client';

import { useEffect } from 'react';
import { EsqueletoDashboard } from '@/components/esqueleto';
import { usuarioSalvo } from '@/lib/sessao';

export default function Inicio() {
  useEffect(() => {
    const u = usuarioSalvo();
    location.replace(u?.tipo === 'equipe' ? '/painel' : u?.tipo === 'cliente' ? '/cliente' : '/login');
  }, []);
  return <main className="p-6"><EsqueletoDashboard /></main>;
}
