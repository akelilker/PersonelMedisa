import type { ApiResponse } from "../types/api";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type PersonelActivationStatusResult = {
  valid: boolean;
  reason?: string;
  expires_at_utc?: string;
};

export type PersonelActivationCompleteResult = {
  activated: boolean;
  message?: string;
};

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }
  return value as Record<string, unknown>;
}

function readString(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }
  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : undefined;
}

function readBoolean(value: unknown): boolean {
  return value === true || value === 1 || value === "1" || value === "true";
}

export async function fetchPersonelActivationStatus(
  token: string
): Promise<PersonelActivationStatusResult> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.auth.personelActivationStatus, {
    method: "POST",
    body: JSON.stringify({ token })
  });
  const record = toRecord(response.data) ?? toRecord(response);
  if (!record) {
    return { valid: false, reason: "invalid" };
  }
  return {
    valid: readBoolean(record.valid),
    reason: readString(record.reason),
    expires_at_utc: readString(record.expires_at_utc)
  };
}

export async function completePersonelActivation(input: {
  token: string;
  newPassword: string;
  newPasswordConfirmation: string;
}): Promise<PersonelActivationCompleteResult> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.auth.personelActivationComplete,
    {
      method: "POST",
      body: JSON.stringify({
        token: input.token,
        new_password: input.newPassword,
        new_password_confirmation: input.newPasswordConfirmation
      })
    }
  );
  const record = toRecord(response.data) ?? toRecord(response);
  if (!record) {
    throw new Error("Aktivasyon yaniti beklenen formatta degil.");
  }
  return {
    activated: readBoolean(record.activated),
    message: readString(record.message)
  };
}
