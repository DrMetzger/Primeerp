import { TelaLogin } from '@/components/tela-login';

export const metadata = { title: 'Central do cliente' };
export default function PaginaPortal() {
  return <TelaLogin tipo="cliente" />;
}
