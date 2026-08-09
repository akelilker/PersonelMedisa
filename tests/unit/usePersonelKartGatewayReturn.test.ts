/** @vitest-environment jsdom */
import { renderHook } from "@testing-library/react";
import { type NavigateFunction } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import { usePersonelKartGatewayReturn } from "../../src/features/personeller/hooks/usePersonelKartGatewayReturn";

describe("usePersonelKartGatewayReturn", () => {
  it("handleOpenSurecModal navigates with surec tab and personel preselect contract", () => {
    const navigate = vi.fn() as NavigateFunction;

    const { result } = renderHook(() =>
      usePersonelKartGatewayReturn({
        navigate,
        parsedPersonelId: 3
      })
    );

    result.current.handleOpenSurecModal();

    expect(navigate).toHaveBeenCalledWith("/", {
      state: {
        kayitModal: {
          tab: "surec",
          personelId: 3
        }
      }
    });
  });

  it("does not expose legacy edit/zimmet gateway emitters", () => {
    const navigate = vi.fn() as NavigateFunction;

    const { result } = renderHook(() =>
      usePersonelKartGatewayReturn({
        navigate,
        parsedPersonelId: 1
      })
    );

    expect(result.current).toEqual({
      handleOpenSurecModal: expect.any(Function)
    });
    expect(result.current).not.toHaveProperty("handleOpenPersonelEditGateway");
    expect(result.current).not.toHaveProperty("handleOpenPersonelZimmetGateway");
  });
});
