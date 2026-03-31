import type { ApiResponse } from "../types/api";
import type { AuthSession, LoginCredentials } from "../types/auth";
import { apiRequest, ApiRequestError } from "./client";
import { endpoints } from "./endpoints";

function isAuthSession(value: unknown): value is AuthSession {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const session = value as Partial<AuthSession>;
  return (
    typeof session.token === "string" &&
    typeof session.ui_profile === "string" &&
    typeof session.user === "object" &&
    session.user !== null &&
    typeof session.user.id === "number" &&
    typeof session.user.ad_soyad === "string" &&
    typeof session.user.rol === "string"
  );
}

export async function login(credentials: LoginCredentials): Promise<AuthSession> {
  const response = await apiRequest<ApiResponse<AuthSession>>(endpoints.auth.login, {
    method: "POST",
    body: JSON.stringify(credentials)
  });

  if (!isAuthSession(response.data)) {
    throw new ApiRequestError("Login yaniti beklenen oturum formatinda degil.", 200);
  }

  return response.data;
}
