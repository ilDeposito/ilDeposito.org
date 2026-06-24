FROM node:22-alpine
RUN apk add --no-cache tzdata
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

CMD ["sh", "docker-entrypoint.sh"]
