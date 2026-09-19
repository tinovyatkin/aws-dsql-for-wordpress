# Debian 12 / x86_64 and PHP 8.5.10 NTS match the deployment ABI.
FROM rust:1.98.1-bookworm@sha256:828077e0f5ed0401fbd9cb5b4d5dedca23bd13c7fe032f3e8b7313e7acd2a57f AS rust
FROM php:8.5.10-fpm-bookworm@sha256:cc45fc347dcdef585e907fa7dab27087039f2adf7bd911c22b79a3d80cc98d08 AS build
COPY --from=rust /usr/local/cargo /usr/local/cargo
COPY --from=rust /usr/local/rustup /usr/local/rustup
ENV PATH="/usr/local/cargo/bin:${PATH}" RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo
RUN apt-get update && apt-get install -y --no-install-recommends libclang-dev cmake pkg-config python3 binutils && rm -rf /var/lib/apt/lists/*
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
