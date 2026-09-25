#!/usr/bin/env bash
#
# التراجعُ إلى صورةٍ سابقةٍ من الخلفيّةِ والواجهة، بلا بناءٍ وبلا سحب:
#
#     cd /srv/elmotamayez && bash scripts/rollback.sh            # إلى :previous
#     cd /srv/elmotamayez && bash scripts/rollback.sh 3f9c2a1b7d4e  # إلى sha بعينِه
#
# `deploy.sh` يَسِمُ الصورةَ العاملةَ `:previous` قبلَ كلِّ بناء، وكلَّ بناءٍ بـ`:<sha>`
# (آخرُ ثلاثة). `docker image ls elmotamayez-backend` يعرضُ ما هو متاح.
#
# ⚠️ **هذا يشتري وقتاً ولا يُصلِحُ شيئاً.** الشيفرةُ على القرصِ والـcompose وإعدادُ nginx
# تبقى من النشرةِ الأحدث، والنشرةُ التاليةُ تبني من main من جديد — فالإصلاحُ الحقيقيُّ
# commit يعكسُ الخطأَ على main. والهجراتُ لا تُعكَس: قاعدةُ المستودعِ أنّها إضافيّة، فالشيفرةُ
# الأقدمُ تعملُ على المخطّطِ الأحدث.
#
# ⚠️ وليسَ بلا انقطاع: `up -d` يُطفئُ ثمّ يُنشئ، ثوانٍ من ٥٠٢. في لحظةِ تراجعٍ هذا مقبول.
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker/docker-compose.prod.yml --env-file docker/.env"
TAG=${1:-previous}

for repo in elmotamayez-backend elmotamayez-frontend; do
    if ! docker image inspect "$repo:$TAG" >/dev/null 2>&1; then
        echo "✗ لا توجدُ $repo:$TAG — لم يُبدَّلْ شيء. المتاح:" >&2
        docker image ls "$repo" >&2
        exit 1
    fi
done

echo "▸ :latest ← :$TAG"
docker tag "elmotamayez-backend:$TAG" elmotamayez-backend:latest
docker tag "elmotamayez-frontend:$TAG" elmotamayez-frontend:latest

echo "▸ إعادةُ إنشاءِ الخدماتِ من الصورةِ المُستعادة"
$COMPOSE up -d --no-deps --no-build backend frontend horizon scheduler reverb

echo "▸ ذاكرةُ الإعدادات (بـwww-data — انظر warm_backend في deploy.sh)"
$COMPOSE exec -T -u www-data backend php artisan config:clear
$COMPOSE exec -T -u www-data backend php artisan config:cache
$COMPOSE exec -T -u www-data backend php artisan route:cache
$COMPOSE exec -T -u www-data backend php artisan view:cache
$COMPOSE exec -T -u www-data backend php artisan queue:restart

echo "✓ تمَّ التراجعُ إلى :$TAG — والآن commit يعكسُ الخطأَ على main قبلَ النشرةِ التالية."
