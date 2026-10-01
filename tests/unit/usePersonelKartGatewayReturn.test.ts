/** @vitest-environment jsdom */
import { renderHook } from "@testing-library/react";
import { createElement } from "react";
import { MemoryRouter, type NavigateFunction } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import { usePersonelKartGatewayReturn } from "../../src/features/personeller/hooks/usePersonelKartGatewayReturn";

function renderGatewayHook(parsedPersonelId: number, initialPath = `/personeller/${parsedPersonelId}`) {
  const navigate = vi.fn() as NavigateFunction;
  const view = renderHook(
    () =>
      usePersonelKartGatewayReturn({
        navigate,
        parsedPersonelId
      }),
    {
      wrapper: ({ children }) =>
        createElement(MemoryRouter, { initialEntries: [initialPath] }, children)
    }
  );
  return { ...view, navigate };
}

describe("usePersonelKartGatewayReturn", () => {
  it("handleOpenSurecModal navigates with surec tab and personel preselect contract", () => {
    const { result, navigate } = renderGatewayHook(3);

    result.current.handleOpenSurecModal();

    expect(navigate).toHaveBeenCalledWith("/personeller/3", {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: 3,
          targetTab: "puantaj",
          intent: "personel-surec-gateway",
          returnTo: "/personeller/3"
        }
      }
    });
  });

  it("does not expose legacy edit/zimmet gateway emitters", () => {
    const { result } = renderGatewayHook(1);

    expect(result.current).toEqual({
      handleOpenSurecModal: expect.any(Function),
      handleOpenYillikIzinHakDuzeltme: expect.any(Function),
      handleOpenMissingInfo: expect.any(Function)
    });
    expect(result.current).not.toHaveProperty("handleOpenPersonelEditGateway");
    expect(result.current).not.toHaveProperty("handleOpenPersonelZimmetGateway");
  });

  it("handleOpenYillikIzinHakDuzeltme navigates with izin tab and hak-duzeltme operation", () => {
    const { result, navigate } = renderGatewayHook(9);

    result.current.handleOpenYillikIzinHakDuzeltme();

    expect(navigate).toHaveBeenCalledWith("/personeller/9", {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: 9,
          targetTab: "puantaj",
          intent: "yillik-izin-hak-duzeltme-gateway",
          operation: "yillik-izin-hak-duzeltme"
        }
      }
    });
  });
});
