import { useI18n } from "vue-i18n";

export function useImageValidationRules() {
  const { t } = useI18n();

  const required = (v: string) => !!v || t("validation.required");
  const maxLength = (max: number) => (v: string) =>
    v.length <= max || t("validation.maxLength", { max });

  // Mirrors backend/app/Http/Requests/StoreImageRequest.php — change both together.
  const titleRules = [required, maxLength(140)];
  const descriptionRules = [maxLength(400)];

  // Mirrors backend/app/Rules/ImageValidationRules.php — change both together.
  const IMAGE_MAX_BYTES = 10 * 1024 * 1024;
  const IMAGE_MIME_TYPES = ["image/jpeg", "image/png", "image/webp"];

  const imageFile = (file: File) => {
    if (!IMAGE_MIME_TYPES.includes(file.type)) {
      return t("validation.imageType");
    }
    if (file.size > IMAGE_MAX_BYTES) {
      return t("validation.imageTooLarge", { max: 10 });
    }
    return true;
  };

  return {
    titleRules,
    descriptionRules,
    imageFile,
    IMAGE_MAX_BYTES,
    IMAGE_MIME_TYPES,
  };
}
