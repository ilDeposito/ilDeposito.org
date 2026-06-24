FROM node:22-alpine
RUN apk add --no-cache tzdata
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY --chown=node:node . .
RUN mkdir -p /app/output && chown node:node /app/output

USER node

CMD ["sh", "docker-entrypoint.sh"]
