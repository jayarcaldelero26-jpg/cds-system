FROM php:8.2-apache

# I-install ang gikinahanglan nga extensions
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev zip unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql

# I-enable ang mod_rewrite
RUN a2enmod rewrite

# I-copy ang code
COPY . /var/www/html

# I-set ang DocumentRoot sa Apache
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Mao ni ang pinakaimportante:
# Gamiton nato ang direkta nga apache2 command imbes nga script
CMD ["apache2ctl", "-D", "FOREGROUND"]
