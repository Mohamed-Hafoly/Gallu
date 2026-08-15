import type { Image } from "@/types/image";
import { defineStore } from "pinia";
import api from "@/plugins/axios";

interface CreateImagePayload {
  title: string;
  description?: string;
  selected_category_ids: number[];
  image: File;
}

interface UpdateImagePayload {
  title: string;
  description?: string;
  selected_category_ids: number[];
  image?: File;
}

export const useImageStore = defineStore("image", () => {
  async function fetchImages() {
    const { data } = await api.get("/api/images");
    console.log("fetchImages response", data.data);
    return data.data as Image[];
  }

  async function createImage(payload: CreateImagePayload) {
    const formData = new FormData();
    formData.append("title", payload.title);
    if (payload.description) formData.append("description", payload.description);
    for (const id of payload.selected_category_ids) formData.append("selected_category_ids[]", String(id));
    formData.append("image", payload.image);

    const { data } = await api.post("/api/images", formData);
    console.log("createImage response", data.data);
    return data.data as Image;
  }

  async function updateImage(id: number, payload: UpdateImagePayload) {
    const formData = new FormData();
    formData.append("_method", "PATCH");
    formData.append("title", payload.title);
    if (payload.description) formData.append("description", payload.description);
    for (const catId of payload.selected_category_ids) formData.append("selected_category_ids[]", String(catId));
    if (payload.image) formData.append("image", payload.image);

    const { data } = await api.post(`/api/images/${id}`, formData);
    console.log("updateImage response", data.data);
    return data.data as Image;
  }

  async function deleteImage(id: number) {
    const response = await api.delete(`/api/images/${id}`);
    console.log("deleteImage response", response.status);
  }

  return { fetchImages, createImage, updateImage, deleteImage };
});
