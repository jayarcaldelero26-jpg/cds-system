FROM php:8.3-apache

# 1. I-install ang system dependencies ug Node.js 20
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

# Clear apt cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. I-install ang kinahanglang PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 3. Kuhaa ang Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Ibutang ang working directory
WORKDIR /var/www/html

# 5. Kopyaha una ang package files aron ma-optimize ang caching
COPY composer.json composer.lock package.json package-lock.json ./

# 6. I-install ang PHP ug Node dependencies
RUN composer install --no-dev --optimize-autoloader
RUN npm install

# 7. Kopyaha ang nahabiling source code
COPY . /var/www/html

# 8. I-run ang Vite build direkta sulod sa container aron matingob ang manifest.json
RUN npm run build

# 9. I-set ang saktong permissions sa storage, bootstrap cache, ug public folder
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public

# 10. Gamita ang www-data user para sa security
USER www-data

# 11. I-expose ang port ug i-clear ang cache inig sugod sa server
EXPOSE 80
CMD php artisan config:clear && php artisan cache:clear && php artisan serve --host=0.0.0.0 --port=$PORT
