function obrigatoria(nome: string): string {
  const valor = process.env[nome];
  if (!valor) {
    throw new Error(`Variável de ambiente ${nome} não definida`);
  }
  return valor;
}

export const config = {
  porta: Number(process.env.PORT ?? 3000),
  db: {
    host: process.env.DB_HOST ?? 'db',
    database: process.env.DB_NAME ?? 'primehub',
    user: process.env.DB_USER ?? 'primehub',
    password: obrigatoria('DB_PASS'),
  },
  /** Assina o token de login da API nova (site e app). */
  jwtSegredo: new TextEncoder().encode(obrigatoria('JWT_SECRET')),
  /** Compartilhado com o PHP (sso.php) pra abrir as telas clássicas já logado. */
  ssoSegredo: obrigatoria('SSO_SECRET'),
  urlBase: (process.env.URL_BASE ?? 'https://suporte.primeerp.com.br').replace(/\/$/, ''),
  /** Pasta com primehub.apk + versao.json (publicados pelo workflow do Android). */
  pastaDownloads: process.env.DOWNLOADS_DIR ?? '/downloads',
} as const;
