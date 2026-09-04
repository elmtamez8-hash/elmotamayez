#!/usr/bin/env bash
#
# دفعةٌ إلى `main` بلا CI، ثمّ سحبٌ على الخادمِ فوراً.
#
#     ./scripts/ship.sh
#
# ⚠️ الوسمُ `[skip ci]` يوقفُ `ci.yml` — و`deploy.yml` معه. النشرُ مربوطٌ بـ
# `workflow_run` على CI، فتخطّي الأولى يعني أنّ الثانيةَ **لا تُطلَقُ أبداً**؛
# ولذلك ينادي هذا السكربتُ `deploy.sh` بنفسِه. سكربتٌ يدفعُ ولا ينشرُ يتركُ
# `main` أمامَ الخادمِ بلا ما يقول.
#
# ⚠️ والثمنُ مكتوبٌ هنا لا في رأسِ أحد: البوّاباتُ الخمسُ لا تعملُ في هذه
# الطريق. أخطرُها بناءُ الواجهة — لا `tsc` ولا `npm test` يبني المسارات، وصفحتانِ
# تحملانِ العنوانَ نفسَه تردّانِ ٥٠٠ على **كلِّ صفحةٍ في المنتَج**، وقد شُحِنَ ذلك
# مرّةً وجلسَ جلسةً كاملة. فإن مسَّتِ الدفعةُ `frontend/` فشغّلْ `npm run build`
# قبلَها، أو ادفعْ بلا الوسمِ ودعِ CI يعمل.
set -euo pipefail

cd "$(dirname "$0")/.."

REMOTE_HOST="${DEPLOY_SSH_HOST:-elmotamayez}"
REMOTE_PATH="${DEPLOY_APP_PATH:-/srv/elmotamayez}"

# ⚠️ الحارسُ على **رأسِ** الدفعةِ لا على أيِّ التزام: GitHub يقرأُ رسالةَ الالتزامِ
# الأخيرِ وحدَها. بلا هذا الفحصِ تُدفَعُ الدفعةُ ويعملُ CI ويعملُ النشرُ التلقائيُّ
# بعدَه — فيصيرُ نشرانِ متزاحمانِ على خادمٍ واحدٍ بدلَ التوفير.
if ! git log -1 --pretty=%B | grep -qF '[skip ci]'; then
    echo "✗ رسالةُ الالتزامِ الأخيرِ لا تحملُ [skip ci] — أضفْها أو ادفعْ بـ git push ودعِ CI يعمل." >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "✗ الشجرةُ غيرُ نظيفة. الخادمُ يعملُ بـ git reset --hard origin/main، فما لم يُلتَزَمْ لا يصلُه." >&2
    exit 1
fi

echo "▸ الدفعُ إلى main"
git push origin main

echo "▸ النشرُ على $REMOTE_HOST"
ssh "$REMOTE_HOST" "cd '$REMOTE_PATH' && bash ./scripts/deploy.sh"
