import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import { useCategoryStore } from "@/stores/category";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const mockedApi = vi.mocked(api);

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("fetchPickerCategories", () => {
  it("unwraps the resource collection's data envelope", async () => {
    const categories = [{ id: 1, name_en: "Books", name_ar: "كتب" }];
    mockedApi.get.mockResolvedValue({ data: { data: categories } });

    await expect(useCategoryStore().fetchPickerCategories()).resolves.toEqual(categories);
    expect(mockedApi.get).toHaveBeenCalledWith("/api/categories/picker");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.get.mockRejectedValue(new Error("boom"));

    await expect(useCategoryStore().fetchPickerCategories()).rejects.toThrow("boom");
  });
});

describe("fetchAllCategories", () => {
  it("requests the admin listing and unwraps the data envelope", async () => {
    const categories = [
      { id: 1, name_en: "Books", name_ar: "كتب", deleted_at: null },
      { id: 2, name_en: "Gone", name_ar: "محذوف", deleted_at: "2026-08-15T10:00:00Z" },
    ];
    mockedApi.get.mockResolvedValue({ data: { data: categories } });

    await expect(useCategoryStore().fetchAllCategories()).resolves.toEqual(categories);
    expect(mockedApi.get).toHaveBeenCalledWith("/api/categories");
  });
});

describe("restoreCategory", () => {
  it("posts to the category's restore endpoint", async () => {
    const category = { id: 7, name_en: "Books", name_ar: "كتب", deleted_at: null };
    mockedApi.post.mockResolvedValue({ data: { data: category } });

    await expect(useCategoryStore().restoreCategory(7)).resolves.toEqual(category);
    expect(mockedApi.post).toHaveBeenCalledWith("/api/categories/7/restore");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.post.mockRejectedValue(new Error("boom"));

    await expect(useCategoryStore().restoreCategory(7)).rejects.toThrow("boom");
  });
});

describe("updateCategory", () => {
  it("patches only the fields it is given", async () => {
    const category = { id: 3, name_en: "Sports", name_ar: "رياضة" };
    mockedApi.patch.mockResolvedValue({ data: { data: category } });

    await expect(
      useCategoryStore().updateCategory(3, { name_en: "Sports" }),
    ).resolves.toEqual(category);

    expect(mockedApi.patch).toHaveBeenCalledWith("/api/categories/3", {
      name_en: "Sports",
    });
  });
});

describe("deleteCategory", () => {
  it("deletes by id", async () => {
    mockedApi.delete.mockResolvedValue({ status: 204 });

    await useCategoryStore().deleteCategory(3);

    expect(mockedApi.delete).toHaveBeenCalledWith("/api/categories/3");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.delete.mockRejectedValue(new Error("boom"));

    await expect(useCategoryStore().deleteCategory(3)).rejects.toThrow("boom");
  });
});

describe("createCategory", () => {
  it("posts both names and returns the created category", async () => {
    const category = { id: 21, name_en: "Sports", name_ar: "رياضة" };
    mockedApi.post.mockResolvedValue({ data: { data: category } });

    await expect(
      useCategoryStore().createCategory({ name_en: "Sports", name_ar: "رياضة" }),
    ).resolves.toEqual(category);

    expect(mockedApi.post).toHaveBeenCalledWith("/api/categories", {
      name_en: "Sports",
      name_ar: "رياضة",
    });
  });
});
