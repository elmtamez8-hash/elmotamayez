#!/usr/bin/env bash
#
# يُعيدُ تشغيلَ كلِّ حاويةٍ من هذا المشروعِ فحصُها الصحّيُّ `unhealthy`. يُنادى من cron
# كلَّ خمسِ دقائق (يُنصِّبُه `deploy.sh`)، ولا يطبعُ شيئاً حينَ لا يجدُ شيئاً.
#
# ⚠️ `restart: unless-stopped` لا يكفي: يُعيدُ حاويةً ماتت، لا حاويةً حيّةً معتلّة.
# المجدوِلُ الذي توقّفت نبضتُه وHorizon الذي يقولُ `inactive` كلاهما «يعمل» في نظرِ دوكر.
#
# ⛔ **و`mysql` مستثنىً عمداً.** قاعدةٌ بطيئةٌ تحتَ حملٍ ثقيلٍ قد يفشلُ فحصُها، وإعادةُ
# تشغيلِها في تلكَ اللحظةِ تقطعُ كلَّ معاملةٍ وتبدأُ استعادةً بعدَ انهيار — علاجٌ أسوأُ من
# العرَض. قاعدةٌ معتلّةٌ تحتاجُ إنساناً، ويُخبِرُه بها مراقبُ التوفّرِ الخارجيّ.
#
# ⚠️ وكلُّ إعادةِ تشغيلٍ سطرٌ في `/var/log/elmotamayez-autoheal.log`: حاويةٌ تُعادُ كلَّ
# خمسِ دقائقَ علّةٌ لم تُصلَح، والسجلُّ هو ما يقولُ ذلك.
set -uo pipefail

docker ps \
    --filter label=com.docker.compose.project=elmotamayez \
    --filter health=unhealthy \
    --format '{{.ID}} {{.Label "com.docker.compose.service"}}' \
| while read -r id service; do
    [ "$service" = mysql ] && continue
    echo "$(date -Is) إعادةُ تشغيلِ $service ($id): الفحصُ الصحّيُّ unhealthy"
    docker restart -t 30 "$id" >/dev/null || echo "$(date -Is) ✗ تعذّرت إعادةُ تشغيلِ $service" >&2
done
