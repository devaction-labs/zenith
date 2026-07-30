import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

export type ColorScheme = "system" | "dark" | "light";
export type ResolvedColorScheme = Exclude<ColorScheme, "system">;

type ColorSchemeContextValue = {
  scheme: ColorScheme;
  resolvedScheme: ResolvedColorScheme;
  cycle: () => void;
};

const storageKey = "horizonColorScheme";
const darkModeQuery = "(prefers-color-scheme: dark)";
const schemes: ColorScheme[] = ["system", "dark", "light"];

const defaultColorScheme: ColorSchemeContextValue = {
  scheme: "system",
  resolvedScheme: "light",
  cycle: () => {},
};

const ColorSchemeContext = createContext<ColorSchemeContextValue>(defaultColorScheme);

function storedScheme(): ColorScheme {
  try {
    const value = window.localStorage.getItem(storageKey);

    return value === "dark" || value === "light" || value === "system" ? value : "system";
  } catch {
    return "system";
  }
}

function storeScheme(scheme: ColorScheme) {
  try {
    window.localStorage.setItem(storageKey, scheme);
  } catch {
    // The current session can still use the selected scheme when storage is unavailable.
  }
}

function systemScheme(): ResolvedColorScheme {
  return window.matchMedia(darkModeQuery).matches ? "dark" : "light";
}

function applyScheme(scheme: ResolvedColorScheme) {
  document.documentElement.classList.toggle("dark", scheme === "dark");
  document.documentElement.style.colorScheme = scheme;
}

export function ColorSchemeProvider({ children }: { children: ReactNode }) {
  const [scheme, setScheme] = useState<ColorScheme>(storedScheme);
  const [systemPreference, setSystemPreference] = useState<ResolvedColorScheme>(systemScheme);
  const resolvedScheme = scheme === "system" ? systemPreference : scheme;

  useEffect(() => {
    const media = window.matchMedia(darkModeQuery);
    const handleChange = (event: MediaQueryListEvent) => {
      setSystemPreference(event.matches ? "dark" : "light");
    };

    media.addEventListener("change", handleChange);

    return () => media.removeEventListener("change", handleChange);
  }, []);

  useEffect(() => {
    storeScheme(scheme);
    applyScheme(resolvedScheme);
  }, [resolvedScheme, scheme]);

  const cycle = useCallback(() => {
    setScheme((current) => schemes[(schemes.indexOf(current) + 1) % schemes.length]);
  }, []);

  const value = useMemo(() => ({ scheme, resolvedScheme, cycle }), [cycle, resolvedScheme, scheme]);

  return <ColorSchemeContext.Provider value={value}>{children}</ColorSchemeContext.Provider>;
}

export function useColorScheme(): ColorSchemeContextValue {
  return useContext(ColorSchemeContext);
}

export function useResolvedColorScheme(): ResolvedColorScheme {
  return useColorScheme().resolvedScheme;
}
