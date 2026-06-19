FROM debian:12-slim

ARG DIGIBYTE_VERSION=8.26.2
ARG TARGETARCH

RUN apt-get update \
  && apt-get install -y --no-install-recommends ca-certificates curl tar xz-utils \
  && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
  arch="x86_64-linux-gnu"; \
  if [ "${TARGETARCH:-amd64}" = "arm64" ]; then arch="aarch64-linux-gnu"; fi; \
  url="https://github.com/DigiByte-Core/digibyte/releases/download/v${DIGIBYTE_VERSION}/digibyte-${DIGIBYTE_VERSION}-${arch}.tar.gz"; \
  curl -fsSL "$url" -o /tmp/digibyte.tar.gz; \
  tar -xzf /tmp/digibyte.tar.gz -C /tmp; \
  install -m 0755 /tmp/digibyte-${DIGIBYTE_VERSION}/bin/digibyted /usr/local/bin/digibyted; \
  install -m 0755 /tmp/digibyte-${DIGIBYTE_VERSION}/bin/digibyte-cli /usr/local/bin/digibyte-cli; \
  rm -rf /tmp/digibyte*

EXPOSE 12024 14022
VOLUME ["/data"]
ENTRYPOINT []
CMD ["digibyted", "-datadir=/data", "-printtoconsole"]
