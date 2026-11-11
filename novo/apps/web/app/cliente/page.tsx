import { PaginaChamados } from '@/components/pagina-chamados';

export const metadata = { title: 'Meus chamados' };
export default function Pagina() {
  return <PaginaChamados visao="cliente" />;
}
