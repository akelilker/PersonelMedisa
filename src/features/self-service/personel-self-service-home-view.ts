import type { AttendanceTodayResponse } from "../../api/attendance-mobile.api";
import type { SelfServiceYillikIzinView } from "./personel-self-service-yillik-izin-view";

export type PersonelSelfServiceHomeInfoRow = {
  label: string;
  value: string;
  testId?: string;
};

export type PersonelSelfServiceHomeInfoView = {
  rows: PersonelSelfServiceHomeInfoRow[];
  izinModalRow: { text: string } | null;
};

function dash(value: string | null | undefined): string {
  const trimmed = value?.trim();
  return trimmed ? trimmed : "-";
}

function eventClock(event: AttendanceTodayResponse["giris"]): string {
  if (!event) {
    return "-";
  }
  return dash(event.display_local_time ?? event.local_time);
}

export function buildPersonelSelfServiceHomeInfoView(
  today: AttendanceTodayResponse,
  izinView: SelfServiceYillikIzinView | null
): PersonelSelfServiceHomeInfoView {
  const rows: PersonelSelfServiceHomeInfoRow[] = [];
  let izinModalRow: { text: string } | null = null;

  if (izinView) {
    rows.push({
      label: "İşe giriş",
      value: izinView.iseGirisLabel,
      testId: "personel-home-ise-giris"
    });
    rows.push({
      label: "Kıdem",
      value: izinView.kidemCalisiyorLabel,
      testId: "personel-home-kidem"
    });

    if (izinView.hakBasladi) {
      rows.push(
        { label: "Toplam izin", value: izinView.toplamLabel, testId: "personel-home-izin-toplam" },
        { label: "Kullanılan", value: izinView.kullanilanLabel, testId: "personel-home-izin-kullanilan" }
      );
      izinModalRow = { text: izinView.rowText };
    } else {
      izinModalRow = { text: izinView.rowText };
    }
  }

  rows.push(
    { label: "Bugün giriş", value: eventClock(today.giris), testId: "personel-home-bugun-giris" },
    { label: "Bugün çıkış", value: eventClock(today.cikis), testId: "personel-home-bugun-cikis" },
    {
      label: "Mesai bitimine kalan",
      value: dash(today.mesai_bitimine_kalan_label),
      testId: "personel-home-mesai-kalan"
    }
  );

  return { rows, izinModalRow };
}
