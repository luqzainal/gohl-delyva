# Gunakan PHP 8.2 dengan Apache
FROM php:8.2-apache

# Install extension yang Laravel perlukan
RUN docker-php-ext-install pdo pdo_mysql mbstring tokenizer xml ctype

# Enable Apache mod_rewrite (Laravel perlu untuk routing)
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy composer dari official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy file composer dulu (supaya Docker layer cache)
COPY composer.json composer.lock ./

# Install dependencies tanpa dev
RUN composer install --no-dev --optimize-autoloader

# Copy semua source code Laravel
COPY . .

# Permission untuk storage & bootstrap/cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
