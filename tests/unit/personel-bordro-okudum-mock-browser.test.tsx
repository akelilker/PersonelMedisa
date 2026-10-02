/** @vitest-environment jsdom */
import { describe, expect, it, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

const { acknowledgeSelfBordro, fetchSelfBordrolar } = vi.hoisted(() => ({
  acknowledgeSelfBordro: vi.fn(),
  fetchSelfBordrolar: vi.fn()
}));

vi.mock("../../src/api/api-client", () => ({
  shouldPreferDemoApi: () => false,
  isApiRequestError: () => false
}));

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: () => ({ hasPermission: () => true })
}));

vi.mock("../../src/features/self-service/hooks/use-self-profil-foto", () => ({
  useSelfProfilFoto: () => ({ photoSrc: null, photoMessage: null, onPhotoSelected: vi.fn() })
}));

vi.mock("../../src/api/self-product.api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/api/self-product.api")>();
  return {
    ...actual,
    fetchSelfBordrolar,
    acknowledgeSelfBordro
  };
});

import { PersonelSelfServiceProfilPage } from "../../src/features/self-service/pages/PersonelSelfServiceProfilPage";

vi.mock("../../src/api/me.api", () => ({
  fetchMe: vi.fn().mockResolvedValue({
    ad_soyad: "Test User",
    personel: {
      ad_soyad: "Test Personel",
      sicil_no: "1",
      sube_ad: "Merkez",
      bolum_ad: "Üretim",
      birim_ad: "Montaj"
    }
  }),
  fetchMeYillikIzinBakiye: vi.fn().mockResolvedValue({ ise_giris_tarihi: "2020-01-01" })
}));

describe("personel bordro okudum (mocked browser)", () => {
  beforeEach(() => {
    acknowledgeSelfBordro.mockReset();
    fetchSelfBordrolar.mockReset();
    fetchSelfBordrolar.mockResolvedValue([
      {
        calistirma_id: 42,
        yil: 2026,
        ay: 3,
        sube_id: 1,
        donem_label: "2026-03",
        okundu: false,
        okundu_at: null,
        okundu_by_user_id: null
      }
    ]);
    acknowledgeSelfBordro.mockResolvedValue({
      calistirma_id: 42,
      okundu: true,
      okundu_at: "2026-03-01T10:00:00Z",
      okundu_by_user_id: 7,
      donem_label: "2026-03",
      yil: 2026,
      ay: 3
    });
  });

  it("shows published bordro, calls acknowledge API, then shows Okudum", async () => {
    render(
      <MemoryRouter>
        <PersonelSelfServiceProfilPage />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByTestId("personel-bordro-okudum")).toBeTruthy();
    });

    fireEvent.click(screen.getByTestId("personel-bordro-okudum-42"));

    await waitFor(() => {
      expect(acknowledgeSelfBordro).toHaveBeenCalledWith(42);
      expect(screen.getByTestId("personel-bordro-okundu-42")).toBeTruthy();
    });
  });
});
