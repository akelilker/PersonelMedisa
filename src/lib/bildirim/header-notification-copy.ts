import { formatIsoDateDetail } from "../display/iso-date-format";
import { formatBildirimTuruLabel, normalizeEnumKey } from "../display/enum-display";
import type { Bildirim } from "../../types/bildirim";

export type HeaderNotificationCopy = {
  title: string;
  subtitle: string;
};

const PERSONEL_NAME_FALLBACK = "Personel bildirimi";

/** Soft ceiling for header DIGER summary before CSS line-clamp. */
const DIGER_SUMMARY_SOFT_MAX = 96;
const DIGER_SUMMARY_MIN_MEANINGFUL = 6;

/** Prefer conclusive passive/result tails (not narrative "… yapıldı."). */
const RESULT_SENTENCE_TAIL =
  /(?:yap[ıi]lm[ıi][şs]t[ıi]r|edilmi[şs]tir|ger[çc]ekle[şs]tirilmi[şs]tir|tamamlanm[ıi][şs]t[ıi]r|tamamland[ıi]|kaydedildi|onayland[ıi]|bildirildi|g[üu]ncellendi)\.?$/iu;

function trimText(value: string | null | undefined): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function resolvePersonelName(
  item: Pick<Bildirim, "personel_ad_soyad">,
  lookupName?: string | null
): string {
  return trimText(item.personel_ad_soyad) ?? trimText(lookupName) ?? PERSONEL_NAME_FALLBACK;
}

function formatNotificationDate(tarih: string | null | undefined): string | null {
  const formatted = formatIsoDateDetail(tarih);
  return formatted === "-" ? null : formatted;
}

function buildSubtitle(tarih: string | null | undefined): string {
  // Header date row: gg.aa.yyyy only (no clock, şube, or technical metadata).
  return formatNotificationDate(tarih) ?? "İşlem gerektiriyor";
}

function positiveDakika(value: number | null | undefined): number | null {
  if (typeof value !== "number" || !Number.isFinite(value) || value <= 0) {
    return null;
  }
  return Math.trunc(value);
}

function normalizeWhitespace(value: string): string {
  return value.replace(/\s+/g, " ").trim();
}

/**
 * Split into sentences without blind mid-word cuts.
 * Keeps terminal punctuation on each sentence.
 */
function splitSentences(text: string): string[] {
  const normalized = normalizeWhitespace(text);
  if (!normalized) {
    return [];
  }

  const parts = normalized.match(/[^.!?…]+[.!?…]+|[^.!?…]+$/gu);
  if (!parts) {
    return [normalized];
  }

  return parts.map((part) => part.trim()).filter(Boolean);
}

function stripTrailingPunctuation(value: string): string {
  return value.replace(/[.!?…]+$/u, "").trim();
}

function ensureTerminalPeriod(value: string): string {
  const trimmed = value.trim();
  if (!trimmed) {
    return trimmed;
  }
  if (/[.!?…]$/u.test(trimmed)) {
    return trimmed;
  }
  return `${trimmed}.`;
}

function isMeaningfulSentence(sentence: string): boolean {
  const core = stripTrailingPunctuation(sentence);
  if (core.length < DIGER_SUMMARY_MIN_MEANINGFUL) {
    return false;
  }
  // Reject pure filler / numeric noise.
  if (/^(evet|hayır|ok|tamam|not|açıklama)\.?$/iu.test(core)) {
    return false;
  }
  return /\p{L}/u.test(core);
}

function pickShortestMeaningful(sentences: string[]): string | null {
  const candidates = sentences.filter(isMeaningfulSentence);
  if (candidates.length === 0) {
    return null;
  }
  candidates.sort((a, b) => {
    const lenDelta = a.length - b.length;
    if (lenDelta !== 0) {
      return lenDelta;
    }
    return sentences.indexOf(a) - sentences.indexOf(b);
  });
  return candidates[0] ?? null;
}

function pickResultSentence(sentences: string[]): string | null {
  const results = sentences.filter(
    (sentence) => isMeaningfulSentence(sentence) && RESULT_SENTENCE_TAIL.test(sentence.trim())
  );
  if (results.length === 0) {
    return null;
  }
  // Prefer the last result clause (usually the conclusion), then shortest.
  const last = results[results.length - 1] ?? null;
  if (last && last.length <= DIGER_SUMMARY_SOFT_MAX) {
    return last;
  }
  return pickShortestMeaningful(results);
}

/**
 * Reduce a single long sentence to a scannable clause without mid-word chops.
 * Prefers the final clause after separators when it looks conclusive.
 */
