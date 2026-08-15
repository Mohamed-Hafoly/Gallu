import { createPinia, setActivePinia } from "pinia";
import { beforeEach, describe, expect, it, vi } from "vitest";
import api from "@/plugins/axios";
import { useCategoryStore } from "@/stores/category";

vi.mock("@/plugins/axios", () => ({
  default: { get: vi.fn() },
}));

const mockedApi = vi.mocked(api);

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe("fetchCategories", () => {
  it("unwraps the resource collection's data envelope", async () => {
    const categories = [{ id: 1, name_en: "Books", name_ar: "كتب" }];
    mockedApi.get.mockResolvedValue({ data: { data: categories } });

    await expect(useCategoryStore().fetchCategories()).resolves.toEqual(categories);
    expect(mockedApi.get).toHaveBeenCalledWith("/api/categories");
  });

  it("propagates failures rather than swallowing them", async () => {
    mockedApi.get.mockRejectedValue(new Error("boom"));

    await expect(useCategoryStore().fetchCategories()).rejects.toThrow("boom");
  });
});
