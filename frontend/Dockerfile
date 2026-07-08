FROM node:22-alpine
# brotli/gzip/findutils: precompressione asset statici in docker-entrypoint.sh
# (findutils/coreutils per find/xargs/cp GNU: -print0, -P, cp -al affidabili)
RUN apk add --no-cache tzdata su-exec rsync brotli gzip findutils coreutils
WORKDIR /app

COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY package.json package-lock.json ./
# Cache mount BuildKit: al cambio del lock npm riusa la cache dei pacchetti
# del daemon invece di riscaricare tutto (la cache non finisce nell'immagine).
RUN --mount=type=cache,target=/root/.npm npm ci && chown -R node:node node_modules

COPY --chown=node:node . .

ENTRYPOINT ["entrypoint.sh"]
CMD ["sh", "docker-entrypoint.sh"]
