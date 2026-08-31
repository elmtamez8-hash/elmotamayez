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

# ⚠️ المحاولةُ الثانيةُ ليست إعادةَ محاولةٍ — بل **مصدرٌ آخر**، وهذا ما يُهمَّ.
#
# `preferred-install: "dist"` في `composer.json` يُطفئُ الرجوعَ إلى المصدر،
# فيقولُ البناءُ «Source fallback is disabled» ثمَّ يسقُطُ على أوَّلِ أرشيفٍ
# يرفُضُهُ GitHub. وقد حدثَ ثلاثَ مرّاتٍ في ساعةٍ (٢٠٢٦-٠٨-٣١): أربعُ
# حزمٍ من ١٧٣ تردُّ `HTTP 400` على `codeload.…/legacy.zip/<sha>` — وكلُّها
# أحدثُ إصدارٍ من حزمتِها، وسابقُ كلٍّ منها يُنزَّلُ بنجاح.
#
# ⚠️ والتزامةُ نفسُها موجودةٌ وقابلةٌ للاستنساخ — مقيسٌ على
# `spatie/image-optimizer:1.10.0`، إحدى المكسوراتِ بعينِها: الأرشيفُ يردُّ
# ٤٠٠ و`git clone` ينجح. فالعطلُ في توليدِ الأرشيفِ وحدَه، و`git` مثبَّتٌ
# أعلاه منذ أوَّلِ سطرٍ في هذا الملفّ.
#
# ولا يُستعمَلُ `--prefer-source` وحدَه: استنساخُ مئتي مستودَعٍ في كلِّ
# بناءٍ ثمنٌ يُدفَعُ دائماً مقابلَ عطلٍ نادِر. المسارُ الثاني لا يعملُ
# إلّا حينَ يسقُطُ الأوَّل، ولا يمسُّ `composer.lock` بحرفٍ — بخلافِ خفضِ
# ثلاثِ حزمٍ إحداها تبعيّةٌ للارافل، وهو تشويشٌ يُتراجَعُ عنه بعدَ أيّام.
RUN composer install --no-interaction --optimize-autoloader --no-dev \
    || composer install --no-interaction --optimize-autoloader --no-dev --prefer-source

# public/storage → storage/app/public, so nginx can serve uploaded receipts.
RUN php artisan storage:link --force

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
