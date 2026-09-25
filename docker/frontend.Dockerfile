FROM node:24.21.0-alpine AS builder

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

COPY . .

# ⚠️ قيمُ `NEXT_PUBLIC_*` تُدمَجُ في الحزمةِ وقتَ البناءِ لا وقتَ التشغيل، فلا
# تكفي في `environment:` — الصفحةُ تُشحَنُ حاملةً القيمةَ التي بُنِيَت بها، وهي
# الفراغُ، بلا خطأٍ في أيِّ مكان. مضيفُ السوكِتِ المكتوبُ خطأً يُنتِجُ نقاشاً
# «يعملُ متأخّراً»، ونظامٌ `http` في صفحةِ `https` يُرفَضُ كمحتوًى مختلَطٍ بصمت.
ARG NEXT_PUBLIC_APP_URL
ARG NEXT_PUBLIC_REVERB_APP_KEY
ARG NEXT_PUBLIC_REVERB_HOST
ARG NEXT_PUBLIC_REVERB_PORT
ARG NEXT_PUBLIC_REVERB_SCHEME
ARG NEXT_PUBLIC_VAPID_PUBLIC_KEY

ENV NEXT_PUBLIC_APP_URL=$NEXT_PUBLIC_APP_URL
ENV NEXT_PUBLIC_REVERB_APP_KEY=$NEXT_PUBLIC_REVERB_APP_KEY
ENV NEXT_PUBLIC_REVERB_HOST=$NEXT_PUBLIC_REVERB_HOST
ENV NEXT_PUBLIC_REVERB_PORT=$NEXT_PUBLIC_REVERB_PORT
ENV NEXT_PUBLIC_REVERB_SCHEME=$NEXT_PUBLIC_REVERB_SCHEME
# The PUBLIC half only — it is the JWT `iss` by protocol. Empty until the server
# `.env` carries VAPID keys, and `pushAvailable()` then hides the card.
ENV NEXT_PUBLIC_VAPID_PUBLIC_KEY=$NEXT_PUBLIC_VAPID_PUBLIC_KEY
ENV NEXT_TELEMETRY_DISABLED=1

# ⚠️ يبني بلا API، ويجبُ أن ينجحَ كذلك: صفحاتُ المتجرِ الأربعُ تُهيَّأُ مسبقاً
# بجلبِ الـAPI وكلٌّ منها يلتقطُ فشلَه. صفحةٌ تجلبُ بلا `try` تُسقِطُ البناءَ هنا،
# وهي في الإنتاجِ تُسقِطُ نشرةً كاملةً لأنّ الـAPI تأخّرَ ثانيتَين عن الواجهة.
# ⚠️ خبيئةُ `.next/cache` عبرَ BuildKit لا عبرَ طبقةٍ: الطبقةُ تسقطُ عندَ أوّلِ
# `COPY . .` مختلف، أي عندَ كلِّ نشرة. الخبيئةُ تبقى على قرصِ الخادمِ ولا تدخلُ
# الصورةَ — والمُشغِّلُ ينسخُ `standalone` و`static` وحدَهما.
RUN --mount=type=cache,target=/app/.next/cache npm run build

FROM node:24.21.0-alpine AS runner

WORKDIR /app

ENV NODE_ENV=production

# ⚠️ `0.0.0.0` لا الافتراضيّ. خادمُ standalone يستمعُ على `localhost` داخلَ
# الحاوية، وهو عنوانٌ لا يصلُه nginx من حاويةٍ أخرى — فالنتيجةُ ٥٠٢ على كلِّ
# صفحةٍ بينما سجلُّ الواجهةِ يقولُ «ready» بلا شكوى.
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

# ⚠️ مستخدمُ `node` (المبنيُّ في الصورةِ الرسميّة) لا root، و`--chown` على
# **النسخِ الثلاثِ** ليس زينة: إعادةُ التوليدِ (`revalidate`) تكتبُ وقتَ التشغيلِ
# داخلَ `.next/server/app/*` و`.next/cache`. ملفّاتٌ ملكُ root تحتَ مستخدمٍ غيرِ
# root تعني أنّ كلَّ إعادةِ توليدٍ تفشلُ بسطرٍ في السجلِّ وحدَه، والصفحةُ القديمةُ
# تُخدَمُ إلى الأبد — لا خطأَ يراه أحد. و`.next` نفسُه يُنشأُ مسبقاً بمالكِه كما
# في مثالِ Next الرسميّ، فلا يبقى مجلّدٌ أبٌ ضمنيٌّ ملكَ root.
RUN mkdir -p .next && chown node:node .next
COPY --from=builder --chown=node:node /app/.next/standalone ./
COPY --from=builder --chown=node:node /app/.next/static ./.next/static
COPY --from=builder --chown=node:node /app/public ./public

USER node

EXPOSE 3000

CMD ["node", "server.js"]
