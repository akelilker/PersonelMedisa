export type PersonelKartBackState = {
  to: string;
  label: string;
};

export type PersonelKartLocationState = {
  personelKartBack?: PersonelKartBackState;
};

export type PersonelKartPhase = 'scope' | 'list' | 'search';

export function readPersonelKartBack(state: unknown): PersonelKartBackState | null {
  if (!state || typeof state !== 'object') {
    return null;
  }
  const back = (state as PersonelKartLocationState).personelKartBack;
  if (!back || typeof back.to !== 'string' || typeof back.label !== 'string') {
    return null;
  }
  if (!back.to.trim() || !back.label.trim()) {
    return null;
  }
  return { to: back.to, label: back.label };
}
