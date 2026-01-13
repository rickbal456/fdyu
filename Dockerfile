# AIKAFLOW Dockerfile
FROM php:8.3-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_mysql curl zip \
    && a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/temp /var/www/html/logs

# Apache configuration
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/aikaflow.ini

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/api/ping.php || exit 1

# Start Apache
CMD ["apache2-foreground"]