export type GenelYoneticiBildirimOnayiState = "TAMAMLANDI";

export type GenelYoneticiBildirimOnayi = {
  id: number;
  sube_id: number;
  birim_amiri_user_id: number;
  ay: string;
  aylik_bildirim_onayi_id: number;
  state: GenelYoneticiBildirimOnayiState;
  onaylayan_user_id: number;
  onaylandi_at: string | null;
  aciklama: string | null;
  created_at: string;
  updated_at: string;
};

export type GenelYoneticiBildirimOnayiOzetKaydi = Pick<
  GenelYoneticiBildirimOnayi,
  "id" | "state" | "onaylayan_user_id" | "onaylandi_at" | "aciklama"
>;

export type GenelYoneticiBildirimOnayiOzet = {
  ay: string;
  ay_baslangic: string;
  ay_bitis: string;
  sube_id: number;
  birim_amiri_user_id: number;
  counts: {
    toplam_bildirim: number;
    mutabakata_alinan: number;
    eksik_hafta: number;
  };
  aylik_bildirim_onayi: {
    id: number;
    state: "TAMAMLANDI";
    onaylandi_at: string | null;
  } | null;
  genel_yonetici_bildirim_onayi: GenelYoneticiBildirimOnayiOzetKaydi | null;
  onay_verilebilir_mi: boolean;
  blok_nedeni: string | null;
};

export type GenelYoneticiBildirimOnayiOlusturPayload = {
  ay: string;
  sube_id: number;
  birim_amiri_user_id: number;
};
