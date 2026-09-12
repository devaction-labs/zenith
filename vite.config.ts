import { wayfinder } from "@laravel/vite-plugin-wayfinder";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import laravel from "laravel-vite-plugin";
import { defineConfig } from "vite-plus";

export default defineConfig({
  lint: {
    ignorePatterns: ["resources/js/generated/**"],
    options: {
      typeAware: true,
      typeCheck: true,
    },
  },
  fmt: {
    ignorePatterns: ["resources/js/generated"],
  },
  // Relative base keeps CSS/font/chunk URLs valid after publish under vendor/zenith/build.
  base: "./",
  plugins: [
    laravel({
      input: "resources/js/app.tsx",
      publicDirectory: "dist",
      assets: "resources/images/favicon.svg",
    }),
    wayfinder({
      actions: false,
      command: "node scripts/generate-wayfinder.mjs",
      path: "resources/js/generated",
      patterns: ["src/**/*.php", "vendor/laravel/horizon/routes/**/*.php"],
    }),
    react(),
    tailwindcss(),
  ],
});
