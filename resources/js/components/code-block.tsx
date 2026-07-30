import type { ComponentProps } from "react";
import { CopyIcon } from "lucide-react";
import { toast } from "@/components/ui/toast";

import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { copyToClipboard } from "@/lib/clipboard";
import { cn } from "@/lib/utils";

type CodeBlockProps = ComponentProps<"pre"> & {
  copyLabel?: string;
  copyValue?: string;
};

export function CodeBlock({
  className,
  copyLabel = "content",
  copyValue,
  ...props
}: CodeBlockProps) {
  const copy = async () => {
    if (copyValue === undefined) {
      return;
    }

    if (await copyToClipboard(copyValue)) {
      toast.add({ title: "Copied to clipboard.", type: "success" });

      return;
    }

    toast.add({ title: "Content could not be copied.", type: "error" });
  };

  return (
    <div className="relative" data-code-theme="dark">
      {copyValue !== undefined ? (
        <Tooltip>
          <TooltipTrigger
            render={
              <Button
                type="button"
                variant="code"
                size="icon-sm"
                className="absolute top-3 right-3 z-10"
                aria-label={`Copy ${copyLabel}`}
                onClick={() => void copy()}
              />
            }
          >
            <CopyIcon aria-hidden="true" />
          </TooltipTrigger>
          <TooltipContent side="left">Copy {copyLabel}</TooltipContent>
        </Tooltip>
      ) : null}
      <pre
        {...props}
        className={cn(
          "m-0 overflow-auto bg-code px-4 py-4 font-mono text-[12.5px] leading-6 font-medium whitespace-pre text-code-foreground sm:px-6",
          className,
        )}
      />
    </div>
  );
}
