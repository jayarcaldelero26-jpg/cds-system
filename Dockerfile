FROM php:8.2-apache

# I-install ang mga gikinahanglan nga extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql

# I-enable ang mod_rewrite sa Apache
RUN a2enmod rewrite

# I-copy ang imong code
COPY . /var/www/html

# I-set ang DocumentRoot sa Apache ngadto sa /public
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# I-set ang permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# I-install ang composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

EXPOSE 80
