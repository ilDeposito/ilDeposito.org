/// <reference path="../.astro/types.d.ts" />

// Import di file YAML tramite @rollup/plugin-yaml (default export = oggetto parsato).
declare module '*.yaml' {
  const data: Record<string, unknown>;
  export default data;
}
