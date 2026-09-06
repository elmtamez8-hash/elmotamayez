/**
 * قصُّ صورةِ الحسابِ في مربَّع — الحسابُ وحدَه، بلا DOM.
 *
 * ⚠️ الهندسةُ هنا والرسمُ في المكوّن، وذلك ليست ترتيباً جميلاً بل الشرطَ الوحيدَ
 * الذي يجعلُ هذا مقيساً: `jsdom` بلا `canvas` إطلاقاً — لا `getContext` ولا
 * `toBlob` — فمنطقٌ مكتوبٌ داخلَ المكوّنِ لا يستطيعُ `npm test` أن يقتربَ منه،
 * وهو المكانُ الذي يعيشُ فيه العطبُ (مقدارُ التكبيرِ الأدنى، وتقييدُ الإزاحةِ عندَ
 * الحواف). المكوّنُ يصيرُ بضعةَ أسطرٍ لا يُخطئُ فيها أحد.
 *
 * ⚠️ والمخزَّنُ **مربَّعٌ لا دائرة**: الدائرةُ تُرسَمُ بالـCSS (`rounded-full`) في
 * تسعِ شاشاتٍ تقرأُ هذا العمود، وصورةٌ بشفافيّةٍ خارجَ الدائرةِ تعني PNG بدلَ JPEG
 * — ملفٌّ أكبرُ لحلِّ مشكلةٍ لا وجودَ لها.
 */

export interface CropView {
  /** المقاسُ الطبيعيُّ للصورةِ المرفوعة. */
  naturalWidth: number;
  naturalHeight: number;
  /** ضلعُ نافذةِ المعاينةِ بالبكسل. */
  view: number;
}

export interface CropState {
  /** ١ = أصغرُ تكبيرٍ يغطّي النافذة. */
  zoom: number;
  /** إزاحةُ أعلى-يسارِ الصورةِ المكبَّرةِ داخلَ النافذة، بالبكسل، سالبةٌ أو صفر. */
  offsetX: number;
  offsetY: number;
}

export const MIN_ZOOM = 1;
export const MAX_ZOOM = 4;

/**
 * أصغرُ تكبيرٍ **يغطّي** النافذةَ لا يحتويها.
 *
 * ⚠️ `max` لا `min`، وهذا هو الفرقُ كلُّه: `min` يحتوي، فتظهرُ أشرطةٌ فارغةٌ داخلَ
 * الدائرةِ في أيِّ صورةٍ ليست مربَّعة — وهي كلُّ صورةِ هاتفٍ تقريباً.
 */
export function coverScale({ naturalWidth, naturalHeight, view }: CropView): number {
  if (naturalWidth <= 0 || naturalHeight <= 0) return 1;

  return Math.max(view / naturalWidth, view / naturalHeight);
}

/** التكبيرُ ضمنَ حدَّيه — مُدخَلُ الشريطِ لا يُوثَقُ به أكثرَ من غيرِه. */
export function clampZoom(zoom: number): number {
  if (!Number.isFinite(zoom)) return MIN_ZOOM;

  return Math.min(Math.max(zoom, MIN_ZOOM), MAX_ZOOM);
}

/**
 * إزاحةٌ لا تكشفُ فراغاً.
 *
 * الصورةُ المكبَّرةُ عرضُها `natural * scale` والنافذةُ `view`، فالإزاحةُ محبوسةٌ
 * بينَ `view - عرضُ الصورة` (نهايةُ السحبِ) وصفرٍ (بدايتُها). ولو كانتِ الصورةُ
 * أضيقَ من النافذةِ — لا يحدثُ بعدَ `coverScale` إلّا بخطأِ تقريبٍ عائم — فالحدُّ
 * الأدنى يتجاوزُ الأعلى ويعودُ `0`، أي التوسيط، لا قيمةً موجبةً تكشفُ حافّة.
 */
export function clampOffset(offset: number, natural: number, scale: number, view: number): number {
  const lower = Math.min(view - natural * scale, 0);

  return Math.min(Math.max(offset, lower), 0);
}

/**
 * أينَ تبدأُ الصورةُ عندَ فتحِ المحرّر: في المنتصف.
 *
 * صفرٌ يعني الحافّةَ العليا اليسرى، وهي أسوأُ بدايةٍ ممكنة — الوجهُ في صورةٍ
 * رأسيّةٍ يقعُ في الثلثِ الأعلى لا في الحافّة، وفي صورةٍ عريضةٍ في الوسط. فالبدءُ
 * من المنتصفِ يوافقُ ما كانَ المتصفّحُ يفعلُه بـ`object-cover` من قبل، والفرقُ
 * أنّ صاحبَها يستطيعُ الآنَ أن يزيحَه.
 *
 * والمحورُ الذي تغطّيه الصورةُ بالضبطِ يعطي صفراً من تلقاءِ نفسِه: نصفُ الفرقِ
 * بينَ مقاسَينِ متساويَين صفر.
 */
