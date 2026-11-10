FROM node:24-bookworm-slim
RUN mkdir -p /app/node_modules && chown -R node:node /app
WORKDIR /app
USER node
CMD ["sh", "-c", "npm ci && exec npm run dev -- --host 0.0.0.0"]
