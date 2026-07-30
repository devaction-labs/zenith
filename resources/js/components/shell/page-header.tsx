import { RefreshCwIcon } from "lucide-react";

import {
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@/components/ui/sidebar";
import { Switch } from "@/components/ui/switch";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { ThemeToggle } from "@/components/shell/theme-toggle";
import { useAutoRefreshStatus } from "@/lib/auto-refresh-status";
import { cn } from "@/lib/utils";
import type { HorizonStatus as HorizonStatusValue } from "@/types/dashboard";

const autoRefreshLabel = "Auto load new entries";
const autoRefreshFailedLabel = "Auto refresh failed; retrying automatically";
const autoRefreshFailedDescription =
  "The last automatic refresh failed. Horizon will keep retrying at the normal interval.";

export function SidebarFooterControls({
  status,
  autoLoad,
  onAutoLoadChange,
}: {
  status: HorizonStatusValue;
  autoLoad: boolean;
  onAutoLoadChange: (enabled: boolean) => void;
}) {
  const refreshStatus = useAutoRefreshStatus();
  const visibleStatus = autoLoad ? refreshStatus : "idle";
  const isRefreshing = visibleStatus === "refreshing";
  const isFailed = visibleStatus === "failed";
  const label = isFailed ? autoRefreshFailedLabel : autoRefreshLabel;

  const button = (
    <SidebarMenuButton
      className="h-9 gap-2.5 rounded-lg px-2.5 pr-14"
      aria-label={label}
      aria-pressed={autoLoad}
      aria-busy={isRefreshing}
      aria-description={isFailed ? autoRefreshFailedDescription : undefined}
      data-refresh-status={visibleStatus}
      onClick={() => onAutoLoadChange(!autoLoad)}
    >
      <RefreshCwIcon
        className={cn(
          "text-muted-foreground",
          isRefreshing && "animate-spin motion-reduce:animate-none",
          isFailed && "text-destructive",
        )}
        style={{ rotate: "0turn" }}
        aria-hidden="true"
      />
      <span>Auto refresh</span>
    </SidebarMenuButton>
  );

  return (
    <SidebarGroup className="p-0" data-horizon-status={status}>
      <SidebarGroupLabel className="px-2">Settings</SidebarGroupLabel>
      <SidebarGroupContent>
        <SidebarMenu className="gap-1.5">
          <SidebarMenuItem>
            <ThemeToggle showTooltip={false} variant="menu" />
          </SidebarMenuItem>
          <SidebarMenuItem>
            {isFailed ? (
              <Tooltip>
                <TooltipTrigger render={button} />
                <TooltipContent side="right" sideOffset={8}>
                  {autoRefreshFailedDescription}
                </TooltipContent>
              </Tooltip>
            ) : (
              button
            )}
            <Switch
              checked={autoLoad}
              className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2"
              aria-hidden="true"
              tabIndex={-1}
            />
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarGroupContent>
    </SidebarGroup>
  );
}

export { SidebarFooterControls as PageHeader };
