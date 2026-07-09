export type Bildirim = {
  id: number;
  tarih?: string;
  departman_id?: number;
  personel_id?: number;
  bildirim_turu: string;
  aciklama?: string;
  state?: string;
  okundu_mi?: boolean;
  created_by?: number | null;
  updated_by?: number | null;
  submitted_at?: string | null;
  correction_requested_by?: number | null;
  correction_reason?: string | null;
};
