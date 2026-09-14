import { useI18n } from "vue-i18n";

export function useImageValidationRules() {
  const { t } = useI18n();

  // Measured against the trimmed value, because Laravel's global TrimStrings
  // middleware trims these before they ever reach validation server-side.
  const required = (v: string) => !!v?.trim() || t("validation.required");
  const maxLength = (max: number) => (v: string) =>
    (v?.trim().length ?? 0) <= max || t("validation.maxLength", { max });

  // Mirrors the length half of ImageValidationRules::title() in the backend —
  // change both together. The uniqueness half is deliberately not duplicated:
  // a title only has to be unique within its document, and the browser holds no
  // list of that document's other titles to check against. A collision comes
  // back as a 422 and lands on the field through TitleField's `error` prop.
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
