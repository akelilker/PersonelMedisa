import { PersonelPuantajOzetSection } from "./PersonelPuantajOzetSection";

export function PersonelPuantajPanel({
  personel,
  canViewPuantaj,
  canViewRevizyon,
  isActive
}: {
  personel: Parameters<typeof PersonelPuantajOzetSection>[0]["personel"];
  canViewPuantaj: boolean;
  canViewRevizyon: boolean;
  isActive: boolean;
}) {
  return (
    <PersonelPuantajOzetSection
      personel={personel}
      canViewPuantaj={canViewPuantaj}
      canViewRevizyon={canViewRevizyon}
      isActive={isActive}
    />
  );
}
