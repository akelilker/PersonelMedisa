type QueryValue = string | number | boolean | null | undefined;

function isEmptyValue(value: QueryValue) {
  return value === undefined || value === null || value === "";
}

export function appendQueryParams(path: string, query: Record<string, QueryValue>) {
  const params = new URLSearchParams();

  Object.entries(query).forEach(([key, value]) => {
    if (isEmptyValue(value)) {
      return;
    }

    params.set(key, String(value));
  });

  const queryString = params.toString();
  if (!queryString) {
    return path;
  }

  return `${path}?${queryString}`;
}
