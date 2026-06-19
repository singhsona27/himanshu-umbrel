FROM golang:1.22-bookworm AS builder

WORKDIR /src
RUN git clone --depth 1 https://github.com/etclabscore/core-pool.git .
RUN go mod download
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/core-pool .

FROM debian:12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates gettext-base \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=builder /out/core-pool /app/core-pool
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8008 8888 8080
ENTRYPOINT ["/entrypoint.sh"]
