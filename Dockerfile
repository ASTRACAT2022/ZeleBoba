FROM ruby:4.0.5-bookworm

RUN apt-get update \
  && apt-get install -y --no-install-recommends build-essential libpq-dev \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY Gemfile Gemfile.lock ./
RUN bundle config set without 'development test' \
  && bundle install --jobs 4 --retry 3

COPY . .
RUN useradd --create-home --shell /usr/sbin/nologin app \
  && mkdir -p /app/var \
  && chown -R app:app /app

USER app
EXPOSE 9292
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD ruby -rnet/http -e 'exit(Net::HTTP.get_response(URI("http://127.0.0.1:9292/health/live")).is_a?(Net::HTTPSuccess) ? 0 : 1)'
CMD ["bundle", "exec", "rackup", "--host", "0.0.0.0", "--port", "9292", "config.ru"]
