FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Copia index.php, api.php, config.php e a pasta data exatamente como estão.
COPY . /app

# Aumenta o limite para uploads de APK e configurações em lote.
COPY php.ini /usr/local/etc/php/conf.d/configbox.ini

EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app"]
