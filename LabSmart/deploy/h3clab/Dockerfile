# h3clab 部署镜像：在服务器已有的 eLabFTW（Alpine/PHP8.4）镜像上叠加 SmartLab 源码。
# 基础镜像按摘要固定，与服务器缓存一致，不跟随 latest。
FROM elabftw/elabimg@sha256:500b0bcaed1b3b9f825d5272cd517072cd32f3e22d5dc68ec6f72f5a532cb745

WORKDIR /elabftw

# PDF 预览所需工具（走中科大 Alpine 镜像）
RUN sed -i 's#https://dl-cdn.alpinelinux.org/alpine#https://mirrors.ustc.edu.cn/alpine#g' /etc/apk/repositories \
    && apk add --no-cache poppler-utils

COPY apps/elabftw/src/ /elabftw/src/
COPY apps/elabftw/web/ /elabftw/web/

# 1) 关闭 authoritative classmap，让 PSR-4 加载 SmartLab 新增类
# 2) 保证 /api/v2 路由保留原始 URI（兼容既有源码的 req 参数）
# 3) 每次启动清理 Twig 编译缓存，避免复用旧模板
RUN sed -i 's/setClassMapAuthoritative(true)/setClassMapAuthoritative(false)/' /elabftw/vendor/composer/autoload_real.php \
    && grep -Fq 'setClassMapAuthoritative(false)' /elabftw/vendor/composer/autoload_real.php \
    && for f in /etc/nginx/common.conf /etc/nginx/common.conf.tpl; do \
         if [ -f "$f" ]; then sed -i 's#ApiController.php break;#ApiController.php?req=$uri break;#' "$f"; fi; \
       done \
    && { grep -Fq 'ApiController.php?req=$uri' /etc/nginx/common.conf 2>/dev/null \
         || grep -Fq 'ApiController.php?req=$uri' /etc/nginx/common.conf.tpl 2>/dev/null; } \
    && for f in /etc/nginx/common.conf /etc/nginx/common.conf.tpl; do \
         if [ -f "$f" ]; then sed -i 's#|samples.php)#|samples.php|smartlab-drive.php|smartlab-drive-api.php|smartlab-drive-file.php|smartlab-qr-api.php|smartlab-mobile-api.php|smartlab-qr-login.php|smartlab-mobile.php)#' "$f"; fi; \
       done \
    && { grep -Fq 'smartlab-drive.php' /etc/nginx/common.conf 2>/dev/null \
         || grep -Fq 'smartlab-drive.php' /etc/nginx/common.conf.tpl 2>/dev/null; } \
    && sed -i '/elabftwConf() {/a\    rm -rf /elabftw/cache/twig-bench-*' /usr/sbin/docker-entrypoint.sh \
    && grep -Fq '/elabftw/cache/twig-bench-*' /usr/sbin/docker-entrypoint.sh
