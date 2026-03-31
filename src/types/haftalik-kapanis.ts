export type HaftalikKapanisPayload = {
  hafta_baslangic: string;
  hafta_bitis: string;
  departman_id?: number;
};

export type HaftalikKapanisSonuc = {
  id?: number;
  hafta_baslangic?: string;
  hafta_bitis?: string;
  departman_id?: number;
  state?: string;
  [key: string]: unknown;
};
