import { router } from "@inertiajs/react";
import { LoaderCircleIcon, RotateCcwIcon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { store as retryRetainedJob } from "@/generated/routes/zenith/jobs/retry";
import { useHorizonAbilities } from "@/hooks/use-horizon-abilities";
import { resolveHorizonRoute } from "@/lib/horizon-route";

export function RetryRetainedJobButton({
  type,
  jobId,
  horizonBaseUrl,
}: {
  type: "completed" | "silenced";
  jobId: string;
  horizonBaseUrl: string;
}) {
  const abilities = useHorizonAbilities();
  const [working, setWorking] = useState(false);

  const retry = () => {
    const route = resolveHorizonRoute(retryRetainedJob({ type, job: jobId }), horizonBaseUrl);

    router.post(
      route.url,
      {},
      {
        preserveScroll: true,
        onStart: () => setWorking(true),
        onFinish: () => setWorking(false),
      },
    );
  };

  return (
    <Button
      type="button"
      disabled={working || !abilities.retryJobs}
      aria-label={working ? "Retrying job" : "Retry job"}
      onClick={retry}
    >
      {working ? <LoaderCircleIcon className="animate-spin" /> : <RotateCcwIcon />}
      <span>{working ? "Retrying" : "Retry"}</span>
    </Button>
  );
}
