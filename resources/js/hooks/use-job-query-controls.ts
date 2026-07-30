import { useCallback, useEffect, useRef, useState } from "react";

import type { JobFilterKey, JobFilterValues } from "@/types/jobs";

export function useJobQueryControls({
  query,
  filters,
  onSubmit,
}: {
  query: string;
  filters: JobFilterValues;
  onSubmit: (query: string, filters: JobFilterValues) => void;
}) {
  const [search, setSearchState] = useState(query);
  const [selectedFilters, setSelectedFilters] = useState(filters);
  const searchRef = useRef(query);
  const selectedFiltersRef = useRef(filters);
  const lastCommittedQueryRef = useRef(query);
  const lastCommittedFiltersRef = useRef(filters);
  const lastSubmittedStateRef = useRef(queryStateSignature(query, filters));

  useEffect(() => {
    if (searchRef.current.trim() === lastCommittedQueryRef.current) {
      searchRef.current = query;
      setSearchState(query);
    }

    lastCommittedQueryRef.current = query;
  }, [query]);

  useEffect(() => {
    const currentFilters = selectedFiltersRef.current;

    if (
      filtersEqual(currentFilters, lastCommittedFiltersRef.current) ||
      filtersEqual(currentFilters, filters)
    ) {
      selectedFiltersRef.current = filters;
      setSelectedFilters(filters);
    }

    lastCommittedFiltersRef.current = filters;
  }, [filters]);

  const submit = useCallback(
    (nextQuery: string, nextFilters: JobFilterValues) => {
      lastSubmittedStateRef.current = queryStateSignature(nextQuery, nextFilters);
      onSubmit(nextQuery, nextFilters);
    },
    [onSubmit],
  );

  useEffect(() => {
    const nextQuery = search.trim();
    const nextState = queryStateSignature(nextQuery, selectedFilters);

    if (
      nextState === queryStateSignature(query, filters) ||
      nextState === lastSubmittedStateRef.current
    ) {
      return;
    }

    const timer = window.setTimeout(() => {
      submit(nextQuery, selectedFilters);
    }, 500);

    return () => window.clearTimeout(timer);
  }, [filters, query, search, selectedFilters, submit]);

  const setSearch = useCallback((value: string) => {
    searchRef.current = value;
    setSearchState(value);
  }, []);

  const setFilterValue = useCallback(
    (filterKey: JobFilterKey, value: string | null) => {
      const nextFilters = {
        ...selectedFiltersRef.current,
        [filterKey]: value,
      };
      selectedFiltersRef.current = nextFilters;
      setSelectedFilters(nextFilters);
      submit(searchRef.current.trim(), nextFilters);
    },
    [submit],
  );

  const clearFilters = useCallback(() => {
    const nextFilters: JobFilterValues = {
      job: null,
      queue: null,
      connection: null,
      state: null,
    };
    selectedFiltersRef.current = nextFilters;
    setSelectedFilters(nextFilters);
    submit(searchRef.current.trim(), nextFilters);
  }, [submit]);

  return {
    search,
    setSearch,
    filters: selectedFilters,
    setFilterValue,
    clearFilters,
    isCommitted:
      queryStateSignature(search.trim(), selectedFilters) === queryStateSignature(query, filters),
  };
}

function filtersEqual(left: JobFilterValues, right: JobFilterValues): boolean {
  return (
    left.job === right.job &&
    left.queue === right.queue &&
    left.connection === right.connection &&
    left.state === right.state
  );
}

function queryStateSignature(query: string, filters: JobFilterValues): string {
  return JSON.stringify([query, filters.job, filters.queue, filters.connection, filters.state]);
}
