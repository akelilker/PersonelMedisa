export type QrScanResult = {
  rawValue: string;
};

export type QrScannerHandle = {
  stop: () => void;
};

type StartOptions = {
  video: HTMLVideoElement;
  onResult: (result: QrScanResult) => void;
  onError?: (message: string) => void;
};

type BarcodeDetectorLike = {
  detect: (source: HTMLVideoElement) => Promise<Array<{ rawValue?: string }>>;
};

type BarcodeDetectorCtor = new (options?: { formats?: string[] }) => BarcodeDetectorLike;

function getBarcodeDetector(): BarcodeDetectorCtor | null {
  const w = window as unknown as { BarcodeDetector?: BarcodeDetectorCtor };
  return typeof w.BarcodeDetector === "function" ? w.BarcodeDetector : null;
}

async function decodeWithJsQr(video: HTMLVideoElement): Promise<string | null> {
  const mod = await import("jsqr");
  const jsQR = mod.default;
  const canvas = document.createElement("canvas");
  const width = video.videoWidth;
  const height = video.videoHeight;
  if (!width || !height) {
    return null;
  }
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  if (!ctx) {
    return null;
  }
  ctx.drawImage(video, 0, 0, width, height);
  const imageData = ctx.getImageData(0, 0, width, height);
  const code = jsQR(imageData.data, imageData.width, imageData.height, {
    inversionAttempts: "dontInvert"
  });
  return code?.data ? String(code.data) : null;
}

/**
 * Camera QR capture abstraction.
 * Primary: BarcodeDetector. Fallback: jsqr (dynamic import).
 * No manual token paste / file upload.
 */
export async function startQrScanner(options: StartOptions): Promise<QrScannerHandle> {
  if (!window.isSecureContext) {
    throw new Error("Kamera için güvenli bağlantı (HTTPS) gerekir.");
  }
  if (!navigator.mediaDevices?.getUserMedia) {
    throw new Error("Bu cihaz kamera erişimini desteklemiyor.");
  }

  let stream: MediaStream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: {
        facingMode: { ideal: "environment" }
      }
    });
  } catch (error) {
    const name =
      error && typeof error === "object" && "name" in error ? String((error as { name?: unknown }).name) : "";
    if (name === "NotAllowedError" || name === "PermissionDeniedError") {
      throw new Error("Kamera izni reddedildi. Tarayıcı ayarlarından kamera erişimini açın.");
    }
    if (name === "NotFoundError" || name === "DevicesNotFoundError") {
      throw new Error("Bu cihazda kullanılabilir kamera bulunamadı.");
    }
    if (name === "NotReadableError" || name === "TrackStartError") {
      throw new Error("Kamera başka bir uygulama tarafından kullanılıyor olabilir.");
    }
    throw new Error("Kamera açılamadı. İzinleri ve HTTPS bağlantısını kontrol edin.");
  }

  const video = options.video;
  video.srcObject = stream;
  video.setAttribute("playsinline", "true");
  video.muted = true;
  await video.play();

  let stopped = false;
  let raf = 0;
  const Detector = getBarcodeDetector();
  let detector: BarcodeDetectorLike | null = null;
  if (Detector) {
    try {
      detector = new Detector({ formats: ["qr_code"] });
    } catch {
      detector = null;
    }
  }

  const tick = async () => {
    if (stopped) {
      return;
    }
    try {
      if (detector) {
        const codes = await detector.detect(video);
        const raw = codes.find((c) => c.rawValue)?.rawValue;
        if (raw) {
          options.onResult({ rawValue: String(raw) });
          return;
        }
      } else {
        const raw = await decodeWithJsQr(video);
        if (raw) {
          options.onResult({ rawValue: raw });
          return;
        }
      }
    } catch (error) {
      options.onError?.(error instanceof Error ? error.message : "QR okuma hatasi.");
    }
    raf = window.setTimeout(() => {
      void tick();
    }, 250);
  };

  void tick();

  return {
    stop: () => {
      stopped = true;
      window.clearTimeout(raf);
      const tracks = stream.getTracks();
      for (const track of tracks) {
        track.stop();
      }
      if (video.srcObject === stream) {
        video.srcObject = null;
      }
    }
  };
}
