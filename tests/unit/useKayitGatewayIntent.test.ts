/** @vitest-environment jsdom */
import { renderHook } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { useKayitGatewayIntent } from "../../src/features/kayit/hooks/useKayitGatewayIntent";

describe("useKayitGatewayIntent", () => {
  it("routes legacy edit intent to surec genel tab without bounce theater", () => {
    const onSelectPersonelTab = vi.fn();

    const { result } = renderHook(() =>
      useKayitGatewayIntent({
        activeTab: "surec",
        initialIntent: "personel-edit-gateway",
        onSelectPersonelTab
      })
    );

    expect(result.current.showGatewayMessage).toBe(false);
    expect(result.current.legacyPersonelTab).toBe("genel");
    expect(onSelectPersonelTab).toHaveBeenCalledWith("genel");
  });

  it("routes legacy zimmet intent to surec zimmet tab without bounce theater", () => {
    const onSelectPersonelTab = vi.fn();

    const { result } = renderHook(() =>
      useKayitGatewayIntent({
        activeTab: "surec",
        initialIntent: "personel-zimmet-gateway",
        onSelectPersonelTab
      })
    );

    expect(result.current.showGatewayMessage).toBe(false);
    expect(result.current.legacyPersonelTab).toBe("zimmet");
    expect(onSelectPersonelTab).toHaveBeenCalledWith("zimmet");
  });

  it("does not select personel tab when activeTab is yeni-kayit", () => {
    const onSelectPersonelTab = vi.fn();

    renderHook(() =>
      useKayitGatewayIntent({
        activeTab: "yeni-kayit",
        initialIntent: "personel-edit-gateway",
        onSelectPersonelTab
      })
    );

    expect(onSelectPersonelTab).not.toHaveBeenCalled();
  });

  it("handleGatewayReturn is a no-op", () => {
    const { result } = renderHook(() =>
      useKayitGatewayIntent({
        activeTab: "surec",
        initialIntent: "personel-edit-gateway"
      })
    );

    expect(() => result.current.handleGatewayReturn()).not.toThrow();
  });
});