export function centredOffset(frame: CropView, scale: number): { x: number; y: number } {
  return {
    x: (frame.view - frame.naturalWidth * scale) / 2,
    y: (frame.view - frame.naturalHeight * scale) / 2,
  };
}

/**
 * المستطيلُ المرئيُّ من الصورةِ الأصليّة — وهو ما يُرسَمُ على اللوحة.
 *
 * إحداثيّاتُ النافذةِ تُقسَمُ على `scale` فتعودُ إلى إحداثيّاتِ المصدر. والضلعُ
 * `view / scale` لأنّ النافذةَ مربَّعةٌ بحكمِ التعريف.
 */
export function sourceRect(
  frame: CropView,
  state: CropState,
): { sx: number; sy: number; size: number } {
  const scale = coverScale(frame) * clampZoom(state.zoom);
  const x = clampOffset(state.offsetX, frame.naturalWidth, scale, frame.view);
  const y = clampOffset(state.offsetY, frame.naturalHeight, scale, frame.view);

  // `+ 0` يحوّلُ `-0` إلى `0`: إزاحةُ صفرٍ تنفيٌ تعطي `-0`، وهو مقبولٌ
  // عندَ `drawImage` ويفشلُ في `toEqual({ sx: 0 })` — فرقٌ لا يراهُ أحدٌ إلّا
  // من يقرأُ توكيداً أحمرَ ليسَ عن شيء.
  return { sx: -x / scale + 0, sy: -y / scale + 0, size: frame.view / scale };
}

/** ضلعُ الملفِّ المُصدَّر — الخادمُ يُعيدُ ترميزَه إلى ٥١٢ على أيِّ حال. */
export const OUTPUT_SIZE = 512;

/**
 * اللوحةُ — السطرُ الوحيدُ الذي لا يستطيعُ `jsdom` تشغيلَه.
 *
 * ⚠️ JPEG لا WebP. `toBlob('image/webp')` في سفاري يرتدُّ **صامتاً** إلى PNG،
 * فيخرجُ ملفٌّ **أكبرُ** من الأصلِ أحياناً — المطلبُ مقلوباً، على المتصفّحِ الذي
 * لا يجرّبُه أحد. ولا شفافيّةَ في صورةِ وجهٍ فلا خسارةَ في JPEG.
 */
export function exportCrop(
  image: CanvasImageSource,
  rect: { sx: number; sy: number; size: number },
  out: number = OUTPUT_SIZE,
): Promise<Blob> {
  const canvas = document.createElement("canvas");

  canvas.width = out;
  canvas.height = out;

  const context = canvas.getContext("2d");

  if (context === null) {
    return Promise.reject(new Error("تعذّر تجهيز الصورة في هذا المتصفّح."));
  }

  context.drawImage(image, rect.sx, rect.sy, rect.size, rect.size, 0, 0, out, out);

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob === null ? reject(new Error("تعذّر تجهيز الصورة.")) : resolve(blob)),
      "image/jpeg",
      0.85,
    );
  });
}

/**
 * الملفُّ المختارُ ⇐ عنصرُ صورةٍ محمَّل.
 *
 * ⚠️ `<img>` و`createObjectURL`، وليس `createImageBitmap`. المتصفّحُ يطبّقُ دورانَ
 * EXIF على مسارِ `<img>`، بينما افتراضُ `createImageBitmap` في شأنِ الدورانِ
 * اختلفَ بينَ الإصدارات — وصورةُ هاتفٍ ملتقطةٌ رأسيّاً تظهرُ مضطجعةً في الدائرة،
 * وهو بالضبطِ العطبُ الذي كانت هذه الميزةُ ستُشحَنُ به.
 */
export function loadImageFile(file: File): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const image = new Image();

    // ⚠️ ولا يُبطَلُ العنوانُ هنا وإن كانَ ذلكَ ما تقولُهُ كلُّ وصفة.
    // إبطالُهُ عندَ `onload` يتركُ هذا العنصرَ سليماً — بكسلاتُهُ مفكوكةٌ
    // سلفاً — و**يقتلُ أيَّ `<img>` لاحقٍ يحملُ العنوانَ نفسَه**، ومرحلةُ
    // القصِّ ترسمُ واحداً بالضبط: فتظهرُ دائرةٌ فارغةٌ بلا خطأٍ واحدٍ في
    // الطرفيّة، ولا يراهُ `jsdom` لأنّه لا يحمّلُ صوراً أصلاً. مَن يعرضُ هو
    // مَن يُبطِلُ: {@see AvatarCropper} عندَ التفكيك.
    image.onload = () => resolve(image);

    image.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error("تعذّر فتح هذه الصورة. جرّب صورة أخرى."));
    };

    image.src = url;
  });
}
