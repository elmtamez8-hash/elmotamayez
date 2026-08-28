#!/usr/bin/env bash
#
# نشرةٌ واحدةٌ على الخادم. يُنادى من `.github/workflows/deploy.yml` عبرَ SSH،
# ويُشغَّلُ باليدِ بالأمرِ نفسِه عندَ الحاجة:
#
#     cd /srv/elmotamayez && ./scripts/deploy.sh
#
# ⚠️ سكربتٌ في المستودعِ لا سلسلةُ أوامرَ في ملفِّ الـworkflow، والسببُ ليس
# ترتيباً: أوامرُ النشرِ المكتوبةُ داخلَ YAML لا يمكنُ تشغيلُها يدويّاً عندَ عطلٍ
# إلّا بنسخِها سطراً سطراً من صفحةِ الويب — وفي تلك اللحظةِ بالذاتِ يُنسى سطر.

set -euo pipefail

COMPOSE="docker compose -f docker/docker-compose.prod.yml --env-file docker/.env"

echo "▸ سحبُ آخرِ ما على main"
git fetch --prune origin
# ⚠️ `reset --hard` لا `pull`. الخادمُ ليس مكانَ تحريرٍ، وتعديلٌ يدويٌّ عليه
# يجعلُ `pull` يفشلُ بتعارضِ دمجٍ في منتصفِ نشرةٍ — أو، أسوأُ، ينجحُ ويُبقي
# التعديلَ حيّاً إلى الأبدِ حيثُ لا يراه أحدٌ في مراجعة.
git reset --hard origin/main

echo "▸ بناءُ الصور"
$COMPOSE build

echo "▸ رفعُ الخدمات"
$COMPOSE up -d

echo "▸ انتظارُ قاعدةِ البيانات"
# الفحصُ الصحّيُّ في ملفِّ الـcompose يحرسُ الإقلاع؛ هذا يحرسُ الهجرةَ بعدَ
# إعادةِ بناءٍ قد تكون أعادت تشغيلَ MySQL للتوّ.
until $COMPOSE exec -T mysql mysqladmin ping --silent 2>/dev/null; do sleep 2; done

echo "▸ الهجرات"
# ⚠️ `--force` لأنّ الأمرَ يسألُ تأكيداً في بيئةِ الإنتاجِ ولا أحدَ هنا ليُجيب،
# فيتعلّقُ إلى أن تنتهيَ مهلةُ الوظيفة. و**لا `migrate:fresh` أبداً**: تلك تُسقِطُ
# كلَّ جدولٍ — كلَّ طالبٍ وكلَّ دفعةٍ وكلَّ تسجيل.
$COMPOSE exec -T -u www-data backend php artisan migrate --force

echo "▸ إعادةُ بناءِ ذاكرةِ الإعدادات"
# ⚠️ بهذا الترتيب: `config:clear` قبلَ `config:cache`. ملفُّ ذاكرةٍ قديمٌ يحملُ
# قيمَ `.env` السابقةَ ويتجاهلُ الجديدةَ بصمت — فتُقرأُ نشرةٌ صحيحةٌ على أنّها
# إعدادٌ خاطئ.
# ⚠️ `-u www-data`، وهو ما كان ناقصاً. `docker compose exec` يعملُ بصلاحيّةِ
# **root**، و`view:cache` يكتبُ كلَّ قالبٍ مُصرَّفٍ مملوكاً لـroot — بينما حوضُ
# PHP-FPM يعملُ بـ`www-data`. وBlade يستدعي `touch()` ليُطابِقَ زمنَ الملفِّ
# المُصرَّفِ بالمصدر، و`utime()` لا يُسمَحُ بها إلّا لمالكِ الملفّ:
#
#     touch(): Utime failed: Operation not permitted   (BladeCompiler.php:215)
#
# فتردُّ **٥٠٠ كلُّ صفحةِ Blade** — `/admin/login` أوّلُها. ولا يظهرُ في فحصٍ
# دخانيّ: `/` تخدمُها Next، و`/admin` تُحوِّلُ ٣٠٢ قبلَ تصييرِ أيِّ قالب.
$COMPOSE exec -T -u www-data backend php artisan config:clear
$COMPOSE exec -T -u www-data backend php artisan config:cache
$COMPOSE exec -T -u www-data backend php artisan route:cache
$COMPOSE exec -T -u www-data backend php artisan view:cache

