# Шаг 1: Выбор базового образа
FROM php:8.2-cli

# Установка переменных окружения для неинтерактивной установки
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME="/tmp" \
    INSTALL_DIR="/usr/local/bin" \
    DEBIAN_FRONTEND=noninteractive

# Шаг 1: Обновление списка пакетов и установка основных системных зависимостей
RUN apt-get update -y && \
    apt-get install -y --no-install-recommends \
    bash \
    git \
    curl \
    openssl \
    zip \
    unzip \
    bison \
    re2c \
    pkg-config \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Шаг 2: Установка dev-пакетов для PHP расширений
RUN apt-get update -y && \
    apt-get install -y --no-install-recommends \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    libxml2-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Шаг 3: Конфигурирование и установка PHP расширений
RUN docker-php-ext-configure intl && \
    docker-php-ext-install \
    bcmath \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo \
    xml \
    zip

# Установка Composer последней версии
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Установка рабочей директории в контейнере
WORKDIR /var/www/app

# Копируем composer.json и composer.lock для кэширования зависимостей Docker
COPY composer.json composer.lock ./

# Устанавливаем зависимости Composer БЕЗ выполнения скриптов на этом этапе
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Копируем все остальные файлы приложения в рабочую директорию
COPY . .

# Теперь, когда все файлы скопированы (включая artisan), можно выполнить скрипты Composer, если это необходимо
# Обычно package:discover запускается для генерации config/app.php и bootstrap/cache/packages.php
# Если мы кэшируем конфиг, то это может быть не обязательно, но лучше выполнить.
RUN composer dump-autoload --optimize 
RUN php artisan package:discover --ansi 

# Копируем .env.example в .env.
RUN cp .env.example .env

# Генерация ключа приложения Laravel
RUN php artisan key:generate --force

# Очистка кэша Laravel
RUN php artisan config:clear 
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Команда по умолчанию для запуска контейнера
CMD ["php", "artisan", "bot:run"]