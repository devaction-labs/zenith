import { Link, router } from "@inertiajs/react";
import { SearchIcon, XIcon } from "lucide-react";

import { ResponsiveTabsHeader } from "@/components/responsive-tabs-header";
import { ListPageHeader } from "@/components/shell/list-page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Field, FieldLabel } from "@/components/ui/field";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupInput,
} from "@/components/ui/input-group";
import { Tabs, TabsContent } from "@/components/ui/tabs";
import { formatCount } from "@/lib/format-count";

export type TabbedResultsTab<Tab extends string> = {
  count?: number | null;
  href: string;
  label: string;
  preserveState?: boolean;
  value: Tab;
};

export function TabbedResultsCard<Tab extends string>({
  title,
  activeTab,
  tabs,
  tabListLabel,
  contentHeading,
  search,
  searchLabel,
  searchPlaceholder,
  onSearchChange,
  filters,
  actions,
  children,
}: {
  title: string;
  activeTab: Tab;
  tabs: readonly TabbedResultsTab<Tab>[];
  tabListLabel: string;
  contentHeading: string;
  search?: string;
  searchLabel?: string;
  searchPlaceholder?: string;
  onSearchChange?: (value: string) => void;
  filters?: React.ReactNode;
  actions?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <TabbedResultsLayout
      title={title}
      activeTab={activeTab}
      tabs={tabs}
      tabListLabel={tabListLabel}
      actions={actions}
    >
      <TabbedResultsContent
        activeTab={activeTab}
        contentHeading={contentHeading}
        search={search}
        searchLabel={searchLabel}
        searchPlaceholder={searchPlaceholder}
        onSearchChange={onSearchChange}
        filters={filters}
      >
        {children}
      </TabbedResultsContent>
    </TabbedResultsLayout>
  );
}

export function TabbedResultsLayout<Tab extends string>({
  title,
  activeTab,
  tabs,
  tabListLabel,
  actions,
  children,
}: {
  title: string;
  activeTab: Tab;
  tabs: readonly TabbedResultsTab<Tab>[];
  tabListLabel: string;
  actions?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <Card>
      <ListPageHeader title={title} actions={actions} separated={false} />
      <CardContent className="p-0">
        <Tabs value={activeTab} className="gap-0">
          <ResponsiveTabsHeader
            value={activeTab}
            items={tabs.map((tab) => ({
              value: tab.value,
              label: tab.label,
              count:
                tab.count !== null && tab.count !== undefined ? formatCount(tab.count) : undefined,
              render: (
                <Link
                  href={tab.href}
                  prefetch
                  preserveScroll
                  preserveState={tab.preserveState}
                  aria-current={tab.value === activeTab ? "page" : undefined}
                />
              ),
            }))}
            ariaLabel={tabListLabel}
            separatedFromHeader
            onValueChange={(value) => {
              const tab = tabs.find((item) => item.value === value);

              if (!tab || tab.value === activeTab) {
                return;
              }

              router.visit(tab.href, {
                preserveScroll: true,
                preserveState: tab.preserveState,
              });
            }}
            className="w-full"
            triggerClassName="pt-0 pb-3"
          />

          {children}
        </Tabs>
      </CardContent>
    </Card>
  );
}

export function TabbedResultsContent<Tab extends string>({
  activeTab,
  children,
  ...props
}: {
  activeTab: Tab;
  contentHeading: string;
  search?: string;
  searchLabel?: string;
  searchPlaceholder?: string;
  onSearchChange?: (value: string) => void;
  filters?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <TabsContent value={activeTab}>
      <TabbedResultsBody {...props}>{children}</TabbedResultsBody>
    </TabsContent>
  );
}

export function TabbedResultsBody({
  contentHeading,
  search,
  searchLabel,
  searchPlaceholder,
  onSearchChange,
  filters,
  children,
}: {
  contentHeading: string;
  search?: string;
  searchLabel?: string;
  searchPlaceholder?: string;
  onSearchChange?: (value: string) => void;
  filters?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <>
      {search !== undefined || filters ? (
        <div className="flex min-h-10 items-center border-y border-separator py-1.5 pr-2.5 pl-4 sm:px-6">
          {search !== undefined &&
          searchLabel !== undefined &&
          searchPlaceholder !== undefined &&
          onSearchChange !== undefined ? (
            <Field className="min-w-0 flex-1">
              <FieldLabel className="sr-only">{searchLabel}</FieldLabel>
              <InputGroup className="gap-1.5 border-0 bg-transparent! shadow-none has-[[data-slot=input-group-control]:focus-visible]:ring-0!">
                <InputGroupInput
                  className="px-0!"
                  type="text"
                  role="searchbox"
                  inputMode="search"
                  value={search}
                  aria-label={searchLabel}
                  placeholder={searchPlaceholder}
                  onChange={(event) => onSearchChange(event.target.value)}
                />
                <InputGroupAddon align="inline-start" className="pl-0">
                  <SearchIcon aria-hidden="true" />
                </InputGroupAddon>
                {search ? (
                  <InputGroupAddon align="inline-end" className="py-0 pr-0">
                    <InputGroupButton
                      size="icon-xs"
                      aria-label="Clear search"
                      onClick={() => onSearchChange("")}
                    >
                      <XIcon />
                    </InputGroupButton>
                  </InputGroupAddon>
                ) : null}
              </InputGroup>
            </Field>
          ) : (
            <div className="min-w-0 flex-1" />
          )}
          {filters ? (
            <div className="flex min-h-8 shrink-0 items-center justify-end">{filters}</div>
          ) : null}
        </div>
      ) : null}

      <h2 className="sr-only">{contentHeading}</h2>
      {children}
    </>
  );
}
