FROM php:8.5-fpm-alpine

# ⚠️ `intl` و`exif` مطلوبان ولا يذكرُهما `composer.json`: يأتيان من تبعيّاتٍ
# غيرِ مباشرة، فيسقطُ `composer install` داخلَ الصورةِ برسالةٍ تُسمّي الامتدادَ
# ولا تُسمّي من يطلبُه. لا يظهرُ محلّيّاً إطلاقاً — PHP على جهازِ التطويرِ يأتي
# بهما مثبَّتَين، فالبناءُ الأوّلُ للصورةِ هو أوّلُ مكانٍ يُسألُ فيه السؤال.
# و`icu-dev` هو ما يُصرِّفُ `intl`، ويبقى مثبَّتاً لأنّ الصورةَ مرحلةٌ واحدة.
RUN apk add --no-cache \
    git \
    curl \
    icu-dev \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    librdkafka-dev \
    postgresql-dev \
    && docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    gd \
    zip \
    intl \
    exif \
    mbstring \
    xml \
    bcmath \
    pcntl

# ⚠️ `phpredis` امتدادٌ من PECL لا يُصرِّفُه `docker-php-ext-install`، وغيابُه
# **لا يظهرُ إلّا وقتَ التشغيل**: `config/database.php` يفترضُ `phpredis` عميلاً
# افتراضيّاً، فتُقلِعُ الحاوياتُ كلُّها بنجاحٍ ثمّ تسقطُ أوّلُ هجرةٍ تمسُّ الذاكرةَ
# بـ`Class "Redis" not found` — رسالةٌ تُسمّي صنفاً غيرَ موجودٍ ولا تُسمّي امتداداً
# ناقصاً. والذاكرةُ والطوابيرُ والجلساتُ الثلاثُ على redis في الإنتاج، فبلا هذا
# السطرِ لا شيءَ يعملُ أصلاً.
#
# `$PHPIZE_DEPS` أدواتُ بناءٍ مؤقّتةٌ تُحذَفُ بعدَ التصريفِ في الأمرِ نفسِه: تركُها
# يضيفُ نحوَ ١٠٠ ميغابايت إلى كلِّ نشرةٍ مقابلَ لا شيء.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --optimize-autoloader --no-dev

# public/storage → storage/app/public, so nginx can serve uploaded receipts.
RUN php artisan storage:link --force

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
