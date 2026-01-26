FROM php:8.3-apache

# Instalar dependencias del sistema y FFmpeg
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Configurar y compilar la extensión GD con soporte completo
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp \
    && docker-php-ext-install -j$(nproc) gd

# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# Configurar PHP para uploads grandes y ejecución prolongada
RUN echo "memory_limit = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "upload_max_filesize = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 0" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = -1" >> /usr/local/etc/php/conf.d/uploads.ini

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar todos los archivos del proyecto
COPY . /var/www/html/

# Crear directorios necesarios con permisos correctos
RUN mkdir -p /var/www/html/uploads \
             /var/www/html/processed \
             /var/www/html/jobs \
             /var/www/html/assets \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/uploads \
                    /var/www/html/processed \
                    /var/www/html/jobs \
                    /var/www/html/assets

# Exponer puerto 80
EXPOSE 80

# Comando para iniciar Apache en primer plano
CMD ["apache2-foreground"]
