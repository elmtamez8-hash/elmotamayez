#!/usr/bin/env bash
#
# إدخالُ أسرارِ المزوّدين (LiveKit · R2 · Bunny) في `docker/.env` على الخادم.
#
#   ssh root@<server> 'cd /srv/elmotamayez && bash scripts/set-secrets.sh'
#
# ⚠️ يُشغَّلُ من طرفيّةِ صاحبِ الخادمِ مباشرةً. القراءةُ بـ`read -rs` فلا يُطبَعُ
# شيءٌ على الشاشةِ ولا يدخلُ الصدفةَ في `~/.bash_history` ولا يظهرُ في `ps`.
# وتمريرُ سرٍّ في سطرِ أمرٍ يجعلُه مرئيّاً لكلِّ عمليّةٍ على الجهاز.
#
# Enter (فارغ) = أبقِ القيمةَ الحاليّةَ كما هي.
set -euo pipefail

cd "$(dirname "$0")/.."
ENV_FILE=docker/.env
COMPOSE="docker compose -f docker/docker-compose.prod.yml --env-file docker/.env"

[ -f "$ENV_FILE" ] || { echo "لا يوجد $ENV_FILE"; exit 1; }

# ⚠️ `umask` قبلَ النسخة: هذه النسخةُ ستحملُ أسرارَ المزوّدين بعدَ أوّلِ تشغيل،
# وملفٌّ احتياطيٌّ مقروءٌ للجميعِ هو المفاتيحُ نفسُها بلا حماية.
umask 077
cp "$ENV_FILE" "$ENV_FILE.bak.$(date +%s)"
chmod 600 "$ENV_FILE.bak."* 2>/dev/null || true

# يكتبُ مفتاحاً أو يُضيفُه؛ awk موجودٌ على كلِّ صورة.
upsert() {
  local key="$1" val="$2" tmp
  tmp=$(mktemp)
  if grep -q "^${key}=" "$ENV_FILE"; then
    awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="}
      $1==k {print k "=" v; next} {print}' "$ENV_FILE" > "$tmp"
  else
    cat "$ENV_FILE" > "$tmp"; printf '%s=%s\n' "$key" "$val" >> "$tmp"
  fi
  cat "$tmp" > "$ENV_FILE"; rm -f "$tmp"
}

current() { sed -n "s/^$1=//p" "$ENV_FILE" | head -1; }

ask() {                       # ask KEY "الوصف" [plain]
  local key="$1" label="$2" mode="${3:-secret}" cur val
  cur=$(current "$key")
  local shown="(فارغ)"
  [ -n "$cur" ] && { [ "$mode" = plain ] && shown="$cur" || shown="********"; }
  printf '%s [%s]: ' "$label" "$shown" >&2
  if [ "$mode" = plain ]; then read -r val; else read -rs val; echo >&2; fi

  # ⚠️ `if` لا `[ -n "$val" ] && upsert …`. تحتَ `set -e` تلك القائمةُ تخرجُ
  # بـ١ عندَ أوّلِ Enter (أي «أبقِ القيمةَ») فتموتُ الصدفةُ في منتصفِ الملفّ:
  # بعضُ المفاتيحِ مكتوبٌ، والمزوّدُ لم يُبدَّلْ، والخدماتُ لم تُعَدْ تشغيلاً.
  # وهي عائلةُ العطلِ نفسِها التي أضاعت كلمةَ مرورِ المديرِ في هذه الجلسة.
  if [ -n "$val" ]; then upsert "$key" "$val"; fi
}

echo "══ البثُّ المباشر (LiveKit) ══"
ask LIVEKIT_URL        "LIVEKIT_URL (wss://...)" plain
ask LIVEKIT_API_KEY    "LIVEKIT_API_KEY"
ask LIVEKIT_API_SECRET "LIVEKIT_API_SECRET"

