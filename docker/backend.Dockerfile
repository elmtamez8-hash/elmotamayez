FROM php:8.5.10-fpm-alpine

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

# ⛔ **الصورةُ كانت تعملُ بلا `php.ini` إطلاقاً** — قِيسَ على الإنتاجِ في ٢٠٢٦-٠٩-٢٥:
# `php_ini_loaded_file()` فارغ، فكلُّ قيمةٍ هي الافتراضيُّ المُصرَّفُ في PHP:
# `display_errors=1` (نصُّ الخطأِ ومسارُ الملفِّ في جسمِ الردّ)، ورفعٌ بسقفِ ٢ ميغا
# (فإيصالٌ بـ٣ ميغا يُرفَضُ قبلَ أن يصلَ قاعدةَ التحقّقِ التي تقبلُ ١٠)، و١٢٨ ميغا ذاكرة،
# وخمسةُ عمّالِ FPM لخادمٍ بـ١٦ غيغا — الطلبُ السادسُ المتزامنُ ينتظرُ في الطابور.
#
# `php.ini-production` أساساً، وفوقَه ملفّانِ صغيرانِ هما كلُّ ما يخصُّنا. ويُطبَّقانِ على
# `horizon` و`scheduler` و`reverb` أيضاً لأنّها الصورةُ نفسُها (CLI يقرأُ `conf.d` ذاتَه).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# ⚠️ الرفعُ: أكبرُ رفعٍ عبرَ نموذجٍ (multipart) قاعدتُه ١٠ ميغا (الإيصالُ، الواجبُ، ملفُّ
# الاستيراد)؛ ٢٠ ميغا تتركُ هامشاً، و`post_max_size` أكبرُ منه بقليلٍ لأنّ الطلبَ يحملُ
# حقولاً غيرَ الملفّ. ورفعُ الوسائطِ الكبيرُ على المزوّدِ المحلّيِّ `PUT` بجسمٍ خامٍ
# يُقرَأُ تدفّقاً (`getContent(true)`)، فلا يمرُّ بأيٍّ من السقفَين — وهو سببُ بقاءِ
# `client_max_body_size 512m` في nginx.
#
# ⚠️ `variables_order` يبقى `EGPCS` كما كانَ بلا ملفّ: `php.ini-production` يُسقِطُ `E`،
# ولا نغيّرُ من أينَ تُقرَأُ متغيّراتُ البيئةِ في التغييرِ نفسِه الذي يغيّرُ كلَّ شيءٍ آخر.
#
# ⚠️ `opcache.validate_timestamps=0`: الشيفرةُ داخلَ الصورةِ لا تتغيّرُ ما دامت الحاويةُ
# حيّة — كلُّ نشرةٍ تُنشئُ حاويةً جديدة. **لكنّ `artisan config:cache` يدويّاً داخلَ حاويةٍ
# عاملةٍ لا يراه FPM** حتى تُعادَ (`docker compose … restart backend`). وCLI لا يتأثّر:
# `opcache.enable_cli` مطفأٌ افتراضيّاً.
RUN printf '%s\n' \
        'upload_max_filesize = 20M' \
        'post_max_size = 21M' \
        'memory_limit = 512M' \
        'expose_php = Off' \
        'display_errors = Off' \
        'display_startup_errors = Off' \
        'log_errors = On' \
        'variables_order = "EGPCS"' \
        'opcache.enable = 1' \
        'opcache.memory_consumption = 256' \
        'opcache.interned_strings_buffer = 32' \
        'opcache.max_accelerated_files = 32531' \
        'opcache.validate_timestamps = 0' \
    > /usr/local/etc/php/conf.d/zz-app.ini

