FROM php:8.2-apache

# Instalar FFmpeg y dependencias
RUN apt-get update && apt-get install -y \
    ffmpeg \
    libzip-dev \
    && docker-php-ext-install zip

# Configurar límites para videos grandes
RUN echo "upload_max_filesize = 500M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini

# Permisos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html
