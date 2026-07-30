export function cspNonce(): string | undefined {
  return document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content || undefined;
}
