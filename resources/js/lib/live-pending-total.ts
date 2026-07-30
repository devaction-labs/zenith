/**
 * Live pending total from queue structures (reserved + ready + delayed).
 * Returns null when any component is unavailable so callers can display "—".
 */
export function livePendingTotal(
  reserved: number | null | undefined,
  ready: number | null | undefined,
  delayed: number | null | undefined,
): number | null {
  if (reserved == null || ready == null || delayed == null) {
    return null;
  }

  return reserved + ready + delayed;
}
