#!/bin/sh
# Variáveis do container também valem pros cron jobs (o cron não herda o ambiente).
set -e
printenv | grep -E '^(ADMINPRIME_|TZ=)' | sed -E 's/^([^=]+)=(.*)$/export \1="\2"/' > /etc/primehub.env
chmod 600 /etc/primehub.env

# Uploads ficam num volume (sobrevivem a atualização) -- garante as
# proteções .htaccess e as pastas que o sistema espera.
mkdir -p /var/www/html/uploads/chamados /var/www/html/uploads/clientes /var/www/html/assets/avatars
[ -f /var/www/html/uploads/.htaccess ] || echo 'Require all denied' > /var/www/html/uploads/.htaccess
[ -f /var/www/html/assets/avatars/.htaccess ] || cp /opt/primehub-htaccess-avatars /var/www/html/assets/avatars/.htaccess 2>/dev/null || true
chown -R www-data:www-data /var/www/html/uploads /var/www/html/assets/avatars /var/www/html/assets/img

# Cron só liga quando PRIMEHUB_CRON=1 (depois da troca definitiva) --
# na fase de teste a cópia do banco não pode responder chamado nem mandar lembrete.
if [ "${PRIMEHUB_CRON:-0}" = "1" ]; then
  cat > /etc/cron.d/primehub <<'CRON'
SHELL=/bin/sh
*/5 * * * * www-data . /etc/primehub.env; /usr/local/bin/php /var/www/html/cron_ia_chamados.php >> /proc/1/fd/1 2>&1
* * * * * www-data . /etc/primehub.env; /usr/local/bin/php /var/www/html/cron_lembretes_treinamento.php >> /proc/1/fd/1 2>&1
*/15 * * * * www-data . /etc/primehub.env; /usr/local/bin/php /var/www/html/cron_lembrete_resposta_chamado.php >> /proc/1/fd/1 2>&1
CRON
  chmod 644 /etc/cron.d/primehub
  cron
fi

exec docker-php-entrypoint "$@"
