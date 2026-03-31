let resolveToken: () => string | null = () => null;

export function registerAuthTokenSource(getter: () => string | null): void {
  resolveToken = getter;
}

export function getAuthTokenForApi(): string | null {
  return resolveToken();
}
