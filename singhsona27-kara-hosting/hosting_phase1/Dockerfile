FROM webdevops/php-apache:8.3

RUN apt-get update && \
    apt-get install -y docker.io mariadb-client zip unzip tar coreutils procps curl openssl && \
    rm -rf /var/lib/apt/lists/*

RUN groupadd -g 987 dockerhost || true && \
    usermod -aG dockerhost application || true

USER root