# ⚠️ خمسةُ عمّالٍ كانت سقفَ الموقعِ كلِّه. ٤٠ على ١٦ غيغا: عاملُ Laravel يستهلكُ عادةً
# ٥٠–٨٠ ميغا، فـ٤٠ نحوَ ٣ غيغا في الذروة، ويبقى الباقي لـMySQL وRedis وMeilisearch وNext.
# و`memory_limit` سقفٌ لطلبٍ واحدٍ شاذّ، لا ما يستهلكُه كلُّ عامل.
# `pm.max_requests` يُعيدُ العاملَ بعدَ ألفِ طلبٍ فلا يتراكمُ تسرّبٌ بطيء.
# والاسمُ `zz-` كي يُقرَأَ بعدَ `www.conf` (خمسةُ العمّالِ منه) فيغلبَه. و`printf` لا
# `COPY <<EOF`: ذاكَ يحتاجُ BuildKit حديثاً لا نعرفُ أنّ دوكر الخادمِ يحملُه.
RUN printf '%s\n' \
        '[www]' \
        'pm = dynamic' \
        'pm.max_children = 40' \
        'pm.start_servers = 8' \
        'pm.min_spare_servers = 4' \
        'pm.max_spare_servers = 12' \
        'pm.max_requests = 1000' \
    > /usr/local/etc/php-fpm.d/zz-app.conf

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# ⚠️ المحاولةُ الأخيرةُ ليست إعادةَ محاولةٍ — بل **مصدرٌ آخر**، وهذا ما يُهمّ.
#
# `preferred-install: "dist"` في `composer.json` يُطفئُ الرجوعَ إلى المصدر،
# فيقولُ البناءُ «Source fallback is disabled» ثمَّ يسقُطُ على أوَّلِ أرشيفٍ
# يرفُضُهُ GitHub. وقد حدثَ ثلاثَ مرّاتٍ في ساعةٍ (٢٠٢٦-٠٨-٣١): أربعُ
# حزمٍ من ١٧٣ تردُّ `HTTP 400` على `codeload.…/legacy.zip/<sha>` — وكلُّها
# أحدثُ إصدارٍ من حزمتِها، وسابقُ كلٍّ منها يُنزَّلُ بنجاح. والتزامةُ
# نفسُها موجودةٌ وقابلةٌ للاستنساخ — مقيسٌ على
# `spatie/image-optimizer:1.10.0`: الأرشيفُ يردُّ ٤٠٠ و`git clone` ينجح.
#
# ولا يُستعمَلُ `--prefer-source` وحدَه: استنساخُ مئتي مستودَعٍ في كلِّ
# بناءٍ ثمنٌ يُدفَعُ دائماً مقابلَ عطلٍ نادِر.
#
# ⛔ **والحلقةُ كانت تخلطُ عطلَينِ علاجُهما متضادّ، وهذا التعليقُ
# نفسُه كانَ يفرّقُ بينَهما بينما الكودُ لا يرى الفرق.** الـ٤٠٠ **دائمٌ**
# في ذلكَ الأرشيفِ بعينِه، فالمصدرُ مخرجُه الوحيدُ والانتظارُ قبلَه ضائع؛
# والـ٤٢٩ تحديدُ معدَّلٍ، والاستنساخُ من **المضيفِ المحدودِ نفسِه** أسوأُ
# ردٍّ ممكن. فالحلقةُ تقرأُ نوعَ الفشلِ الآنَ لا عددَه: ٤٢٩ ← انتظارٌ
# وإعادةٌ على مسارِ الحُزَمِ الجاهزة، وأيُّ فشلٍ آخرَ ← المصدرُ فوراً بلا نوم.
#
# ٢٠٢٦-٠٩-١٥ ثمَّ ٢٠٢٦-٠٩-١٦: التوقيعُ نفسُه مرّتَين — سقطَتِ المحاولةُ
# عندَ **١٧٣ من ١٧٤** على `mpdf/mpdf` بـ**HTTP/2 429**، فذهبَ البناءُ إلى
# المصدرِ واستنسخَ الـ١٧٤ كلَّها: **٦١٤ ثانيةً** بدلاً من ستّين، ونشرةٌ في
# ستَّ عشرةَ دقيقةً بدلَ دقيقتَين. والمحاولاتُ الثلاثُ لم تُغنِ في
# المرّتَين: الـ٤٢٩ لا يزولُ بـ٩٠ ثانيةً لأنَّ GitHub يحسِبُ بـ**نافذةٍ
# ساعيّةٍ ثابتة** لا بفترةِ تهدئة.
#
# ⚠️ **والسببُ الجذريُّ أنّنا نطلُبُ غيرَ موثَّقين: ٦٠ طلباً في الساعةِ
# للعنوانِ الواحد.** ثماني نشراتٍ في يومٍ واحدٍ تستنفِدُها، فنحنُ من يحدُّ
# نفسَه. و`COMPOSER_AUTH` يرفعُها إلى ٥٠٠٠ — فهو وحدَه ما يزيلُ العشرَ دقائق،
# وفرعُ الحلقةِ أعلاه يوفّرُ تسعينَ ثانيةً على الـ٤٠٠ ولا يُغيّرُ شيئاً على الـ٤٢٩.
#
# ⚠️ **وليسَ الرافعةُ `COMPOSER_MAX_PARALLEL_HTTP` كما قالَ هذا التعليقُ
# قبلَ أن يُقاس.** الحدُّ عددُ طلباتٍ في ساعة، والتوازي لا يُنقِصُ عدداً —
# يُبطِءُ كلَّ بناءٍ مقابلَ لا شيء. كانت فرضيّةً مكتوبةً كأنّها قياس.
#
# ⚠️ **و`ARG` وحدَه بلا `ENV`:** وسيطُ البناءِ موجودٌ في بيئةِ `RUN` أصلاً؛
# وإضافةُ `ENV` تضعُ الرمزَ في الحاويةِ العاملةِ حيثُ يقرؤهُ PHP.
# وسقفُ هذا الاختيارِ مكتوبٌ: الرمزُ يبقى في `docker history` للصورة، وهي
# محلّيّةٌ على الخادمِ ولا تُدفَعُ إلى أيِّ سجلّ؛ فإن دُفِعَت يوماً،
# البديلُ `RUN --mount=type=secret`. والفارغُ هو الافتراضُ، فبناءٌ بلا رمزٍ
# يعملُ كما كانَ بالضبط.
ARG COMPOSER_AUTH=""
RUN for attempt in 1 2 3; do \
        composer install --no-interaction --optimize-autoloader --no-dev >/tmp/composer.log 2>&1 \
            && { cat /tmp/composer.log; exit 0; }; \
        cat /tmp/composer.log; \
        if grep -q "429" /tmp/composer.log; then \
            echo ">>> rate-limited by the archive host (attempt ${attempt}/3) - waiting, dist path again"; \
            sleep $((attempt * 15)); \
        else \
            echo ">>> composer install failed with a non-429 error - the source path is the only way out"; \
            break; \
        fi; \
    done; \
    composer install --no-interaction --optimize-autoloader --no-dev --prefer-source

# public/storage → storage/app/public, so nginx can serve uploaded receipts.
RUN php artisan storage:link --force

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
