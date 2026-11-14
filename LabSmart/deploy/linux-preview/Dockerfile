# Server's existing Alpine/PHP 8.4 eLabFTW image, pinned by digest.
FROM elabftw/elabimg@sha256:500b0bcaed1b3b9f825d5272cd517072cd32f3e22d5dc68ec6f72f5a532cb745
WORKDIR /elabftw
RUN sed -i 's#https://dl-cdn.alpinelinux.org/alpine#https://mirrors.ustc.edu.cn/alpine#g' /etc/apk/repositories \
    && apk add --no-cache poppler-utils
COPY apps/elabftw/src/ /elabftw/src/
COPY apps/elabftw/web/ /elabftw/web/
RUN sed -i 's/setClassMapAuthoritative(true)/setClassMapAuthoritative(false)/' /elabftw/vendor/composer/autoload_real.php \
    && grep -Fq 'setClassMapAuthoritative(false)' /elabftw/vendor/composer/autoload_real.php \
    && grep -Fq 'ApiController.php?req=$uri' /etc/nginx/common.conf
# This instance owns its cache volume. Rebuilds must not retain old compiled pages.
RUN sed -i '/elabftwConf() {/a\    rm -rf /elabftw/cache/twig-bench-*' /usr/sbin/docker-entrypoint.sh \
    && grep -Fq '/elabftw/cache/twig-bench-*' /usr/sbin/docker-entrypoint.sh
