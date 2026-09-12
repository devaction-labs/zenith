import { usePage } from "@inertiajs/react";

import type { HorizonPageProps } from "@/types/page";

const allowAll = {
  pauseQueues: true,
  clearQueues: true,
  retryJobs: true,
  cancelJobs: true,
  manageInstances: true,
  manageMonitoring: true,
  manageBatches: true,
  manageSchedule: true,
  manageWorkflows: true,
};

export function useHorizonAbilities() {
  const abilities = usePage<HorizonPageProps>().props.horizon.abilities;

  return {
    ...allowAll,
    ...abilities,
  };
}
