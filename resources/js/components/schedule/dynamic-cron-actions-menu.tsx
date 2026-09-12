import { router } from "@inertiajs/react";
import { LoaderCircleIcon, PauseIcon, PencilIcon, PlayIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

import { ActionMenuTrigger } from "@/components/ui/action-menu-trigger";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem } from "@/components/ui/dropdown-menu";
import { destroy as deleteDynamicCron } from "@/generated/routes/zenith/schedule/dynamic-crons";
import {
  destroy as resumeDynamicCron,
  store as pauseDynamicCron,
} from "@/generated/routes/zenith/schedule/dynamic-crons/pause";
import { resolveHorizonRoute } from "@/lib/horizon-route";

export function DynamicCronActionsMenu({
  name,
  cronId,
  paused,
  horizonBaseUrl,
  onEdit,
}: {
  name: string;
  cronId: number;
  paused: boolean;
  horizonBaseUrl: string;
  onEdit: () => void;
}) {
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [working, setWorking] = useState(false);
  const options = {
    preserveScroll: true,
    onStart: () => setWorking(true),
    onFinish: () => setWorking(false),
  };

  const togglePause = () => {
    if (paused) {
      router.delete(resolveHorizonRoute(resumeDynamicCron(cronId), horizonBaseUrl).url, options);

      return;
    }

    router.post(resolveHorizonRoute(pauseDynamicCron(cronId), horizonBaseUrl).url, {}, options);
  };

  const confirmDelete = () => {
    router.delete(resolveHorizonRoute(deleteDynamicCron(cronId), horizonBaseUrl).url, {
      ...options,
      onSuccess: () => setConfirmingDelete(false),
    });
  };

  return (
    <>
      <DropdownMenu>
        <ActionMenuTrigger available label={`${name} cron actions`} working={working} />
        <DropdownMenuContent align="end" className="w-44">
          <DropdownMenuItem onSelect={onEdit}>
            <PencilIcon />
            Edit
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={togglePause}>
            {paused ? <PlayIcon /> : <PauseIcon />}
            {paused ? "Resume" : "Pause"}
          </DropdownMenuItem>
          <DropdownMenuItem variant="destructive" onSelect={() => setConfirmingDelete(true)}>
            <Trash2Icon />
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete {name}?</DialogTitle>
            <DialogDescription>
              This permanently removes the dynamic cron. It will not run again unless recreated.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <DialogClose render={<Button type="button" variant="ghost" disabled={working} />}>
              Cancel
            </DialogClose>
            <Button type="button" variant="destructive" disabled={working} onClick={confirmDelete}>
              {working ? <LoaderCircleIcon className="animate-spin" /> : <Trash2Icon />}
              {working ? "Deleting…" : "Delete"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
