import type { Ref } from "vue";
import { useI18n } from "vue-i18n";

export function useAuthValidationRules() {
  const { t } = useI18n();

  const required = (v: string) => !!v || t("validation.required");
  const minLength = (min: number) => (v: string) =>
    v.length >= min || t("validation.minLength", { min });
  const maxLength = (max: number) => (v: string) =>
    v.length <= max || t("validation.maxLength", { max });
  const email = (v: string) =>
    /^[^\s@]+@[^\s@][^\s.@]*\.[^\s@]+$/.test(v) || t("validation.emailInvalid");

  const nameRules = [required, minLength(4), maxLength(255)];
  const emailRules = [required, email, maxLength(255)];
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
