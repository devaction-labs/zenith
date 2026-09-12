import { router } from "@inertiajs/react";
import { LoaderCircleIcon, PauseIcon, PlayIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { ActionMenuTrigger } from "@/components/ui/action-menu-trigger";
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
import { destroy as clearAllQueues } from "@/generated/routes/zenith/queues/clear-all";
import {
  destroy as resumeAllQueues,
  store as pauseAllQueues,
} from "@/generated/routes/zenith/queues/pause-all";
import { useHorizonAbilities } from "@/hooks/use-horizon-abilities";
import { resolveHorizonRoute } from "@/lib/horizon-route";

type QueuesActionDialog = "clear" | "pause-all" | "resume-all";

export function QueuesActions({
  horizonBaseUrl,
  queueCount,
  pendingJobs,
  allPaused = false,
  queuePausingAll = false,
}: {
  horizonBaseUrl: string;
  queueCount: number;
  pendingJobs: number;
  allPaused?: boolean;
  queuePausingAll?: boolean;
}) {
  const abilities = useHorizonAbilities();
  const [dialog, setDialog] = useState<QueuesActionDialog | null>(null);
  const [working, setWorking] = useState(false);
  const canPauseAll = queuePausingAll && abilities.pauseQueues;
  const hasActions = (pendingJobs > 0 && abilities.clearQueues) || canPauseAll;

  const options = {
    preserveScroll: true,
    onStart: () => setWorking(true),
    onSuccess: () => setDialog(null),
    onFinish: () => setWorking(false),
  };

  const pauseAll = () => {
    router.post(resolveHorizonRoute(pauseAllQueues(), horizonBaseUrl).url, {}, options);
  };

  const resumeAll = () => {
    router.delete(resolveHorizonRoute(resumeAllQueues(), horizonBaseUrl).url, options);
  };

  const clear = () => {
    router.delete(resolveHorizonRoute(clearAllQueues(), horizonBaseUrl).url, options);
  };

  return (
    <>
      <DropdownMenu>
        <ActionMenuTrigger available={hasActions} label="Queue list actions" working={working} />
        <DropdownMenuContent align="end" className="w-52">
          {canPauseAll && allPaused ? (
            <DropdownMenuItem onSelect={() => setDialog("resume-all")}>
              <PlayIcon />
              Resume all queues
            </DropdownMenuItem>
          ) : null}
          {canPauseAll && !allPaused ? (
            <DropdownMenuItem onSelect={() => setDialog("pause-all")}>
              <PauseIcon />
              Pause all queues
            </DropdownMenuItem>
          ) : null}
          <DropdownMenuItem
            variant="destructive"
            disabled={pendingJobs === 0}
            onSelect={() => setDialog("clear")}
          >
            <Trash2Icon />
            Clear all queues
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <Dialog
        open={dialog === "pause-all"}
        onOpenChange={(open) => setDialog(open ? "pause-all" : null)}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Pause all queues?</DialogTitle>
            <DialogDescription>
              Stop workers from reserving jobs on every connection. Individually paused queues stay
              paused after a global resume.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <DialogClose render={<Button type="button" variant="ghost" disabled={working} />}>
              Cancel
            </DialogClose>
            <Button type="button" disabled={working} onClick={pauseAll}>
              {working ? <LoaderCircleIcon className="animate-spin" /> : <PauseIcon />}
              {working ? "Pausing…" : "Pause all queues"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={dialog === "resume-all"}
        onOpenChange={(open) => setDialog(open ? "resume-all" : null)}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Resume all queues?</DialogTitle>
            <DialogDescription>
              This only clears Laravel&apos;s global pause. Queues you paused individually remain
              paused.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <DialogClose render={<Button type="button" variant="ghost" disabled={working} />}>
              Cancel
            </DialogClose>
            <Button type="button" disabled={working} onClick={resumeAll}>
              {working ? <LoaderCircleIcon className="animate-spin" /> : <PlayIcon />}
              {working ? "Resuming…" : "Resume all queues"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={dialog === "clear"} onOpenChange={(open) => setDialog(open ? "clear" : null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Clear all queues?</DialogTitle>
            <DialogDescription>
              Permanently delete all pending, delayed, and reserved jobs from {queueCount}{" "}
              supervised
              {queueCount === 1 ? " queue" : " queues"}. Jobs already processing may still finish.
              This cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <DialogClose render={<Button type="button" variant="ghost" disabled={working} />}>
              Cancel
            </DialogClose>
            <Button type="button" variant="destructive" disabled={working} onClick={clear}>
              {working ? <LoaderCircleIcon className="animate-spin" /> : <Trash2Icon />}
              {working ? "Clearing…" : "Clear all queues"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
