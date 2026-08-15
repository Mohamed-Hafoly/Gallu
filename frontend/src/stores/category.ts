import type { Category } from "@/types/category";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

export const useCategoryStore = defineStore("category", () => {
  async function fetchCategories() {
    const { data } = await api.get("/api/categories");
    console.log("fetchCategories response", data.data);
    return data.data as Category[];
  }

  return { fetchCategories };
});
