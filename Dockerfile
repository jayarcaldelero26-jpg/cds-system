FROM richarvey/nginx-php-fpm:3.1.6

# I-copy ang tibuok project ngadto sa container
COPY . /var/www/html

# I-set ang mga configuration para sa Laravel
ENV AUDIT_LEVEL=0
ENV HTML_HEADER="X-Frame-Options: SAMEORIGIN"
ENV WEBROOT=/var/www/html/public
ENV COMPOSER_ALLOW_SUPERUSER=1

# I-run ang composer install ug npm build
RUN composer install --no-dev --optimize-autoloader
RUN apk add --no-cache nodejs npm && npm install && npm run build

# I-expose ang port sa Nginx
EXPOSE 80
