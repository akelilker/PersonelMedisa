// @vitest-environment jsdom

import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { Hero } from "../../src/components/hero/Hero";

describe("Hero session identity", () => {
  afterEach(() => cleanup());

  it("renders only the real session user under the logo", () => {
    render(<Hero title="Personel Yönetim Sistemi" userLabel="Birim Amiri" subeLabel="Merkez" />);

    expect(screen.getByTestId("hero-session-user").textContent).toBe("Birim Amiri");
    expect(screen.queryByTestId("hero-session-sube")).toBeNull();
  });

  it("keeps the user label visible when branch metadata is unavailable", () => {
    render(<Hero title="Personel Yönetim Sistemi" userLabel="Genel Yönetici" />);

    expect(screen.getByTestId("hero-session-user").textContent).toBe("Genel Yönetici");
    expect(screen.queryByTestId("hero-session-sube")).toBeNull();
  });
});
