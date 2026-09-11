# rc21 Design Core MCP Bridge.
# Build: docker build -t design-core-mcp ./mcp-server
# Run:   see ../docker-compose.yml (attaches to the design-core-internal network).

FROM node:20-slim AS build
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY tsconfig.json ./
COPY src ./src
COPY test ./test
RUN npm run build

FROM node:20-slim AS runtime
ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --omit=dev
COPY --from=build /app/dist ./dist

# Bearer tokens/site config arrive via environment variables at `docker run`/compose time
# (see .env.example) -- never baked into the image.
EXPOSE 3000
USER node
CMD ["node", "dist/src/server.js"]
