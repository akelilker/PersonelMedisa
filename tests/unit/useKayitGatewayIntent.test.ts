/** @vitest-environment jsdom */
import { act, renderHook } from "@testing-library/react";
import { createElement, Fragment, type ReactNode } from "react";
import { MemoryRouter, useLocation, type Location } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import { useKayitGatewayIntent } from "../../src/features/kayit/hooks/useKayitGatewayIntent";

function createRouterWrapper(initialEntries: Parameters<typeof MemoryRouter>[0]["initialEntries"] = ["/"]) {
  let latestLocation: Location | undefined;

  function LocationProbe() {
    latestLocation = useLocation();
    return null;
  }

  function Wrapper({ children }: { children: ReactNode }) {
    return createElement(
      MemoryRouter,
      { initialEntries },
      createElement(Fragment, null, createElement(LocationProbe), children)
    );
  }

  return {
    Wrapper,
    getLocation: () => {
      if (!latestLocation) {
        throw new Error("Location probe not mounted");
      }
      return latestLocation;
    }
  };
}

describe("useKayitGatewayIntent", () => {
  it("shows gateway message only for yeni-kayit tab with valid gateway intent", () => {
    const { Wrapper } = createRouterWrapper();

    const hidden = renderHook(
      () =>
        useKayitGatewayIntent({
          activeTab: "surec",
          initialIntent: "personel-edit-gateway",
          initialReturnTo: "/personeller/1",
          onClose: vi.fn()
        }),
      { wrapper: Wrapper }
    );

    expect(hidden.result.current.showGatewayMessage).toBe(false);

    hidden.unmount();

    const visible = renderHook(
      () =>
        useKayitGatewayIntent({
          activeTab: "yeni-kayit",
          initialIntent: "personel-zimmet-gateway",
          initialReturnTo: "/personeller/2",
          onClose: vi.fn()
        }),
      { wrapper: Wrapper }
    );

    expect(visible.result.current.showGatewayMessage).toBe(true);
    expect(visible.result.current.gatewayActionLabel).toBe("Personel Kartına dön ve zimmet ekle");
    expect(visible.result.current.gatewayInfoMessage).toContain("Zimmet işlemi merkez ekrana taşınıyor");
  });

  it("uses edit gateway copy for personel-edit-gateway intent", () => {
    const { Wrapper } = createRouterWrapper();

    const { result } = renderHook(
      () =>
        useKayitGatewayIntent({
          activeTab: "yeni-kayit",
          initialIntent: "personel-edit-gateway",
          initialReturnTo: "/personeller/1",
          onClose: vi.fn()
        }),
      { wrapper: Wrapper }
    );

    expect(result.current.gatewayActionLabel).toBe("Personel Kartına dön ve düzenle");
    expect(result.current.gatewayInfoMessage).toContain("Kart düzenleme işlemi merkez ekrana taşınıyor");
  });

  it("handleGatewayReturn closes modal and navigates with zimmet route state", () => {
    const onClose = vi.fn();
    const { Wrapper, getLocation } = createRouterWrapper(["/"]);

    const { result } = renderHook(
      () =>
        useKayitGatewayIntent({
          activeTab: "yeni-kayit",
          initialIntent: "personel-zimmet-gateway",
          initialReturnTo: "/personeller/2",
          onClose
        }),
      { wrapper: Wrapper }
    );

    act(() => {
      result.current.handleGatewayReturn();
    });

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(getLocation().pathname).toBe("/personeller/2");
    expect(getLocation().state).toEqual({ openPersonelZimmet: true });
  });

  it("handleGatewayReturn navigates with edit route state", () => {
    const onClose = vi.fn();
    const { Wrapper, getLocation } = createRouterWrapper(["/"]);

    const { result } = renderHook(
      () =>
        useKayitGatewayIntent({
          activeTab: "yeni-kayit",
          initialIntent: "personel-edit-gateway",
          initialReturnTo: "/personeller/1",
          onClose
        }),
      { wrapper: Wrapper }
    );

    act(() => {
      result.current.handleGatewayReturn();
    });

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(getLocation().pathname).toBe("/personeller/1");
    expect(getLocation().state).toEqual({ openPersonelEdit: true });
  });

  it("handleGatewayReturn is no-op without returnTo", () => {
    const onClose = vi.fn();
    const { Wrapper, getLocation } = createRouterWrapper(["/"]);

    const { result } = renderHook(
      () =>
        useKayitGatewayIntent({
          activeTab: "yeni-kayit",
          initialIntent: "personel-edit-gateway",
          initialReturnTo: null,
          onClose
        }),
      { wrapper: Wrapper }
    );

    act(() => {
      result.current.handleGatewayReturn();
    });

    expect(onClose).not.toHaveBeenCalled();
    expect(getLocation().pathname).toBe("/");
  });
});
