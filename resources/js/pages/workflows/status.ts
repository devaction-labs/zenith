import type { VariantProps } from "class-variance-authority";

import type { badgeVariants } from "@/components/ui/badge";

type BadgeVariant = NonNullable<VariantProps<typeof badgeVariants>["variant"]>;

const statusVariants: Partial<Record<string, BadgeVariant>> = {
  pending: "secondary",
  dispatched: "delayed",
  running: "processing",
  retrying: "retry",
  completed: "success",
  failed: "destructive",
  cancelled: "outline",
};

export function workflowStatusVariant(status: string): BadgeVariant {
  return statusVariants[status] ?? "default";
}
