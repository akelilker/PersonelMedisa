/** @vitest-environment jsdom */
import { renderHook, waitFor } from "@testing-library/react";
import { type Location, type NavigateFunction } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import { usePersonelKartGatewayReturn } from "../../src/features/personeller/hooks/usePersonelKartGatewayReturn";

function makeLocation(pathname: string, state: unknown): Location {
  return {
    pathname,
    search: "",
    hash: "",
    key: "default",
    state
  };
}

describe("usePersonelKartGatewayReturn", () => {
  it("consumes openPersonelEdit route state and clears it after navigation", async () => {
    const setActiveTab = vi.fn();
    const setIsEditing = vi.fn();
    const openZimmetModal = vi.fn();
    const navigate = vi.fn() as NavigateFunction;

    renderHook(() =>
      usePersonelKartGatewayReturn({
        location: makeLocation("/personeller/1", { openPersonelEdit: true }),
        navigate,
        parsedPersonelId: 1,
        canEditPersonel: true,
        canCreateZimmet: true,
        setActiveTab,
        setIsEditing,
        openZimmetModal
      })
    );

    await waitFor(() => {
      expect(setActiveTab).toHaveBeenCalledWith("genel-bilgiler");
      expect(setIsEditing).toHaveBeenCalledWith(true);
    });
    expect(openZimmetModal).not.toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith("/personeller/1", { replace: true, state: null });
  });

  it("consumes openPersonelZimmet route state and opens zimmet modal", async () => {
    const setActiveTab = vi.fn();
    const setIsEditing = vi.fn();
    const openZimmetModal = vi.fn();
    const navigate = vi.fn() as NavigateFunction;

    renderHook(() =>
      usePersonelKartGatewayReturn({
        location: makeLocation("/personeller/2", { openPersonelZimmet: true }),
        navigate,
        parsedPersonelId: 2,
        canEditPersonel: true,
        canCreateZimmet: true,
        setActiveTab,
        setIsEditing,
        openZimmetModal
      })
    );

    await waitFor(() => {
      expect(setActiveTab).toHaveBeenCalledWith("zimmet-envanter");
      expect(openZimmetModal).toHaveBeenCalledTimes(1);
    });
    expect(setIsEditing).not.toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith("/personeller/2", { replace: true, state: null });
  });

  it("ignores gateway route state when edit permission is missing", async () => {
    const setActiveTab = vi.fn();
    const setIsEditing = vi.fn();
    const openZimmetModal = vi.fn();
    const navigate = vi.fn() as NavigateFunction;

    renderHook(() =>
      usePersonelKartGatewayReturn({
        location: makeLocation("/personeller/1", { openPersonelEdit: true }),
        navigate,
        parsedPersonelId: 1,
        canEditPersonel: false,
        canCreateZimmet: false,
        setActiveTab,
        setIsEditing,
        openZimmetModal
      })
    );

    await waitFor(() => {
      expect(setActiveTab).not.toHaveBeenCalled();
      expect(setIsEditing).not.toHaveBeenCalled();
      expect(openZimmetModal).not.toHaveBeenCalled();
      expect(navigate).not.toHaveBeenCalled();
    });
  });

  it("handleOpenPersonelEditGateway navigates with kayit modal gateway contract", () => {
    const navigate = vi.fn() as NavigateFunction;

    const { result } = renderHook(() =>
      usePersonelKartGatewayReturn({
        location: makeLocation("/personeller/1", null),
        navigate,
        parsedPersonelId: 1,
        canEditPersonel: true,
        canCreateZimmet: true,
        setActiveTab: vi.fn(),
        setIsEditing: vi.fn(),
        openZimmetModal: vi.fn()
      })
    );

    result.current.handleOpenPersonelEditGateway();

    expect(navigate).toHaveBeenCalledWith("/", {
      state: {
        kayitModal: {
          tab: "yeni-kayit",
          personelId: 1,
          intent: "personel-edit-gateway",
          returnTo: "/personeller/1"
        }
      }
    });
  });

  it("handleOpenPersonelZimmetGateway navigates with zimmet gateway contract", () => {
    const navigate = vi.fn() as NavigateFunction;

    const { result } = renderHook(() =>
      usePersonelKartGatewayReturn({
        location: makeLocation("/personeller/2", null),
        navigate,
        parsedPersonelId: 2,
        canEditPersonel: true,
        canCreateZimmet: true,
        setActiveTab: vi.fn(),
        setIsEditing: vi.fn(),
        openZimmetModal: vi.fn()
      })
    );

    result.current.handleOpenPersonelZimmetGateway();

    expect(navigate).toHaveBeenCalledWith("/", {
      state: {
        kayitModal: {
          tab: "yeni-kayit",
          personelId: 2,
          intent: "personel-zimmet-gateway",
          returnTo: "/personeller/2"
        }
      }
    });
  });
});
