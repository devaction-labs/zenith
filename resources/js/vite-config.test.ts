// @vitest-environment node

import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vite-plus/test";

import viteConfig from "../../vite.config";

function flatPlugins(plugins: unknown): Array<{ name?: string; config?: Function }> {
  return (Array.isArray(plugins) ? plugins : []).flat(Infinity) as Array<{
    name?: string;
    config?: Function;
  }>;
}

describe("Vite package build", () => {
  const source = readFileSync(path.resolve(import.meta.dirname, "../../vite.config.ts"), "utf8");
  const plugins = flatPlugins(viteConfig.plugins);
  const laravelPlugin = plugins.find((plugin) => plugin.name === "laravel");
  const assetsPlugin = plugins.find((plugin) => plugin.name === "laravel:assets");
  const wayfinderPlugin = plugins.find(
    (plugin) => plugin.name === "@laravel/vite-plugin-wayfinder",
  );

  it("keeps a relative production base for published package asset URLs", () => {
    expect(viteConfig).toHaveProperty("base", "./");
  });

  it("builds into dist/build without an ambient Vite publicDir", () => {
    expect(viteConfig).not.toHaveProperty("publicDir");

    const resolved = laravelPlugin?.config?.(
      { base: "./" },
      { command: "build", mode: "production" },
    ) as { build?: { outDir?: string }; publicDir?: unknown };

    expect(resolved.build?.outDir).toBe("dist/build");
    expect(resolved.publicDir).toBe(false);
  });

  it("relies on laravel-vite-plugin defaults for buildDirectory and hotFile", () => {
    expect(source).not.toMatch(/buildDirectory\s*:/);
    expect(source).not.toMatch(/hotFile\s*:/);
    expect(source).toMatch(/publicDirectory:\s*"dist"/);
    expect(source).toMatch(/assets:\s*"resources\/images\/favicon\.svg"/);
  });

  it("registers the Laravel assets plugin for the Blade favicon source", () => {
    expect(assetsPlugin).toBeDefined();
  });

  it("keeps package Wayfinder generation without controller actions", () => {
    expect(wayfinderPlugin).toBeDefined();
    expect(source).toMatch(/actions:\s*false/);
    expect(source).toMatch(/command:\s*"node scripts\/generate-wayfinder\.mjs"/);
    expect(source).toMatch(/path:\s*"resources\/js\/generated"/);
  });

  it("does not declare a package build key or hand-written @ alias", () => {
    expect(viteConfig).not.toHaveProperty("build");
    expect(viteConfig).not.toHaveProperty("resolve");
  });
});
