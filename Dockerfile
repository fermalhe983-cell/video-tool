FROM php:8.3-apache

# Instalar dependencias del sistema y FFmpeg
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Configurar y compilar la extensión GD
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp \
    && docker-php-ext-install -j$(nproc) gd

# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# Configurar PHP para debugging y uploads grandes
RUN { \
    echo "memory_limit = 2048M"; \
    echo "upload_max_filesize = 2048M"; \
    echo "post_max_size = 2048M"; \
    echo "max_execution_time = 0"; \
    echo "max_input_time = -1"; \
    echo "display_errors = On"; \
    echo "error_reporting = E_ALL"; \
    echo "log_errors = On"; \
    echo "error_log = /var/www/html/php_errors.log"; \
    } > /usr/local/etc/php/conf.d/custom.ini

# Configurar Apache para mostrar errores
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar todos los archivos del proyecto
COPY . /var/www/html/

# Crear directorios y establecer permisos
RUN mkdir -p /var/www/html/uploads \
             /var/www/html/processed \
             /var/www/html/jobs \
             /var/www/html/assets \
    && touch /var/www/html/ffmpeg_log.txt \
    && touch /var/www/html/php_errors.log \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/uploads \
                    /var/www/html/processed \
                    /var/www/html/jobs \
                    /var/www/html/assets \
                    /var/www/html/ffmpeg_log.txt \
                    /var/www/html/php_errors.log

# Exponer puerto 80
EXPOSE 80

# Comando para iniciar Apache
CMD ["apache2-foreground"]
