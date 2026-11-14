# eLabFTW 6.0.6. Pin the image used for this deployment; do not follow latest.
ARG SMARTLAB_BASE_IMAGE=elabftw/elabimg@sha256:d74a231bc5aad29b992677d4c5d4ab30b93b89319753df1ff6f01ff2c695c01c
FROM ${SMARTLAB_BASE_IMAGE}

WORKDIR /elabftw

# Read text-based antibody manuals locally; no external AI service is required.
ARG SMARTLAB_APK_MIRROR=
RUN if [ -n "$SMARTLAB_APK_MIRROR" ]; then sed -i "s#https://dl-cdn.alpinelinux.org/alpine#${SMARTLAB_APK_MIRROR%/}#g" /etc/apk/repositories; fi \
    && (command -v pdftotext >/dev/null 2>&1 || apk add --no-cache poppler-utils)

COPY apps/elabftw/src/ /elabftw/src/
COPY apps/elabftw/web/ /elabftw/web/

# This source tree reads the API route from the historical `req` query parameter.
# The pinned image routes v2 requests without it; preserve the original URI for
# the existing controller while retaining the image's TLS and access controls.
RUN sed -i '/rewrite \^\/api\/v2\//s#ApiController.php break;#ApiController.php?req=$uri break;#' /etc/nginx/common.conf.tpl \
    && grep -Fq 'ApiController.php?req=$uri break;' /etc/nginx/common.conf.tpl

# Keep the legacy temporary path inside the image's permitted PHP cache path.
# rmdir deliberately fails if the base image's /tmp is not empty.
RUN rmdir /tmp && ln -s /var/cache/elabftw/php/tmp /tmp

# 本地/测试构建说明：
# 1) 新版官方基础镜像不含 composer 二进制，无法在构建期执行 composer dump-autoload；
# 2) 镜像自带的 vendor/autoload 使用 authoritative classmap，不会加载 SmartLab 新增的类；
# 3) 关闭 authoritative 后，composer 会按 PSR-4（composer.json: "Elabftw\\" => "src/"）
#    从磁盘直接加载 src/ 下的全部类（含新增的 Smartlab* 模型/控制器）。
RUN sed -i 's/setClassMapAuthoritative(true)/setClassMapAuthoritative(false)/' /elabftw/vendor/composer/autoload_real.php \
    && grep -q 'setClassMapAuthoritative(false)' /elabftw/vendor/composer/autoload_real.php

# 本地开发：Twig 编译缓存的目录名固定（twig-bench-*），源码更新后仍会复用旧编译模板。
# 让容器启动清缓存时把 Twig 编译缓存一并删除，保证每次重建后渲染最新模板。
RUN sed -i 's#rm -rf /var/cache/elabftw/php/\* /var/cache/elabftw/nginx/\*#rm -rf /var/cache/elabftw/php/* /var/cache/elabftw/nginx/* /var/cache/elabftw/twig-* /elabftw/cache/twig-*#' /usr/sbin/docker-entrypoint.sh \
    && grep -Fq '/elabftw/cache/twig-*' /usr/sbin/docker-entrypoint.sh
