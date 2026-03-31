export const API_SERVER_ERROR_EVENT = "medisa:api-server-error";

export type ApiServerErrorDetail = {
  message: string;
  status: number;
};

export function emitApiServerError(detail: ApiServerErrorDetail): void {
  if (typeof window === "undefined") {
    return;
  }

  window.dispatchEvent(new CustomEvent<ApiServerErrorDetail>(API_SERVER_ERROR_EVENT, { detail }));
}

export function onApiServerError(handler: (detail: ApiServerErrorDetail) => void): () => void {
  if (typeof window === "undefined") {
    return () => undefined;
  }

  const listener = (event: Event) => {
    const custom = event as CustomEvent<ApiServerErrorDetail>;
    if (custom.detail) {
      handler(custom.detail);
    }
  };

  window.addEventListener(API_SERVER_ERROR_EVENT, listener);
  return () => {
    window.removeEventListener(API_SERVER_ERROR_EVENT, listener);
  };
}
