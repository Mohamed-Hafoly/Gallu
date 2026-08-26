import { useI18n } from "vue-i18n";

export function useDocumentValidationRules() {
  const { t } = useI18n();

  // Measured against the trimmed value, because Laravel's global TrimStrings
  // middleware trims these before they ever reach validation server-side.
  const required = (v: string) => !!v?.trim() || t("validation.required");
  const maxLength = (max: number) => (v: string) =>
    (v?.trim().length ?? 0) <= max || t("validation.maxLength", { max });

  // Mirrors backend/app/Http/Requests/StoreDocumentRequest.php and
  // UpdateDocumentRequest.php — change both together. Note the limits are the
  // document's own, not the team's 40/255.
  const titleRules = [required, maxLength(140)];
  // Nullable server-side, so no `required` here.
  const descriptionRules = [maxLength(400)];
  // Required server-side, and there is no "no team" option to fall back on —
  // the select's own value, not a string, so it needs its own rule.
  const teamRules = [
    (v: number | null) => v !== null || t("validation.required"),
  ];

  return { titleRules, descriptionRules, teamRules };
}
