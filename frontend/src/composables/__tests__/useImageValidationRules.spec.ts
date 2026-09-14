import { beforeEach, describe, expect, it } from "vitest";
import { withSetup } from "@/__tests__/helpers/withSetup";
import { useImageValidationRules } from "@/composables/useImageValidationRules";
import i18n from "@/plugins/i18n";

function rules() {
  return withSetup(() => useImageValidationRules());
}

/** A rule passes when it returns `true`; otherwise it returns the message. */
function failures(list: ((v: string) => unknown)[], value: string) {
  return list.map((rule) => rule(value)).filter((r) => r !== true);
}

function fileOfSize(bytes: number, type = "image/jpeg") {
  const file = new File(["x"], "photo.jpg", { type });
  Object.defineProperty(file, "size", { value: bytes });
  return file;
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("titleRules — mirrors StoreImageRequest 'title'", () => {
  it("rejects an empty title", () => {
    expect(failures(rules().titleRules, "")).not.toHaveLength(0);
  });

  it("accepts a title at exactly the 140 char limit", () => {
    expect(failures(rules().titleRules, "a".repeat(140))).toHaveLength(0);
  });

  it("rejects a title one char over the limit", () => {
    expect(failures(rules().titleRules, "a".repeat(141))).not.toHaveLength(0);
  });
});

describe("descriptionRules — mirrors StoreImageRequest 'description'", () => {
  it("accepts an empty description (nullable server-side)", () => {
    expect(failures(rules().descriptionRules, "")).toHaveLength(0);
  });

  it("accepts exactly 400 chars but rejects 401", () => {
    expect(failures(rules().descriptionRules, "a".repeat(400))).toHaveLength(0);
    expect(failures(rules().descriptionRules, "a".repeat(401))).not.toHaveLength(0);
  });
});

describe("imageFile — mirrors ImageValidationRules::image()", () => {
  it("accepts each allowed mime type", () => {
    const { imageFile, IMAGE_MIME_TYPES } = rules();
    for (const type of IMAGE_MIME_TYPES) {
      expect(imageFile(fileOfSize(1024, type))).toBe(true);
    }
  });

  it("rejects a disallowed mime type", () => {
    expect(rules().imageFile(fileOfSize(1024, "application/pdf"))).not.toBe(true);
  });

  it("accepts a file at the size cap but rejects one byte over", () => {
    const { imageFile, IMAGE_MAX_BYTES } = rules();
    expect(imageFile(fileOfSize(IMAGE_MAX_BYTES))).toBe(true);
    expect(imageFile(fileOfSize(IMAGE_MAX_BYTES + 1))).not.toBe(true);
  });

  it("caps at 10MB, matching config('media-library.max_file_size')", () => {
    expect(rules().IMAGE_MAX_BYTES).toBe(10 * 1024 * 1024);
  });
});

describe("whitespace handling", () => {
  it("rejects a whitespace-only title, matching TrimStrings", () => {
    expect(failures(rules().titleRules, ' '.repeat(4))).not.toHaveLength(0);
  });

  it("does not count padding toward the length limits", () => {
    expect(failures(rules().titleRules, `  ${"a".repeat(140)}  `)).toHaveLength(0);
    expect(failures(rules().titleRules, "a".repeat(141))).not.toHaveLength(0);

    expect(failures(rules().descriptionRules, `  ${"a".repeat(400)}  `)).toHaveLength(0);
    expect(failures(rules().descriptionRules, "a".repeat(401))).not.toHaveLength(0);
  });
});
