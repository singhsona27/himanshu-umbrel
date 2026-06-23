FROM debian:12-slim

ARG BCHN_VERSION=29.0.0
ARG TARGETARCH

RUN apt-get update \
  && apt-get install -y --no-install-recommends ca-certificates curl tar \
  && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
  case "${TARGETARCH:-amd64}" in \
    amd64) arch="x86_64-linux-gnu"; checksum="6125d1cbecc1db476f2b6b7b91da5acde92d2311b8e738124e3db64ca84b33e1" ;; \
    arm64) arch="aarch64-linux-gnu"; checksum="67f0006876ebb57bbef63f7c9804fb40a8b513a42f10060b0592846a9d4dd8f1" ;; \
    *) echo "Unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
  esac; \
  base="bitcoin-cash-node-${BCHN_VERSION}"; \
  url="https://github.com/bitcoin-cash-node/bitcoin-cash-node/releases/download/v${BCHN_VERSION}/${base}-${arch}.tar.gz"; \
  curl -fsSL --retry 5 --retry-delay 2 "$url" -o /tmp/bchn.tar.gz; \
  echo "${checksum}  /tmp/bchn.tar.gz" | sha256sum -c -; \
  tar -xzf /tmp/bchn.tar.gz -C /tmp; \
  install -m 0755 "/tmp/${base}/bin/bitcoind" /usr/local/bin/bitcoind; \
  install -m 0755 "/tmp/${base}/bin/bitcoin-cli" /usr/local/bin/bitcoin-cli; \
  rm -rf /tmp/bchn.tar.gz "/tmp/${base}"

EXPOSE 8332 8333 28332
VOLUME ["/data"]
ENTRYPOINT []
CMD ["bitcoind", "-datadir=/data"]
