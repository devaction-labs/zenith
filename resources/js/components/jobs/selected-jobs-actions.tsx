import { router } from "@inertiajs/react";
import { BanIcon, LoaderCircleIcon, RotateCcwIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";

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
import { destroy as cancelSelectedPendingJobs } from "@/generated/routes/zenith/jobs/pending/cancel-selected";
import { destroy as removeSelectedFailedJobs } from "@/generated/routes/zenith/failed-jobs/selected";
import { store as retrySelectedFailedJobs } from "@/generated/routes/zenith/failed-jobs/retry-selected";
import { useHorizonAbilities } from "@/hooks/use-horizon-abilities";
import { resolveHorizonRoute } from "@/lib/horizon-route";

export function CancelSelectedPendingJobsButton({
  horizonBaseUrl,
  ids,
  onDone,
}: {
  horizonBaseUrl: string;
  ids: readonly string[];
  onDone: () => void;
}) {
  const abilities = useHorizonAbilities();
  const [working, setWorking] = useState(false);

  const cancel = () => {
    const route = resolveHorizonRoute(cancelSelectedPendingJobs(), horizonBaseUrl);

    router.delete(route.url, {
      data: { ids: Array.from(ids) },
      preserveScroll: true,
      onStart: () => setWorking(true),
      onSuccess: onDone,
      onFinish: () => setWorking(false),
    });
  };

  return (
    <Button
      type="button"
      variant="destructive"
      size="sm"
      disabled={working || ids.length === 0 || !abilities.cancelJobs}
      onClick={cancel}
    >
      {working ? <LoaderCircleIcon className="animate-spin" /> : <BanIcon />}
      {working ? "Cancelling…" : "Cancel selected"}
    </Button>
  );
}

export function RetrySelectedFailedJobsButton({
  horizonBaseUrl,
  ids,
  onDone,
}: {
  horizonBaseUrl: string;
  ids: readonly string[];
  onDone: () => void;
}) {
  const abilities = useHorizonAbilities();
  const [working, setWorking] = useState(false);

  const retry = () => {
    const route = resolveHorizonRoute(retrySelectedFailedJobs(), horizonBaseUrl);

    router.post(
      route.url,
      { ids: Array.from(ids) },
      {
        preserveScroll: true,
        onStart: () => setWorking(true),
        onSuccess: onDone,
        onFinish: () => setWorking(false),
      },
    );
  };

  return (
    <Button
      type="button"
      size="sm"
      disabled={working || ids.length === 0 || !abilities.retryJobs}
      onClick={retry}
    >
      {working ? <LoaderCircleIcon className="animate-spin" /> : <RotateCcwIcon />}
      {working ? "Retrying…" : "Retry selected"}
    </Button>
  );
}

export function RemoveSelectedFailedJobsButton({
  horizonBaseUrl,
  ids,
  onDone,
}: {
  horizonBaseUrl: string;
  ids: readonly string[];
  onDone: () => void;
}) {
  const abilities = useHorizonAbilities();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [working, setWorking] = useState(false);

  const remove = () => {
    const route = resolveHorizonRoute(removeSelectedFailedJobs(), horizonBaseUrl);

    router.delete(route.url, {
      data: { ids: Array.from(ids) },
      preserveScroll: true,
      onStart: () => setWorking(true),
      onSuccess: () => {
        setDialogOpen(false);
        onDone();
      },
      onFinish: () => setWorking(false),
    });
  };

  return (
    <>
      <Button
        type="button"
        variant="destructive"
        size="sm"
        disabled={ids.length === 0 || !abilities.clearQueues}
        onClick={() => setDialogOpen(true)}
      >
        <Trash2Icon />
        Remove selected
      </Button>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Remove {ids.length} selected failed jobs?</DialogTitle>
            <DialogDescription>
              Permanently remove these failed job records from Horizon and Laravel’s failed-job
              storage. This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <DialogClose render={<Button type="button" variant="ghost" disabled={working} />}>
              Cancel
            </DialogClose>
            <Button type="button" variant="destructive" disabled={working} onClick={remove}>
              {working ? <LoaderCircleIcon className="animate-spin" /> : <Trash2Icon />}
              {working ? "Removing…" : "Remove selected"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
