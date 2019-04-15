FROM xigen/php:cli-composer as composer

ENV APP_ENV dev

COPY . /app/
COPY .env.production /app/.env
WORKDIR /app
RUN composer install --no-dev -o --ignore-platform-reqs

# Use a smaller image for production
FROM xigen/php:cli-73

# Set the correct timezone for logs
ENV TZ Europe/London

COPY --from=composer /app /app
RUN rm -rf /app/var/cache/* && \
  /app/bin/console --env="dev" cache:clear && \
  /app/bin/console --env="dev" cache:warmup  && \
  chmod 777 -R /app/var/cache/

WORKDIR /app

ENTRYPOINT ["bin/console"]
