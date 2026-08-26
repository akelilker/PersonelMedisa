export type IdOption = {
  id: number;
  label: string;
  /** Pack6 hierarchical refs (Bölüm → Departman, Birim → Bölüm). */
  parentId?: number | null;
  /** Org reference short code (bolumler/birimler.kisa_kod). */
  kisaKod?: string | null;
};

export type KeyOption = {
  key: string;
  label: string;
};
