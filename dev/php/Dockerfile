# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

FROM php:8.2-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install -j2 zip \
 && rm -rf /var/lib/apt/lists/*

# Keep the verification extension contract in sync with the PHP jobs in ci.yml.
RUN php -r '$required = "ctype,date,dom,filter,iconv,json,libxml,mbstring,openssl,pcre,pdo,phar,reflection,simplexml,spl,tokenizer,xmlreader,xmlwriter,zip"; foreach (explode(",", $required) as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: $extension\n"); exit(1); } }'

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
