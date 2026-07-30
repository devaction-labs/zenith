import "../css/app.css";

import { createInertiaApp, router } from "@inertiajs/react";
import { JobsLayout } from "@/components/jobs/jobs-page";
import { TooltipProvider } from "@/components/ui/tooltip";
import { Toaster } from "@/components/ui/toast";
import { ColorSchemeProvider } from "@/hooks/use-color-scheme";
import { HorizonLayout } from "@/layouts/horizon-layout";
import { recoverFromAssetVersionChange } from "@/lib/asset-version-recovery";
import { cspNonce } from "@/lib/csp-nonce";
import {
  backgroundVisitOptions,
  registerForegroundVisitCancellation,
} from "@/lib/inertia-request-coordination";
import { pageModulePath } from "@/lib/page-name";
import { recoverFromPreloadError } from "@/lib/preload-error-recovery";
import { StrictMode, type ComponentType } from "react";
import { createRoot } from "react-dom/client";

window.addEventListener("vite:preloadError", (event) => {
  recoverFromPreloadError(event, () => window.location.reload());
});

router.on("location", (event) =>
  recoverFromAssetVersionChange(event, () => {
    window.setTimeout(() => window.location.reload(), 0);
  }),
);

registerForegroundVisitCancellation();

const jobsPageLayouts = [HorizonLayout, JobsLayout];

void createInertiaApp({
  dev: document.querySelector("script[data-inertia-devtools-id]") !== null,
  nonce: cspNonce(),
  defaults: {
    visitOptions: backgroundVisitOptions,
  },
  title: (title) => title,
  progress: {
    color: "var(--primary)",
  },
  layout: (name) =>
    name === "Jobs/Index" || name === "FailedJobs/Index" ? jobsPageLayouts : HorizonLayout,
  resolve: async (name) => {
    const pages = import.meta.glob<{ default: ComponentType<any> }>("./pages/**/*.tsx");
    const page = pages[pageModulePath(name)];

    if (!page) {
      throw new Error(`Unknown Inertia page: ${name}`);
    }

    return (await page()).default;
  },
  setup({ el, App, props }) {
    if (!el) {
      throw new Error("Inertia mount element is missing.");
    }

    createRoot(el).render(
      <StrictMode>
        <ColorSchemeProvider>
          <TooltipProvider>
            <Toaster />
            <App {...props} />
          </TooltipProvider>
        </ColorSchemeProvider>
      </StrictMode>,
    );
  },
});
