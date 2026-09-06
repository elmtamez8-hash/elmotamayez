import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AccountPhotoCard } from "./AccountPhotoCard";

/*
| ⚠️ THIS CONTROL IS THE FIRST WRITER THE COLUMN HAS EVER HAD.
| `teacher_profiles.photo_path` and `student_profiles.avatar_path` had four
| readers each and no writer anywhere, so the initial-in-a-circle was not a
| fallback — it was the only state the product could reach.
|
| ⚠️ AND PICKING A FILE NO LONGER UPLOADS IT — reported 2026-09-06. The first
| version sent the picked file as-is, and every screen then drew it inside
| `rounded-full object-cover`, which crops FROM THE CENTRE. A face in a phone
| photograph is rarely in the centre, so the product decided the framing and the
| owner could not. Picking now opens the crop stage; the upload is what the owner
| saw in the circle.
|
| ⚠️ `jsdom` HAS NO CANVAS AND LOADS NO IMAGES, so the two functions that touch
| either are the mocked ones — and they are two lines each for exactly that
| reason. The geometry they sit around is measured in `lib/avatar-crop.test.ts`.
*/

const savePhoto = vi.fn();
const removePhoto = vi.fn();
const loadImageFile = vi.fn();
const exportCrop = vi.fn();

vi.mock("@/lib/profile", () => ({
  profileApi: {
    savePhoto: (file: File) => savePhoto(file),
    removePhoto: () => removePhoto(),
  },
}));

/*
| ⚠️ البطاقةُ تُخبِرُ السياقَ أنَّ الصورةَ تغيّرت، وإلّا بقيتِ القديمةُ في
| الشريطِ الجانبيِّ وقائمةِ الحسابِ إلى أن يُعادَ تحميلُ التطبيق — بلاغُ مستخدِمٍ
| ٢٠٢٦-٠٩-٠٦.
*/
const refreshUser = vi.fn();

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ refreshUser }) }));

vi.mock("@/lib/avatar-crop", async (importOriginal) => ({
  // The real geometry: the cropper renders through it, so stubbing it away
  // would leave these cases asserting that a mock returns its own answer.
  ...(await importOriginal<typeof import("@/lib/avatar-crop")>()),
  loadImageFile: (file: File) => loadImageFile(file),
  exportCrop: (...args: unknown[]) => exportCrop(...args),
}));

/** The three fields `AvatarCropper` reads off a loaded image, and nothing else. */
const IMAGE = { naturalWidth: 1200, naturalHeight: 800, src: "blob:fake" };

function pick(container: HTMLElement) {
  fireEvent.change(container.querySelector('input[type="file"]') as HTMLInputElement, {
    target: { files: [new File(["x"], "me.png", { type: "image/png" })] },
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  loadImageFile.mockResolvedValue(IMAGE);
  exportCrop.mockResolvedValue(new Blob(["jpeg"], { type: "image/jpeg" }));
});

describe("AccountPhotoCard", () => {
  it("shows the first letter when there is no photo, and offers to add one", () => {
    render(<AccountPhotoCard initialUrl={null} name="خالد" />);

    expect(screen.getByText("خ")).toBeDefined();
    expect(screen.getByRole("button", { name: "ارفع صورة" })).toBeDefined();
    // Nothing to remove yet: a control that refuses on every press is worse
    // than an absent one.
    expect(screen.queryByRole("button", { name: "أزِل الصورة" })).toBeNull();
  });

  it("opens the crop stage on pick, and uploads NOTHING until it is confirmed", async () => {
    const { container } = render(<AccountPhotoCard initialUrl={null} name="خالد" />);

    pick(container);

    await waitFor(() => {
      expect(screen.getByRole("button", { name: "احفظ الصورة" })).toBeDefined();
    });

    // The whole point of the change: the picked bytes have not left the browser.
    expect(savePhoto).not.toHaveBeenCalled();
    expect(screen.getByRole("slider")).toBeDefined();
  });

  it("uploads a square JPEG built from the crop, and swaps the picture for the server's answer", async () => {
    savePhoto.mockResolvedValue({ photo_url: "/storage/avatars/new.jpg" });

    const { container } = render(
      <AccountPhotoCard initialUrl="/storage/avatars/old.jpg" name="خالد" />,
    );

    expect(container.querySelector("img")?.getAttribute("src")).toBe("/storage/avatars/old.jpg");

    pick(container);

    fireEvent.click(await screen.findByRole("button", { name: "احفظ الصورة" }));

    await waitFor(() => {
      expect(savePhoto).toHaveBeenCalledTimes(1);
    });

    // JPEG and not WebP: Safari's `toBlob('image/webp')` falls back to PNG in
    // silence, which is a BIGGER file — the requirement inverted, on the one
    // browser nobody tests.
    const sent = savePhoto.mock.calls[0][0] as File;

    expect(sent.type).toBe("image/jpeg");

    await waitFor(() => {
      expect(container.querySelector("img")?.getAttribute("src")).toBe(
        "/storage/avatars/new.jpg",
      );
    });

    // ⚠️ والسياقُ يُعادُ قراءتُه. بلا هذا التوكيدِ تمرُّ الحالةُ خضراءَ على
    // بناءٍ تتغيّرُ فيهِ الصورةُ في هذهِ البطاقةِ وحدَها وتبقى القديمةُ في كلِّ
    // صفحةٍ أخرى — وهو البلاغُ بعينِه.
    expect(refreshUser).toHaveBeenCalled();
  });

  it("returns to the picture on cancel, having sent nothing", async () => {
    const { container } = render(
      <AccountPhotoCard initialUrl="/storage/avatars/old.jpg" name="خالد" />,
    );

    pick(container);

    fireEvent.click(await screen.findByRole("button", { name: "إلغاء" }));

    await waitFor(() => {
      expect(screen.getByRole("button", { name: "غيّر الصورة" })).toBeDefined();
    });

    expect(savePhoto).not.toHaveBeenCalled();
  });

  it("says why a file it cannot open failed, instead of doing nothing", async () => {
    // ⚠️ A rejected promise with no catch paints a raw overlay in development
    // and does ABSOLUTELY NOTHING in production — a button pressed with no
    // result, on the control a corrupt phone photograph reaches first.
    loadImageFile.mockRejectedValue(new Error("تعذّر فتح هذه الصورة. جرّب صورة أخرى."));

    const { container } = render(<AccountPhotoCard initialUrl={null} name="خالد" />);

    pick(container);

    await waitFor(() => {
      expect(screen.getByText("تعذّر تحديث الصورة")).toBeDefined();
    });
  });

  it("falls back to the initial again after a removal", async () => {
    removePhoto.mockResolvedValue({ photo_url: null });

    render(<AccountPhotoCard initialUrl="/storage/avatars/old.jpg" name="خالد" />);

    fireEvent.click(screen.getByRole("button", { name: "أزِل الصورة" }));

    await waitFor(() => {
      expect(screen.getByText("خ")).toBeDefined();
    });
  });

  it("says why a refused upload failed instead of doing nothing", async () => {
    savePhoto.mockRejectedValue(
      Object.assign(new Error("too big"), { status: 422, message: "الصورة أكبر من ٤ ميغابايت." }),
    );

    const { container } = render(<AccountPhotoCard initialUrl={null} name="خالد" />);

    pick(container);

    fireEvent.click(await screen.findByRole("button", { name: "احفظ الصورة" }));

    await waitFor(() => {
      expect(screen.getByText("تعذّر تحديث الصورة")).toBeDefined();
    });
  });
});
