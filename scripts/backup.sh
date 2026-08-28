#!/usr/bin/env bash
#
# نسخةٌ احتياطيّةٌ يوميّة: قاعدةُ البيانات + المرفوعات.
#
#   bash scripts/backup.sh          # يدويّاً
#   (وفي crontab يوميّاً — انظر أسفلَ الملفّ)
#
# ⚠️ يقرأُ `docker/.env` **عندَ كلِّ تشغيل**، لا نسخةً محفوظةً وقتَ الإعداد.
# كلمةُ مرورِ القاعدةِ تُدوَّرُ يوماً ما، وكلمةٌ منسوخةٌ هنا تجعلُ كلَّ نسخةٍ
# بعدَها تفشلُ — بصمتٍ، لأنّ أحداً لا يقرأُ نجاحَ نسخةٍ احتياطيّة.
set -euo pipefail

cd "$(dirname "$0")/.."
OUT=${BACKUP_DIR:-/srv/backups}
KEEP_DAYS=${BACKUP_KEEP_DAYS:-14}
STAMP=$(date +%Y-%m-%d_%H%M)

set -a; . docker/.env; set +a
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD مفقود في docker/.env}"
: "${DB_DATABASE:?DB_DATABASE مفقود في docker/.env}"

mkdir -p "$OUT"

DB_FILE="$OUT/db_${STAMP}.sql.gz"
ST_FILE="$OUT/storage_${STAMP}.tgz"

# ⚠️ `--single-transaction` وإلّا قُفِلَتِ الجداولُ أثناءَ الحفظِ ووقفَ الموقعُ
# دقائقَ كلَّ ليلة. و`--routines --triggers` لأنّ التفريغَ بدونها ينجحُ ويعودُ
# ناقصاً — والنقصُ لا يظهرُ إلّا يومَ الاستعادة.
docker compose -f docker/docker-compose.prod.yml --env-file docker/.env \
  exec -T mysql mysqldump \
    -u root -p"$DB_ROOT_PASSWORD" \
    --single-transaction --routines --triggers --quick \
    "$DB_DATABASE" | gzip -9 > "$DB_FILE"

# المرفوعات: إيصالاتُ الدفعِ والصورُ وما رُفِعَ محلّيّاً. من المُجلَّدِ مباشرةً،
# فلا يعتمدُ على كونِ التطبيقِ يعمل.
docker run --rm \
  -v elmotamayez_storage:/data:ro \
  -v "$OUT":/out \
  alpine tar czf "/out/$(basename "$ST_FILE")" -C /data . 2>/dev/null

# ⚠️ فحصٌ لا زينة. نسخةٌ لا تُقرَأُ ليست نسخة: تفريغٌ فاشلٌ يُنتِجُ ملفّاً
# بحجمِ صفرٍ أو gzip مقطوعاً، ويبقى في المُجلَّدِ شهراً يبدو كنسخةٍ سليمة.
for f in "$DB_FILE" "$ST_FILE"; do
  gzip -t "$f" 2>/dev/null || { echo "✗ تالفة: $f" >&2; exit 1; }
done
[ "$(stat -c%s "$DB_FILE")" -gt 10240 ] || { echo "✗ تفريغُ القاعدةِ صغيرٌ للشكّ: $DB_FILE" >&2; exit 1; }

find "$OUT" -maxdepth 1 -name 'db_*.sql.gz'   -mtime +"$KEEP_DAYS" -delete
find "$OUT" -maxdepth 1 -name 'storage_*.tgz' -mtime +"$KEEP_DAYS" -delete

echo "✓ $(date +%F\ %T)  $(du -h "$DB_FILE" | cut -f1) قاعدة  ·  $(du -h "$ST_FILE" | cut -f1) مرفوعات"

# ── الاستعادة ────────────────────────────────────────────────────────────
#   gunzip -c /srv/backups/db_XXXX.sql.gz | docker compose -f docker/docker-compose.prod.yml \
#     --env-file docker/.env exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"
#   docker run --rm -v elmotamayez_storage:/data -v /srv/backups:/in alpine \
#     tar xzf /in/storage_XXXX.tgz -C /data
#
# ── التنصيب في cron ──────────────────────────────────────────────────────
#   (crontab -l 2>/dev/null; echo '17 3 * * * cd /srv/elmotamayez && bash scripts/backup.sh >> /var/log/elmotamayez-backup.log 2>&1') | crontab -
#
# ⚠️ هذه النسخُ على **نفسِ القرصِ** الذي تحمي بياناتِه. تحمي من الحذفِ الخاطئِ
# ومن هجرةٍ فاسدة، ولا تحمي من ضياعِ الخادم. النقلُ خارجَه يحتاجُ وجهةً
# (لقطةُ Hostinger أو `rclone` إلى R2) — قرارٌ لصاحبِ الخادم.
