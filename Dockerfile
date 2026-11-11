# Telecomlijn CRM: PHP 8.2 + Apache. The database is a separate container (see docker-compose.yml).
FROM php:8.2-apache

# Time zone of the application and of the scheduled jobs (override with CRM_TIMEZONE in .env)
ENV CRM_TIMEZONE=Europe/Amsterdam TZ=Europe/Amsterdam

# pdo_mysql is the only extension the official image lacks (mbstring, curl, openssl and iconv are built in).
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers setenvif remoteip \
 && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/conf-available/crm.conf
RUN a2enconf crm \
 && sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-crm.ini
COPY docker/entrypoint.sh docker/scheduler.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/scheduler.sh

# The application. includes/config.local.php, storage/* and node_modules are left out by .dockerignore:
# secrets come from the environment and the runtime folder is a volume.
COPY --chown=www-data:www-data . /var/www/html/
RUN mkdir -p /var/www/html/storage && chown www-data:www-data /var/www/html/storage

HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/auth/login.php") === false ? 1 : 0);'

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
