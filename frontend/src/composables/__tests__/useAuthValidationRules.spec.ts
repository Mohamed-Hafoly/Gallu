import { beforeEach, describe, expect, it } from "vitest";
import { ref } from "vue";
import { withSetup } from "@/__tests__/helpers/withSetup";
import { useAuthValidationRules } from "@/composables/useAuthValidationRules";
import i18n from "@/plugins/i18n";

function rules() {
  return withSetup(() => useAuthValidationRules());
}

function failures(list: ((v: string) => unknown)[], value: string) {
  return list.map((rule) => rule(value)).filter((r) => r !== true);
}

beforeEach(() => {
  i18n.global.locale.value = "en";
});

describe("emailRules", () => {
  it.each(["user@example.com", "a.b@sub.example.co"])("accepts %s", (email) => {
    expect(failures(rules().emailRules, email)).toHaveLength(0);
  });

  it.each(["", "notanemail", "no@domain", "@example.com", "a b@example.com"])(
    "rejects %s",
    (email) => {
      expect(failures(rules().emailRules, email)).not.toHaveLength(0);
    },
  );
});

describe("passwordRules — mirrors the backend 8-char minimum", () => {
  it("rejects a 7 char password but accepts 8", () => {
    expect(failures(rules().passwordRules, "a".repeat(7))).not.toHaveLength(0);
    expect(failures(rules().passwordRules, "a".repeat(8))).toHaveLength(0);
  });
});

describe("nameRules", () => {
  it("requires at least 4 chars and at most 255", () => {
    expect(failures(rules().nameRules, "abc")).not.toHaveLength(0);
    expect(failures(rules().nameRules, "abcd")).toHaveLength(0);
    expect(failures(rules().nameRules, "a".repeat(255))).toHaveLength(0);
    expect(failures(rules().nameRules, "a".repeat(256))).not.toHaveLength(0);
  });
});

describe("passwordConfirmationRules", () => {
  it("passes when the confirmation matches the source ref", () => {
    const password = ref("12345678");
    const confirm = rules().passwordConfirmationRules(password);

    expect(failures(confirm, "12345678")).toHaveLength(0);
  });

  it("fails when the confirmation differs", () => {
    const password = ref("12345678");
    const confirm = rules().passwordConfirmationRules(password);

    expect(failures(confirm, "87654321")).not.toHaveLength(0);
  });

  it("re-reads the source on each call, so later edits are respected", () => {
    const password = ref("12345678");
    const confirm = rules().passwordConfirmationRules(password);

    expect(failures(confirm, "12345678")).toHaveLength(0);

    password.value = "changed-after-the-fact";
    expect(failures(confirm, "12345678")).not.toHaveLength(0);
  });
});
