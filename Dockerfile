# FelFact API (PHP 8.5 + Apache), sobre LibreDTE.
# Multiarquitectura.
FROM php:8.5-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libxml2-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libzip-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" soap gd intl bcmath zip \
 && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'date.timezone=America/Santiago\nmemory_limit=512M\nmax_execution_time=120\npost_max_size=8M\nexpose_php=Off\n' > "$PHP_INI_DIR/conf.d/libredte.ini"

# Intermedios que algunos servidores del SII no envían (pangal.sii.cl, 27-10-2026: falta GeoTrust TLS RSA CA G1).
COPY certs/ /usr/local/share/ca-certificates/sii/
RUN update-ca-certificates

ENV TZ=America/Santiago \
    OPENSSL_CONF=/app/openssl.cnf

COPY apache.conf /etc/apache2/sites-available/000-default.conf
RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/zz-libredte.conf

WORKDIR /app
COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
 && rm /usr/bin/composer

COPY bin ./bin
COPY config ./config
COPY public ./public
COPY plantillas ./plantillas
COPY openssl.cnf ./

# Caché del contenedor de LibreDTE: se compila en la construcción, como www-data.
# La comprobación RC2-40 confirma que el proveedor "legacy" de OpenSSL (certificados del SII) está activo.
RUN mkdir -p var && chown www-data:www-data var \
 && su www-data -s /bin/sh -c "php -r 'require \"vendor/autoload.php\"; libredte\lib\Core\Application::getInstance(\"prod\", false)->getPackageRegistry();'" \
 && php -r 'exit(openssl_encrypt("x", "rc2-40-cbc", "k", 0, "12345678") === false ? 1 : 0);' \
 && php -r 'exit(function_exists("sodium_crypto_secretbox") ? 0 : 1);'
