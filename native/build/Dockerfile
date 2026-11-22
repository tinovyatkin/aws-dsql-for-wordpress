# Debian 12 / x86_64 with the deployment's own Sury PHP packages, so the binary matches the
# production ABI exactly (NTS; the official php:8.6 images are built with --enable-zts).
# An extension is bound to one PHP minor (its Zend API number), so CI builds one per minor.
FROM rust:1.98.1-bookworm@sha256:828077e0f5ed0401fbd9cb5b4d5dedca23bd13c7fe032f3e8b7313e7acd2a57f AS build
ARG PHP_MINOR=8.6
# Signing key trusted by the production origin for packages.sury.org.
ARG SURY_FINGERPRINT=15058500A0235D97F5D10063B188E2B695BD4743
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg libclang-dev cmake pkg-config python3 binutils; \
    curl -fsSLo /tmp/sury-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb; \
    dpkg -i /tmp/sury-keyring.deb; \
    gpg --show-keys --with-colons /usr/share/keyrings/debsuryorg-archive-keyring.gpg | grep -qx "fpr:::::::::${SURY_FINGERPRINT}:"; \
    echo 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ bookworm main' > /etc/apt/sources.list.d/php.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends "php${PHP_MINOR}-cli" "php${PHP_MINOR}-dev"; \
    update-alternatives --set php "/usr/bin/php${PHP_MINOR}"; \
    update-alternatives --set php-config "/usr/bin/php-config${PHP_MINOR}"; \
    php -n -r 'exit(PHP_ZTS ? 1 : 0);'; \
    rm -rf /var/lib/apt/lists/* /tmp/sury-keyring.deb
ENV PHP_CONFIG=/usr/bin/php-config${PHP_MINOR}
WORKDIR /build/native
COPY native/Cargo.toml native/Cargo.lock ./
COPY native/src ./src
COPY native/tests/*.rs ./tests/
COPY native/examples ./examples
COPY native/build/smoke.php /build/smoke.php
RUN cargo test --locked --no-default-features && cargo build --locked --release
RUN mkdir /artifact && cp target/release/libwp_dsql_native.so /artifact/wp_dsql_native.so && \
    php -n -d extension=/artifact/wp_dsql_native.so /build/smoke.php && \
    ldd /artifact/wp_dsql_native.so > /artifact/ldd.txt && \
    readelf --version-info /artifact/wp_dsql_native.so > /artifact/elf-versions.txt
ARG SOURCE_COMMIT=unknown
ENV WPDSQL_SOURCE_COMMIT=${SOURCE_COMMIT}
COPY native/build/manifest.py /build/manifest.py
RUN python3 /build/manifest.py /artifact
FROM scratch AS artifact
COPY --from=build /artifact/ /
