import eslintConfigPrettier from "eslint-config-prettier";
import vuetify from "eslint-config-vuetify";

export default vuetify(
  {
    ts: true,
  },
  eslintConfigPrettier,
);
