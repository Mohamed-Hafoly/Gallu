import { useI18n } from "vue-i18n";

export function useTeamValidationRules() {
  const { t } = useI18n();

  // Measured against the trimmed value, because Laravel's global TrimStrings
  // middleware trims these before they ever reach validation server-side.
  const required = (v: string) => !!v?.trim() || t("validation.required");
  const maxLength = (max: number) => (v: string) =>
    (v?.trim().length ?? 0) <= max || t("validation.maxLength", { max });

  // Mirrors backend/app/Http/Requests/StoreTeamRequest.php and
  // UpdateTeamRequest.php — change both together.
  const nameRules = [required, maxLength(40)];
  // Nullable server-side, so no `required` here.
  const descriptionRules = [maxLength(255)];

  return { nameRules, descriptionRules };
}
