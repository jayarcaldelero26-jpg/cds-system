FROM php:8.3-apache

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd

# Keep PHP request limits above the 50 MB BMS CSV validation limit.
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application
COPY . .

# PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Frontend dependencies and build
RUN npm install
RUN npm run build

# Clear Laravel caches
# Do NOT run config:cache during Docker build.
# Render environment variables are provided at runtime.
RUN php artisan optimize:clear || true
RUN php artisan config:clear || true
RUN php artisan route:clear || true
RUN php artisan view:clear || true

# Apache
RUN a2enmod rewrite

RUN sed -ri \
    -e 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache public

EXPOSE 80

CMD ["apache2-foreground"]
