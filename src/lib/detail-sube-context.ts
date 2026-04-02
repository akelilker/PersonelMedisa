import { ApiRequestError } from "../api/api-client";

/** Liste sayfasında location.state ile taşınan tek anahtar (ortak davranış). */
export const SUBE_DETAIL_REDIRECT_STATE_KEY = "subeDetayUyari";

export const SUBE_DETAIL_REDIRECT_MESSAGE =
  "Bu kayıt aktif şube bağlamında görüntülenemiyor. Listeye yönlendirildiniz.";

export function shouldRedirectDetailAfterSubeMismatch(error: unknown): boolean {
  return error instanceof ApiRequestError && (error.status === 404 || error.status === 403);
}
