# Build Rails against the same Ruby version recorded in Gemfile.lock.
FROM ruby:4.0.5-slim-bookworm@sha256:3c75b01d27398bbf3010b35612b957d268980e6bc3ec9419da043b22f90e5c88 AS build

ENV BUNDLE_PATH=/usr/local/bundle \
    BUNDLE_WITHOUT="development:test" \
    BUNDLE_JOBS=4 \
    BUNDLE_RETRY=3

RUN apt-get update && apt-get install -y --no-install-recommends \
      build-essential libargon2-dev libpq-dev libsodium-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY rails_backend/Gemfile rails_backend/Gemfile.lock ./
RUN gem install bundler -v 4.0.11 --no-document && bundle install
COPY rails_backend/ ./

FROM ruby:4.0.5-slim-bookworm@sha256:3c75b01d27398bbf3010b35612b957d268980e6bc3ec9419da043b22f90e5c88

ENV RAILS_ENV=production \
    RACK_ENV=production \
    BUNDLE_PATH=/usr/local/bundle \
    BUNDLE_WITHOUT="development:test" \
    RAILS_LOG_TO_STDOUT=1

RUN apt-get update && apt-get install -y --no-install-recommends libargon2-1 libpq5 libsodium23 \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /app/tmp/cache \
    && chown -R 33:33 /app

WORKDIR /app
COPY --from=build /usr/local/bundle /usr/local/bundle
COPY --from=build --chown=33:33 /app/ ./
USER 33:33
EXPOSE 3000
CMD ["bundle", "exec", "puma", "-b", "tcp://0.0.0.0:3000", "--workers", "2", "--threads", "5:5"]
