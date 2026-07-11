// @vitest-environment jsdom

import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { Hero } from "../../src/components/hero/Hero";

describe("Hero session identity", () => {
  afterEach(() => cleanup());

  it("renders the real session user and active branch labels", () => {
    render(<Hero title="Personel Yönetim Sistemi" userLabel="Birim Amiri" subeLabel="Merkez" />);

    expect(screen.getByTestId("hero-session-user").textContent).toBe("Birim Amiri");
    expect(screen.getByTestId("hero-session-sube").textContent).toBe("Merkez");
  });

  it("does not invent a branch label when active branch metadata is unavailable", () => {
    render(<Hero title="Personel Yönetim Sistemi" userLabel="Genel Yönetici" />);

    expect(screen.getByTestId("hero-session-user").textContent).toBe("Genel Yönetici");
    expect(screen.queryByTestId("hero-session-sube")).toBeNull();
  });
});