echo
echo "══ تخزينُ التسجيلات (Cloudflare R2) ══"
ask LIVEKIT_EGRESS_BUCKET   "R2 bucket" plain
ask LIVEKIT_EGRESS_ENDPOINT "R2 endpoint (https://<acct>.r2.cloudflarestorage.com)" plain
ask LIVEKIT_EGRESS_REGION   "R2 region" plain
ask LIVEKIT_EGRESS_KEY      "R2 access key id"
ask LIVEKIT_EGRESS_SECRET   "R2 secret access key"

echo
echo "══ الفيديو (Bunny Stream) ══"
ask BUNNY_STREAM_LIBRARY_ID      "Library ID" plain
ask BUNNY_STREAM_ACCESS_KEY      "Stream AccessKey"
ask BUNNY_PULL_ZONE              "Pull zone hostname (xxx.b-cdn.net)" plain
ask BUNNY_PULL_ZONE_SECURITY_KEY "Pull zone token security key"

# ⚠️ التحقّقُ قبلَ التبديل. `LIVEKIT_URL` بصيغةِ `wss://` لأنّ المتصفّحَ يطلبُه،
# والمُهايئُ يشتقُّ صيغةَ HTTP بنفسِه — و`https://` هنا كسرَ كلَّ غرفةٍ مرّةً.
LK_URL=$(current LIVEKIT_URL)
if [ -n "$LK_URL" ] && [ "${LK_URL#wss://}" = "$LK_URL" ]; then
  echo "✗ LIVEKIT_URL يجب أن يبدأ بـ wss:// — لم يُبدَّل المزوّد." >&2
  exit 1
fi

# ⚠️ لا يُبدَّلُ المزوّدُ إلّا إذا اكتملت مفاتيحُه. مزوّدٌ مُعلَنٌ بمفتاحٍ فارغٍ
# أسوأُ من مزوّدٍ مُعطَّل: الرفعُ يُقبَلُ ثمّ يفشلُ عندَ الطرفِ الآخَر.
if [ -n "$LK_URL" ] && [ -n "$(current LIVEKIT_API_KEY)" ] && [ -n "$(current LIVEKIT_API_SECRET)" ]; then
  upsert BROADCAST_PROVIDER livekit; echo "✓ البثُّ المباشر: livekit"
else
  upsert BROADCAST_PROVIDER null;    echo "· البثُّ المباشر: null (مفاتيحُ ناقصة)"
fi

if [ -n "$(current BUNNY_STREAM_LIBRARY_ID)" ] && [ -n "$(current BUNNY_STREAM_ACCESS_KEY)" ] \
   && [ -n "$(current BUNNY_PULL_ZONE)" ] && [ -n "$(current BUNNY_PULL_ZONE_SECURITY_KEY)" ]; then
  upsert MEDIA_PROVIDER bunny; echo "✓ الفيديو: bunny"
else
  upsert MEDIA_PROVIDER local; echo "· الفيديو: local (مفاتيحُ ناقصة)"
fi

# ومن أين يُقرأُ ملفُ التسجيل: `r2` إن اكتمل، وإلّا القرصُ المحلّيّ — القيمةُ
# الافتراضيّةُ في `config/media.php` هي `r2`، فتركُها بلا مفاتيحَ فشلٌ صامت.
if [ -n "$(current LIVEKIT_EGRESS_BUCKET)" ] && [ -n "$(current LIVEKIT_EGRESS_KEY)" ]; then
  upsert MEDIA_SOURCE_DISK r2;    echo "✓ مصدرُ التسجيلات: r2"
else
  upsert MEDIA_SOURCE_DISK local; echo "· مصدرُ التسجيلات: local (لا egress)"
fi

# ⚠️ العاملُ يحملُ ما أقلعَ به. بلا إعادةِ تشغيلٍ تبقى المفاتيحُ القديمةُ حيّةً
# في horizon وscheduler، وتُقرَأُ أعطالُها على أنّها خطأٌ في الشيفرة.
echo
echo "إعادةُ تشغيلِ الخدماتِ التي تقرأُ هذه المفاتيح…"
$COMPOSE up -d --force-recreate backend horizon scheduler reverb >/dev/null
$COMPOSE exec -T backend php artisan config:clear >/dev/null 2>&1 || true
echo "✓ تمّ."