# وشبكةُ أمانٍ فوقَها: أيُّ `artisan` يُشغَّلُ يدويّاً بعدَ نشرةٍ (بذرٌ، `tinker`،
# تشخيص) يعملُ بـroot ويُعيدُ التلويثَ نفسَه. سطرٌ واحدٌ يُنظِّفُ الصنفَ كلَّه.
$COMPOSE exec -T backend chown -R www-data:www-data storage bootstrap/cache

# ⚠️ ولا بذرةَ هنا، ولا حتى «للاحتياط». الفهارسُ التي تُقرَأُ وقتَ التشغيلِ
# (قوالبُ الإشعاراتِ · فئاتُ البيانات · فهرسُ التلعيب) تُردَمُ بهجرةٍ تُشحَنُ مع
# الصفِّ الذي يحتاجُها — وهي قاعدةٌ مكتوبةٌ في `CLAUDE.md` لأنّها كُسِرَت ثلاثَ
# مرّات. وبذرةٌ في مسارِ النشرِ تكتبُ `updateOrCreate`، أي أنّها **تدهسُ كلَّ نصٍّ
# عربيٍّ وكلَّ مدّةٍ عدّلها مشغِّلٌ من `/admin`، في كلِّ نشرة**. البذورُ الأولى
# (الأدوارُ والصلاحيّاتُ وإعداداتُ المنصّة) تُشغَّلُ مرّةً واحدةً عندَ أوّلِ إقلاع،
# وهي موصوفةٌ في `docs/deploy-hostinger.md`.

echo "▸ إعادةُ تشغيلِ العامل"
# ⚠️ `queue:restart` لا إعادةَ تشغيلِ الحاوية. العاملُ يُمسِكُ الشيفرةَ التي أقلعَ
# بها، فيظلُّ يفشلُ بخطأٍ أزالَه الـcommit — ساعتان ضاعتا مرّةً على أثرِ استدعاءٍ
# لم يعدْ موجوداً في أيِّ ملفّ.
$COMPOSE exec -T -u www-data backend php artisan queue:restart
$COMPOSE restart horizon scheduler reverb

echo "▸ تنظيفُ الصورِ القديمة"
docker image prune -f >/dev/null

echo "▸ فحصٌ دخانيّ"
# ⚠️ **صفحةُ Blade واحدةٌ على الأقلّ، وهذا هو بيتُ القصيد.** كانت قائمةُ الفحصِ
# كلُّها خضراءَ بينما `/admin/login` ترُدُّ ٥٠٠: `/` تخدمُها Next، و`/admin`
# تُحوِّلُ ٣٠٢ **قبلَ** تصييرِ أيِّ قالب، و`/api` تُعيدُ JSON بلا Blade. فلا نقطةَ
# واحدةً في القائمةِ كانت تُصرِّفُ قالباً — والشاشةُ الوحيدةُ التي يفتحُها إنسانٌ
# ليدخلَ هي بالضبط الشاشةُ التي لم يفحصْها شيء.
DOMAIN_NAME=$(sed -n 's/^DOMAIN=//p' docker/.env | head -1)
FAILED=0
for path in "/" "/api/v1/marketplace/home" "/admin/login"; do
    code=$(curl -s -o /dev/null -w "%{http_code}" -m 30 "https://${DOMAIN_NAME}${path}" || echo 000)
    case "$code" in
        2*|3*) printf '  ✓ %-28s %s
' "$path" "$code" ;;
        *)     printf '  ✗ %-28s %s
' "$path" "$code"; FAILED=1 ;;
    esac
done
[ "$FAILED" -eq 0 ] || { echo "✗ النشرةُ تمّت والموقعُ لا يردّ — راجعْ فوراً." >&2; exit 1; }

echo "✓ تمّت النشرة: $(git rev-parse --short HEAD)"
