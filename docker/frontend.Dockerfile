FROM node:24-alpine AS builder

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

ENV NEXT_PUBLIC_APP_URL=$NEXT_PUBLIC_APP_URL
ENV NEXT_PUBLIC_REVERB_APP_KEY=$NEXT_PUBLIC_REVERB_APP_KEY
ENV NEXT_PUBLIC_REVERB_HOST=$NEXT_PUBLIC_REVERB_HOST
ENV NEXT_PUBLIC_REVERB_PORT=$NEXT_PUBLIC_REVERB_PORT
ENV NEXT_PUBLIC_REVERB_SCHEME=$NEXT_PUBLIC_REVERB_SCHEME
ENV NEXT_TELEMETRY_DISABLED=1

# ⚠️ يبني بلا API، ويجبُ أن ينجحَ كذلك: صفحاتُ المتجرِ الأربعُ تُهيَّأُ مسبقاً
# بجلبِ الـAPI وكلٌّ منها يلتقطُ فشلَه. صفحةٌ تجلبُ بلا `try` تُسقِطُ البناءَ هنا،
# وهي في الإنتاجِ تُسقِطُ نشرةً كاملةً لأنّ الـAPI تأخّرَ ثانيتَين عن الواجهة.
# ⚠️ خبيئةُ `.next/cache` عبرَ BuildKit لا عبرَ طبقةٍ: الطبقةُ تسقطُ عندَ أوّلِ
# `COPY . .` مختلف، أي عندَ كلِّ نشرة. الخبيئةُ تبقى على قرصِ الخادمِ ولا تدخلُ
# الصورةَ — والمُشغِّلُ ينسخُ `standalone` و`static` وحدَهما.
RUN --mount=type=cache,target=/app/.next/cache npm run build

FROM node:24-alpine AS runner

WORKDIR /app

ENV NODE_ENV=production

# ⚠️ `0.0.0.0` لا الافتراضيّ. خادمُ standalone يستمعُ على `localhost` داخلَ
# الحاوية، وهو عنوانٌ لا يصلُه nginx من حاويةٍ أخرى — فالنتيجةُ ٥٠٢ على كلِّ
# صفحةٍ بينما سجلُّ الواجهةِ يقولُ «ready» بلا شكوى.
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

COPY --from=builder /app/.next/standalone ./
COPY --from=builder /app/.next/static ./.next/static
COPY --from=builder /app/public ./public

EXPOSE 3000

CMD ["node", "server.js"]
