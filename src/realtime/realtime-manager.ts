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

let allowReconnect = false;
let isConnecting = false;
let reconnectAttempts = 0;
let reconnectTimer: ReturnType<typeof setTimeout> | null = null;
let lifecycleId = 0;
let lastWsUrl: string | null = null;

const MAX_RECONNECT_ATTEMPTS = 6;

function defaultWsUrl(): string | undefined {
  const raw = import.meta.env.VITE_REALTIME_WS_URL;
  return typeof raw === "string" && raw.trim() !== "" ? raw.trim() : undefined;
}

function clearReconnectTimer(): void {
  if (reconnectTimer !== null) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }
}

function abandonSocket(): void {
  lifecycleId++;
  if (socket) {
    try {
      socket.close();
    } catch {
      /* ignore */
    }
    socket = null;
  }
  isConnecting = false;
}

function scheduleReconnect(): void {
  if (!allowReconnect) {
    return;
  }
  const u = lastWsUrl ?? defaultWsUrl();
  if (!u) {
    return;
  }
  if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
    return;
  }
  const attemptIndex = reconnectAttempts;
  reconnectAttempts += 1;
  const delayMs = Math.min(1000 * Math.pow(2, attemptIndex), 10_000);
  clearReconnectTimer();
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    if (!allowReconnect) {
      return;
    }
    tryOpen(undefined);
  }, delayMs);
}

function tryOpen(url?: string): void {
  const u = url ?? defaultWsUrl();
  if (!u || typeof WebSocket === "undefined") {
    isConnecting = false;
    return;
  }
  lastWsUrl = u;
  isConnecting = true;
  lifecycleId++;
  const myId = lifecycleId;

  try {
    const ws = new WebSocket(u);
    socket = ws;
    ws.onopen = () => {
      if (myId !== lifecycleId) {
        return;
      }
      isConnecting = false;
      reconnectAttempts = 0;
    };
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
      if (myId !== lifecycleId) {
        return;
      }
      if (socket === ws) {
        socket = null;
      }
      isConnecting = false;
      scheduleReconnect();
    };
  } catch {
    if (myId !== lifecycleId) {
      return;
    }
    socket = null;
    isConnecting = false;
    scheduleReconnect();
  }
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
  allowReconnect = true;
  clearReconnectTimer();
  reconnectAttempts = 0;
  abandonSocket();
  tryOpen(url);
}

export function disconnect(): void {
  allowReconnect = false;
  clearReconnectTimer();
  reconnectAttempts = 0;
  abandonSocket();
}
