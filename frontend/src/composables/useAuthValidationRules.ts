import type { Ref } from "vue";
import { useI18n } from "vue-i18n";

export function useAuthValidationRules() {
  const { t } = useI18n();

  const required = (v: string) => !!v || t("validation.required");
  const minLength = (min: number) => (v: string) =>
    v.length >= min || t("validation.minLength", { min });
  const email = (v: string) =>
    /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/.test(v) || t("validation.emailInvalid");

  // Laravel's global TrimStrings middleware trims every field except the
  // password ones, so text rules measure the trimmed value to agree with what
  // the server will actually receive. Password rules below must not.
  const requiredTrimmed = (v: string) =>
    !!v?.trim() || t("validation.required");
  const minLengthTrimmed = (min: number) => (v: string) =>
    (v?.trim().length ?? 0) >= min || t("validation.minLength", { min });
  const maxLengthTrimmed = (max: number) => (v: string) =>
    (v?.trim().length ?? 0) <= max || t("validation.maxLength", { max });

  const nameRules = [requiredTrimmed, minLengthTrimmed(4), maxLengthTrimmed(255)];
  const emailRules = [requiredTrimmed, email, maxLengthTrimmed(255)];

  // Untrimmed on purpose: "  hunter2  " is ten characters to the backend.
  const passwordRules = [required, minLength(8)];

  const passwordConfirmationRules = (
    password: Ref<string> | (() => string),
  ) => [
    required,
    (v: string) => {
      const pw = typeof password === "function" ? password() : password.value;
      return v === pw || t("validation.passwordMismatch");
    },
  ];

  return {
    nameRules,
    emailRules,
    passwordRules,
    passwordConfirmationRules,
  };
}
