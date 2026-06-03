FROM elabftw/elabimg:latest

WORKDIR /elabftw

COPY overlays/elabftw/src/ /elabftw/src/
COPY overlays/elabftw/web/ /elabftw/web/

RUN /usr/bin/php84 -d memory_limit=256M -d open_basedir='' /usr/local/bin/composer dump-autoload --optimize --classmap-authoritative --no-dev
