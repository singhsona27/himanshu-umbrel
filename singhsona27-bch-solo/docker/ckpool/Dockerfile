FROM debian:12-slim AS build

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    autoconf automake build-essential ca-certificates git libcurl4-openssl-dev \
    libjansson-dev libtool libzmq3-dev pkg-config \
  && rm -rf /var/lib/apt/lists/*

COPY stratifier-bch.patch /tmp/stratifier-bch.patch

RUN git init /src/ckpool \
  && cd /src/ckpool \
  && git remote add origin https://bitbucket.org/ckolivas/ckpool.git \
  && git fetch --depth 1 origin a01fcb3e0a5388258be35a831fc530882d40f14b \
  && git checkout FETCH_HEAD \
  && sed -i 's/client->ssdc < 72 && tdiff < 240/client->ssdc < 12 \&\& tdiff < 45/' src/stratifier.c \
  && git apply /tmp/stratifier-bch.patch \
  && ./autogen.sh \
  && ./configure CFLAGS="-O3 -march=native -mtune=native" \
  && make -j"$(nproc)"

FROM debian:12-slim

RUN apt-get update \
  && apt-get install -y --no-install-recommends ca-certificates curl libcurl4 libjansson4 libzmq5 \
  && rm -rf /var/lib/apt/lists/*

COPY --from=build /src/ckpool/src/ckpool /usr/local/bin/ckpool
COPY --from=build /src/ckpool/src/ckpmsg /usr/local/bin/ckpmsg
RUN mkdir -p /logs /run/ckpool /tmp/ckpool

EXPOSE 3333
ENTRYPOINT ["ckpool"]
