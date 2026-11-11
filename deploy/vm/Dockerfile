# Prime Hub na VM (suporte.primeerp.com.br): PHP 8.1 + Apache, mesma versão
# da Hostinger, pra não mudar o comportamento do sistema na migração.
FROM php:8.1-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends cron libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev tzdata \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip gd \
 && a2enmod rewrite headers remoteip \
 && sed -ri 's#<Directory /var/www/>#<Directory /var/www/>\n\tOptions -Indexes +FollowSymLinks#; /<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*

# Atrás do Caddy do vanguard: IP real do cliente e HTTPS repassados pelo proxy.
RUN printf 'RemoteIPHeader X-Forwarded-For\nRemoteIPInternalProxy 172.16.0.0/12\nSetEnvIf X-Forwarded-Proto https HTTPS=on\n' > /etc/apache2/conf-enabled/proxy.conf

COPY deploy/vm/php.ini /usr/local/etc/php/conf.d/primehub.ini
COPY deploy/vm/entrypoint.sh /usr/local/bin/primehub-entrypoint
RUN chmod +x /usr/local/bin/primehub-entrypoint

COPY assets/avatars/.htaccess /opt/primehub-htaccess-avatars
COPY --chown=www-data:www-data . /var/www/html
RUN rm -rf /var/www/html/deploy /var/www/html/.github /var/www/html/.git

ENTRYPOINT ["primehub-entrypoint"]
CMD ["apache2-foreground"]
