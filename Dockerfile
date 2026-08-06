FROM php:8.3-apache

# 1. Install system dependencies & Node.js 20
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

# 2. Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 3. Get Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4. Set working directory
WORKDIR /var/www/html

# 5. Copy dependency files first for better caching
COPY composer.json composer.lock package.json package-lock.json ./

# 6. Install PHP and Node dependencies
RUN composer install --no-dev --optimize-autoloader
RUN npm install

# 7. Copy the rest of the project files
COPY . /var/www/html

# 8. Run Vite build as root so it successfully creates public/build/manifest.json
RUN npm run build

# 9. Set proper permissions for storage, bootstrap cache, and public
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public

# 10. Switch to www-data user for security after build is complete
USER www-data

# 11. Expose port and start server
EXPOSE 80
CMD php artisan config:clear && php artisan cache:clear && php artisan serve --host=0.0.0.0 --port=$PORT
