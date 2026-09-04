# TenderHub web — Next.js 16 (standalone output)
#
# next.config.ts sets `output: "standalone"`, which emits a self-contained
# server bundle with only the node_modules it actually uses. The previous
# runner copied the entire node_modules tree and ran `next start` — which does
# not work with standalone output and shipped a much larger image. This copies
# only the standalone server, the static assets and public/.

# --- 1. Builder -------------------------------------------------------------
FROM node:22-alpine AS builder
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
# A JWT secret is only needed at request time; the build uses a placeholder so
# route-module evaluation during page-data collection does not fail. Real
# secrets are provided to the runtime container by docker-compose.
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

# --- 2. Runner --------------------------------------------------------------
FROM node:22-alpine AS runner
WORKDIR /app

ENV NODE_ENV=production
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

# Run as an unprivileged user.
RUN addgroup -g 1001 -S nodejs && adduser -S nextjs -u 1001

# This is an npm-workspace build, so Next's standalone output nests the app
# server under web/ (verified: standalone/web/server.js), and the static assets
# and public/ must be placed under that same web/ directory.
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./web/.next/static
COPY --from=builder --chown=nextjs:nodejs /app/public ./web/public

USER nextjs
EXPOSE 3000

# Liveness probe hits the app's own /api/health (no backend dependency).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD wget -qO- http://127.0.0.1:3000/api/health >/dev/null 2>&1 || exit 1

CMD ["node", "web/server.js"]
