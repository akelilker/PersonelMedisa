export const SERBEST_ZAMAN_FM_CARPANI = 1.5;
export const SERBEST_ZAMAN_FSC_CARPANI = 1.25;

export type HesaplaSerbestZamanDakikaParams = {
  fazla_calisma_dakika: number;
  fazla_surelerle_calisma_dakika?: number;
};

function guvenliDakika(value: number | undefined): number {
  if (value === undefined || !Number.isFinite(value) || value < 0) {
    return 0;
  }

  return value;
}

/**
 * FM/FSC kaynak dakikalarini serbest zaman dakikaya cevirir. Hak uretmez (E3).
 */
export function hesaplaSerbestZamanDakika(params: HesaplaSerbestZamanDakikaParams): number {
  const fm = guvenliDakika(params.fazla_calisma_dakika);
  const fsc = guvenliDakika(params.fazla_surelerle_calisma_dakika);

  return Math.round(fm * SERBEST_ZAMAN_FM_CARPANI) + Math.round(fsc * SERBEST_ZAMAN_FSC_CARPANI);
}
