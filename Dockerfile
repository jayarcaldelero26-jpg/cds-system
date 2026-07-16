FROM php:8.2-cli

# I-install ang mga gikinahanglan nga extensions
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev zip unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql

# I-copy ang code
COPY . /var/www/html
WORKDIR /var/www/html

# Permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Gamiton nato ang PHP built-in server (Kini na ang modagan)
CMD php artisan serve --host=0.0.0.0 --port=$PORT
