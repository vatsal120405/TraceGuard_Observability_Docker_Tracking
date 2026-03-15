# Use PHP 8.4 FPM Alpine image for a smaller footprint
FROM php:8.4-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    nginx \
    nodejs \
    npm \
    supervisor

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Install Redis extension (optional but useful)
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . .

# Fix dubious ownership for Git (if running on certain file systems)
RUN git config --global --add safe.directory /var/www/html

# Set permissions for storage and bootstrap cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Install composer dependencies
RUN composer install --no-interaction --optimize-autoloader

# Install NPM dependencies and build frontend assets
RUN npm install \
    && npm run build

# Nginx config
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Supervisord config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Add setup script
COPY docker/run.sh /usr/local/bin/run.sh
RUN chmod +x /usr/local/bin/run.sh

# Expose port 80 for Nginx
EXPOSE 80

# Start Supervisor (which starts Nginx and PHP-FPM)
ENTRYPOINT ["/usr/local/bin/run.sh"]