function reduceLongSentence(sentence: string): string {
  const normalized = normalizeWhitespace(sentence);
  if (normalized.length <= DIGER_SUMMARY_SOFT_MAX) {
    return ensureTerminalPeriod(normalized);
  }

  const clauses = normalized
    .split(/\s*[;—–:]\s+|\s+-\s+/u)
    .map((part) => part.trim())
    .filter(Boolean);

  if (clauses.length > 1) {
    const last = clauses[clauses.length - 1] ?? "";
    if (isMeaningfulSentence(last) && last.length <= DIGER_SUMMARY_SOFT_MAX) {
      return ensureTerminalPeriod(last);
    }
    const shortClause = pickShortestMeaningful(clauses.map((c) => ensureTerminalPeriod(c)));
    if (shortClause && shortClause.length <= DIGER_SUMMARY_SOFT_MAX) {
      return ensureTerminalPeriod(shortClause);
    }
  }

  // First meaningful stretch ending at a word boundary (not a blind mid-token cut).
  if (normalized.length <= DIGER_SUMMARY_SOFT_MAX) {
    return ensureTerminalPeriod(normalized);
  }
  const slice = normalized.slice(0, DIGER_SUMMARY_SOFT_MAX);
  const boundary = slice.lastIndexOf(" ");
  const cut = boundary >= DIGER_SUMMARY_MIN_MEANINGFUL ? slice.slice(0, boundary) : slice;
  return `${cut.trim()}…`;
}

/**
 * Deterministic DIGER header summary. Raw `aciklama` is never mutated in storage;
 * only the header title uses this reduction.
 */
export function summarizeDigerAciklama(aciklama: string): string {
  const normalized = normalizeWhitespace(aciklama);
  if (!normalized) {
    return "";
  }

  const sentences = splitSentences(normalized);
  const result = pickResultSentence(sentences);
  if (result) {
    return ensureTerminalPeriod(
      result.length <= DIGER_SUMMARY_SOFT_MAX ? result : reduceLongSentence(result)
    );
  }

  if (sentences.length > 1) {
    const shortest = pickShortestMeaningful(sentences);
    if (shortest) {
      return ensureTerminalPeriod(
        shortest.length <= DIGER_SUMMARY_SOFT_MAX ? shortest : reduceLongSentence(shortest)
      );
    }
  }

  return reduceLongSentence(normalized);
}

function formatDigerTitle(personelName: string, aciklama: string | null): string {
  if (!aciklama) {
    return `${personelName} için özel bildirim kaydı.`;
  }
  const summary = summarizeDigerAciklama(aciklama);
  if (!summary) {
    return `${personelName} için özel bildirim kaydı.`;
  }
  return `${personelName} — ${summary}`;
}

/**
 * Pure FE formatter for header notification copy.
 * Preserves raw bildirim_turu identity; never leaks technical personel ids.
 */
export function formatHeaderBildirimCopy(
  item: Pick<
    Bildirim,
    | "bildirim_turu"
    | "personel_ad_soyad"
    | "tarih"
    | "aciklama"
    | "dakika"
    | "baslangic_saati"
    | "bitis_saati"
    | "sube_adi"
  >,
  options?: { lookupPersonelName?: string | null }
): HeaderNotificationCopy {
  const personelName = resolvePersonelName(item, options?.lookupPersonelName);
  const tur = normalizeEnumKey(item.bildirim_turu);
  const dakika = positiveDakika(item.dakika);

  switch (tur) {
    case "GELMEDI":
      return {
        title: `${personelName} gelmedi.`,
        subtitle: buildSubtitle(item.tarih)
      };
    case "GEC_GELDI":
      return {
        title: dakika
          ? `${personelName} ${dakika} dakika geç geldi.`
          : `${personelName} geç geldi.`,
        subtitle: buildSubtitle(item.tarih)
      };
    case "ERKEN_CIKTI":
      return {
        title: dakika
          ? `${personelName} ${dakika} dakika erken çıktı.`
          : `${personelName} erken çıktı.`,
        subtitle: buildSubtitle(item.tarih)
      };
    case "IZINLI":
      return {
        title: `${personelName} izinli.`,
        subtitle: buildSubtitle(item.tarih)
      };
    case "RAPORLU":
      return {
        title: `${personelName} raporlu.`,
        subtitle: buildSubtitle(item.tarih)
      };
    case "GOREVDE":
      return {
        title: `${personelName} görevde çalıştı.`,
        subtitle: buildSubtitle(item.tarih)
      };
    case "DIGER": {
      return {
        title: formatDigerTitle(personelName, trimText(item.aciklama)),
        subtitle: buildSubtitle(item.tarih)
      };
    }
    default: {
      const eventLabel = formatBildirimTuruLabel(item.bildirim_turu);
      const hasPersonel = personelName !== PERSONEL_NAME_FALLBACK;
      return {
        title: hasPersonel
          ? `${personelName} · ${eventLabel}`
          : `Günlük bildirim · ${eventLabel}`,
        subtitle: buildSubtitle(item.tarih)
      };
    }
  }
}

export type ReminderCopyInput = {
  key: "salary" | "sgk";
  daysLeft: number;
  dueDateLabel: string;
};

export function formatHeaderReminderCopy(input: ReminderCopyInput): HeaderNotificationCopy {
  const title =
    input.key === "salary"
      ? "Maaş ödeme zamanı yaklaşıyor."
      : "SGK prim ödeme takibini kontrol et.";

  const deadline =
    input.daysLeft <= 0
      ? `Bugün son gün · ${input.dueDateLabel}`
      : `${input.daysLeft} gün kaldı · ${input.dueDateLabel}`;

  return { title, subtitle: deadline };
}
