import { afterEach, describe, expect, it } from "vite-plus/test";

import { cspNonce } from "@/lib/csp-nonce";

describe("cspNonce", () => {
  afterEach(() => {
    document.head.innerHTML = "";
  });

  it("reads the nonce shared by the package root view", () => {
    document.head.innerHTML = '<meta name="csp-nonce" content="nonce-123">';

    expect(cspNonce()).toBe("nonce-123");
  });

  it("returns undefined when the host did not enable CSP nonces", () => {
    expect(cspNonce()).toBeUndefined();
  });
});
