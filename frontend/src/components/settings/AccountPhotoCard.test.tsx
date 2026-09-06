import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AccountPhotoCard } from "./AccountPhotoCard";

/*
| ⚠️ THIS CONTROL IS THE FIRST WRITER THE COLUMN HAS EVER HAD.
| `teacher_profiles.photo_path` and `student_profiles.avatar_path` had four
| readers each and no writer anywhere, so the initial-in-a-circle was not a
| fallback — it was the only state the product could reach.
*/

const savePhoto = vi.fn();
const removePhoto = vi.fn();

vi.mock("@/lib/profile", () => ({
  profileApi: {
    savePhoto: (file: File) => savePhoto(file),
    removePhoto: () => removePhoto(),
  },
}));

beforeEach(() => {
  vi.clearAllMocks();
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

  it("swaps the picture for the one the server answers with", async () => {
    savePhoto.mockResolvedValue({ photo_url: "/storage/avatars/new.png" });

    const { container } = render(
      <AccountPhotoCard initialUrl="/storage/avatars/old.png" name="خالد" />,
    );

    expect(container.querySelector("img")?.getAttribute("src")).toBe(
      "/storage/avatars/old.png",
    );

    fireEvent.change(container.querySelector('input[type="file"]') as HTMLInputElement, {
      target: { files: [new File(["x"], "me.png", { type: "image/png" })] },
    });

    await waitFor(() => {
      expect(container.querySelector("img")?.getAttribute("src")).toBe(
        "/storage/avatars/new.png",
      );
    });

    expect(savePhoto).toHaveBeenCalledTimes(1);
  });

  it("falls back to the initial again after a removal", async () => {
    removePhoto.mockResolvedValue({ photo_url: null });

    render(<AccountPhotoCard initialUrl="/storage/avatars/old.png" name="خالد" />);

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

    fireEvent.change(container.querySelector('input[type="file"]') as HTMLInputElement, {
      target: { files: [new File(["x"], "huge.png", { type: "image/png" })] },
    });

    await waitFor(() => {
      expect(screen.getByText("تعذّر تحديث الصورة")).toBeDefined();
    });
  });
});
