const fallbackBasePath = "/dakoku/admin";

function normalizeBasePath(value: string) {
  const trimmed = value.trim();
  if (trimmed === "" || trimmed === "/") {
    return "";
  }

  const withLeadingSlash = trimmed.startsWith("/") ? trimmed : `/${trimmed}`;
  return withLeadingSlash.replace(/\/+$/, "");
}

export const NEXT_PUBLIC_BASE_PATH = normalizeBasePath(process.env.NEXT_PUBLIC_BASE_PATH ?? fallbackBasePath);

export function withBasePath(path: string) {
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  return `${NEXT_PUBLIC_BASE_PATH}${normalizedPath}` || normalizedPath;
}
