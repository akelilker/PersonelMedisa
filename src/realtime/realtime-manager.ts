/**
 * Yerel WebSocket istemcisi — harici paket yok.
 * Ortam: import.meta.env.VITE_REALTIME_WS_URL (bos ise baglanti kurulmaz).
 */

export type RealtimeEventType = "BILDIRIM_YENI" | "SUREC_GUNCELLENDI" | "PERSONEL_GUNCELLENDI";

export type RealtimeEnvelope = {
  type: RealtimeEventType;
  /** Yoksa veya aktif sube ile uyusuyorsa islenir */
  sube_id?: number;
  payload: unknown;
};

type MessageHandler = (envelope: RealtimeEnvelope) => void;

let socket: WebSocket | null = null;
const handlers = new Set<MessageHandler>();

function defaultWsUrl(): string | undefined {
  const raw = import.meta.env.VITE_REALTIME_WS_URL;
  return typeof raw === "string" && raw.trim() !== "" ? raw.trim() : undefined;
}

function parseEnvelope(raw: string): RealtimeEnvelope | null {
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== "object" || parsed === null) {
      return null;
    }
    const rec = parsed as Record<string, unknown>;
    const t = rec.type;
    if (t !== "BILDIRIM_YENI" && t !== "SUREC_GUNCELLENDI" && t !== "PERSONEL_GUNCELLENDI") {
      return null;
    }
    return {
      type: t,
      sube_id: typeof rec.sube_id === "number" ? rec.sube_id : undefined,
      payload: rec.payload
    };
  } catch {
    return null;
  }
}

/**
 * Mesaj dinleyicisi; donis abonelik iptali.
 */
export function onMessage(handler: MessageHandler): () => void {
  handlers.add(handler);
  return () => {
    handlers.delete(handler);
  };
}

export function connect(url?: string): void {
  disconnect();
  const u = url ?? defaultWsUrl();
  if (!u || typeof WebSocket === "undefined") {
    return;
  }
  try {
    const ws = new WebSocket(u);
    socket = ws;
    ws.onmessage = (ev) => {
      const env = parseEnvelope(String(ev.data));
      if (!env) {
        return;
      }
      handlers.forEach((h) => {
        h(env);
      });
    };
    ws.onclose = () => {
      if (socket === ws) {
        socket = null;
      }
    };
  } catch {
    socket = null;
  }
}

export function disconnect(): void {
  if (socket) {
    try {
      socket.close();
    } catch {
      /* ignore */
    }
    socket = null;
  }
}
