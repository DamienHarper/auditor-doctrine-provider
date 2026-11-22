ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli AS auditor-doctrine-provider

WORKDIR /app

# install PHP extensions
#  - pdo_mysql
#  - pdo_pgsql
#  - xdebug
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions @composer mbstring pdo_mysql pdo_pgsql xdebug

CMD [ "php" ]

# Blackfire stage — adds the probe extension and the `blackfire` CLI for `make profile`
FROM auditor-doctrine-provider AS auditor-doctrine-provider-blackfire
RUN install-php-extensions blackfire
COPY --from=blackfire/blackfire:2 /usr/local/bin/blackfire /usr/local/bin/blackfire
