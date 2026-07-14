import vuetify from "eslint-config-vuetify";
import eslintConfigPrettier from "eslint-config-prettier";

export default [
  ...vuetify({
    ts: true,
  }),
  eslintConfigPrettier,
];
