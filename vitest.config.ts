import path from "node:path";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite-plus";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(import.meta.dirname, "resources/js"),
    },
  },
  test: {
    environment: "jsdom",
    include: ["resources/js/**/*.test.{ts,tsx}"],
    setupFiles: ["resources/js/test/setup.ts"],
  },
});
