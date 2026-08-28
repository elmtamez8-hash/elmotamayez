#!/usr/bin/env bash
#
# نشرةٌ واحدةٌ على الخادم. يُنادى من `.github/workflows/deploy.yml` عبرَ SSH،
# ويُشغَّلُ باليدِ بالأمرِ نفسِه عندَ الحاجة:
#
#     cd /srv/mteatch && ./scripts/deploy.sh
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
$COMPOSE exec -T backend php artisan migrate --force

echo "▸ إعادةُ بناءِ ذاكرةِ الإعدادات"
# ⚠️ بهذا الترتيب: `config:clear` قبلَ `config:cache`. ملفُّ ذاكرةٍ قديمٌ يحملُ
# قيمَ `.env` السابقةَ ويتجاهلُ الجديدةَ بصمت — فتُقرأُ نشرةٌ صحيحةٌ على أنّها
# إعدادٌ خاطئ.
$COMPOSE exec -T backend php artisan config:clear
$COMPOSE exec -T backend php artisan config:cache
$COMPOSE exec -T backend php artisan route:cache
$COMPOSE exec -T backend php artisan view:cache

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
$COMPOSE exec -T backend php artisan queue:restart
$COMPOSE restart horizon scheduler reverb

echo "▸ تنظيفُ الصورِ القديمة"
docker image prune -f >/dev/null

echo "✓ تمّت النشرة: $(git rev-parse --short HEAD)"
