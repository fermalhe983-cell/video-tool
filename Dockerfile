FROM php:8.2-apache

# 1. Instalar FFmpeg
RUN apt-get update && apt-get install -y \
    ffmpeg \
    libzip-dev \
    && docker-php-ext-install zip

# 2. Configurar límites
RUN echo "upload_max_filesize = 500M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini

# 3. COPIAR ARCHIVOS (¡Esta es la línea que faltaba!)
COPY . /var/www/html/

# 4. Permisos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html
