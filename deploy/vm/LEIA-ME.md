# Prime Hub na VM (suporte.primeerp.com.br)

- Tudo fica em `/opt/primehub` na VM: `app/` (código), `.env` (senhas do
  banco, geradas na própria VM), `dados/` (MySQL, uploads, fotos).
- Containers: `primehub-web` (PHP 8.1 + Apache) e `primehub-db` (MariaDB 11.4).
- O Caddy do **vanguard** (dono das portas 80/443) encaminha
  `suporte.primeerp.com.br` pro `primehub-web`. Backup da configuração
  original dele em `/opt/primehub/migracao/Caddyfile.original`.
- Deploy: workflow **VM - implantar Prime Hub** (roda a cada push que mexe
  em `deploy/vm/`).
- `deploy/vm/IMPORTAR_BANCO` existindo = o deploy **apaga o banco da VM** e
  importa de novo o da Hostinger. Só deixar esse arquivo na fase de teste e
  na troca definitiva; depois da troca, remover.
- Fase de teste: `.env` com `SEM_NOTIFICACOES=1` (não manda e-mail/WhatsApp)
  e `PRIMEHUB_CRON=0` (não responde chamados nem manda lembretes).
