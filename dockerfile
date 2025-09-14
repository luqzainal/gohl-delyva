# Base PHP image
FROM php:8.2-apache

# Enable extensions Laravel perlu
RUN docker-php-ext-install pdo pdo_mysql

# Copy semua file Laravel
COPY . /var/www/html

# Set working dir
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --optimize-autoloader --no-dev

# Expose port
EXPOSE 80

CMD ["apache2-foreground"]
