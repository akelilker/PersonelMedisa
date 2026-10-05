export type QrScanLocationCapture = {
  available: boolean;
  latitude?: number;
  longitude?: number;
  accuracy_meters?: number | null;
  error_code?: string;
};

const CAPTURE_TIMEOUT_MS = 5000;

export async function captureQrScanLocation(): Promise<QrScanLocationCapture> {
  if (typeof navigator === "undefined" || !navigator.geolocation) {
    return { available: false, error_code: "UNSUPPORTED" };
  }

  return new Promise((resolve) => {
    let settled = false;
    const finish = (payload: QrScanLocationCapture) => {
      if (settled) return;
      settled = true;
      resolve(payload);
    };
    const timer = window.setTimeout(
      () => finish({ available: false, error_code: "TIMEOUT" }),
      CAPTURE_TIMEOUT_MS
    );
    navigator.geolocation.getCurrentPosition(
      (position) => {
        window.clearTimeout(timer);
        finish({
          available: true,
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy_meters: Number.isFinite(position.coords.accuracy)
            ? Math.round(position.coords.accuracy * 100) / 100
            : null
        });
      },
      (error) => {
        window.clearTimeout(timer);
        finish({ available: false, error_code: String(error.code) });
      },
      { enableHighAccuracy: true, maximumAge: 0, timeout: CAPTURE_TIMEOUT_MS }
    );
  });
}
