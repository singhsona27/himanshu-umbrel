FROM debian:12-slim AS build

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    autoconf automake build-essential ca-certificates git libcurl4-openssl-dev libjansson-dev libtool pkg-config \
  && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 https://bitbucket.org/ckolivas/ckpool.git /src/ckpool \
  && cd /src/ckpool \
  && ./autogen.sh \
  && ./configure CFLAGS="-O3" \
  && make -j"$(nproc)"

FROM debian:12-slim

RUN apt-get update \
  && apt-get install -y --no-install-recommends ca-certificates libcurl4 libjansson4 \
  && rm -rf /var/lib/apt/lists/*

COPY --from=build /src/ckpool/src/ckpool /usr/local/bin/ckpool
RUN mkdir -p /logs /run/ckpool /tmp/ckpool

EXPOSE 3333
ENTRYPOINT ["ckpool"]
