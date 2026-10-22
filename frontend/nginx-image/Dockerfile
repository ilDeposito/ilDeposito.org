# Immagine di frontend-web: serve i file statici di current/client e fa da
# reverse proxy di /api/* verso frontend-api (vedi docs/frontend.md).
#
# Sostituisce macbre/nginx-brotli, fermo a nginx 1.21.0 (ultimo push
# 2021-07-06), solo amd64 e senza aggiornamenti a monte. Qui nginx e il modulo
# brotli arrivano dallo stesso set di pacchetti Alpine: si aggiornano insieme e
# non esiste il rischio tipico di ricompilare ngx_brotli a mano (mismatch ABI
# tra nginx e modulo dinamico).
#
# Base pinnata per digest: un `docker build` non cambia il sistema operativo
# sotto i piedi. Per aggiornare: verificare il digest corrente di alpine:3.24
# (Docker Hub o `docker manifest inspect alpine:3.24`) e la versione dei
# pacchetti nel ramo v3.24.
FROM alpine:3.24@sha256:71e9b7bae160ba167fa17414401b94cbcdaf3c32feeab9298bd794a2a0614c35

# - nginx: pacchetto Alpine (include --with-compat, realip, gzip_static).
# - nginx-mod-http-brotli: ngx_brotli (brotli/brotli_static di nginx.conf);
#   dipende da nginx=$pkgver-r$pkgrel, quindi apk allinea sempre le due versioni.
# - gettext-envsubst: `envsubst` per nginx-realip.conf.template, usato sia dal
#   command del container sia da validate_nginx_config in ./ildeposito.sh.
# Le versioni dei pacchetti non sono pinnate di proposito: v3.24 è il ramo
# patchato dal security team Alpine, e un pin esatto (-rN) farebbe fallire la
# build nel momento in cui Alpine pubblica una revisione. Le versioni installate
# finiscono nei log di build (`nginx -v`, `apk info -v`).
RUN apk add --no-cache \
        nginx \
        nginx-mod-http-brotli \
        gettext-envsubst \
    && nginx -v \
    && apk info -v nginx nginx-mod-http-brotli

# error_log/access_log sono aperti dal master all'avvio. Il volume
# ildeposito_404_log (compose) monta già /var/log/nginx, ma la directory deve
# esistere comunque: senza, nginx non parte e il container entra in restart-loop.
# conf.d non è nel pacchetto Alpine (usa http.d): la creiamo perché è il
# contesto in cui compose monta i file e in cui validate_nginx_config scrive
# realip.conf.
RUN mkdir -p /var/log/nginx /etc/nginx/conf.d

EXPOSE 80

# SIGQUIT = shutdown graceful: il SIGTERM di default di docker interromperebbe
# le connessioni in corso. Con `daemon off` nginx resta in foreground come PID 1.
STOPSIGNAL SIGQUIT

CMD ["nginx", "-g", "daemon off;"]
