export const AUTH_UNAUTHORIZED_EVENT = "medisa:auth-unauthorized";
export const AUTH_FORBIDDEN_EVENT = "medisa:auth-forbidden";

export type AuthUnauthorizedDetail = {
  status: number;
  path: string;
};

type AuthUnauthorizedCallback = (detail: AuthUnauthorizedDetail) => void;
type AuthForbiddenCallback = (detail: AuthUnauthorizedDetail) => void;

export function emitAuthUnauthorized(detail: AuthUnauthorizedDetail) {
  if (typeof window === "undefined") {
    return;
  }

  window.dispatchEvent(new CustomEvent<AuthUnauthorizedDetail>(AUTH_UNAUTHORIZED_EVENT, { detail }));
}

export function onAuthUnauthorized(callback: AuthUnauthorizedCallback) {
  if (typeof window === "undefined") {
    return () => {};
  }

  const listener = (event: Event) => {
    const customEvent = event as CustomEvent<AuthUnauthorizedDetail>;
    callback(customEvent.detail);
  };

  window.addEventListener(AUTH_UNAUTHORIZED_EVENT, listener);

  return () => {
    window.removeEventListener(AUTH_UNAUTHORIZED_EVENT, listener);
  };
}

export function emitAuthForbidden(detail: AuthUnauthorizedDetail) {
  if (typeof window === "undefined") {
    return;
  }

  window.dispatchEvent(new CustomEvent<AuthUnauthorizedDetail>(AUTH_FORBIDDEN_EVENT, { detail }));
}

export function onAuthForbidden(callback: AuthForbiddenCallback) {
  if (typeof window === "undefined") {
    return () => {};
  }

  const listener = (event: Event) => {
    const customEvent = event as CustomEvent<AuthUnauthorizedDetail>;
    callback(customEvent.detail);
  };

  window.addEventListener(AUTH_FORBIDDEN_EVENT, listener);

  return () => {
    window.removeEventListener(AUTH_FORBIDDEN_EVENT, listener);
  };
}
