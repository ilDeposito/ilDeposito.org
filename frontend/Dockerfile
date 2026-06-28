FROM node:22-alpine
RUN apk add --no-cache tzdata su-exec rsync
WORKDIR /app

COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY package.json package-lock.json ./
RUN npm ci

COPY --chown=node:node . .

ENTRYPOINT ["entrypoint.sh"]
CMD ["sh", "docker-entrypoint.sh"]
