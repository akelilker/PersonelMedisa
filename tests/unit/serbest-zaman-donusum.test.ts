import { describe, expect, it } from "vitest";
import { hesaplaSerbestZamanDakika } from "../../src/services/serbest-zaman-donusum";

describe("serbest-zaman-donusum", () => {
  it("FM 60 dk serbest zamana 90 dk cevirir", () => {
    expect(hesaplaSerbestZamanDakika({ fazla_calisma_dakika: 60 })).toBe(90);
  });

  it("FSC 60 dk serbest zamana 75 dk cevirir", () => {
    expect(
      hesaplaSerbestZamanDakika({
        fazla_calisma_dakika: 0,
        fazla_surelerle_calisma_dakika: 60
      })
    ).toBe(75);
  });

  it("FM ve FSC toplamini ayri carpanlarla hesaplar", () => {
    expect(
      hesaplaSerbestZamanDakika({
        fazla_calisma_dakika: 60,
        fazla_surelerle_calisma_dakika: 60
      })
    ).toBe(165);
  });

  it("negatif ve gecersiz dakikalari 0 kabul eder", () => {
    expect(
      hesaplaSerbestZamanDakika({
        fazla_calisma_dakika: -10,
        fazla_surelerle_calisma_dakika: Number.NaN
      })
    ).toBe(0);
  });

  it("bos giris 0 dondurur", () => {
    expect(hesaplaSerbestZamanDakika({ fazla_calisma_dakika: 0 })).toBe(0);
  });
});
