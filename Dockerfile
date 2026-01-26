FROM php:8.3-apache

# Instalar dependencias del sistema
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

# Configurar PHP para uploads grandes
RUN echo "memory_limit = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "upload_max_filesize = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 0" >> /usr/local/etc/php/conf.d/uploads.ini

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar todos los archivos del proyecto
COPY . /var/www/html/

# Establecer permisos correctos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads /var/www/html/processed /var/www/html/jobs /var/www/html/assets \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/processed /var/www/html/jobs /var/www/html/assets \
    && chmod -R 777 /var/www/html/uploads /var/www/html/processed /var/www/html/jobs /var/www/html/assets

# Exponer puerto 80
EXPOSE 80

# Comando para iniciar Apache
CMD ["apache2-foreground"]
