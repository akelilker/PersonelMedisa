export type IdOption = {
  id: number;
  label: string;
  /** Pack6 hierarchical refs (Bölüm → Departman, Birim → Bölüm). */
  parentId?: number | null;
};

export type KeyOption = {
  key: string;
  label: string;
};
