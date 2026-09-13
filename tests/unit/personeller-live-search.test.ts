/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import { ApiRequestError } from "../../src/api/api-client";
import {
  PERSONEL_SEARCH_DEBOUNCE_MS,
  PERSONEL_SEARCH_MAX_LENGTH,
  normalizePersonelSearchQuery,
  personelSearchMatches
} from "../../src/features/personeller/personel-search-query";
import { usePersoneller } from "../../src/hooks/usePersoneller";
import type { Personel } from "../../src/types/personel";

const personellerApiMock = vi.hoisted(() => ({
  createPersonel: vi.fn(),
  fetchPersonelDetail: vi.fn(),
  fetchPersonellerList: vi.fn(),
  updatePersonel: vi.fn()
}));

vi.mock("../../src/api/personeller.api", () => personellerApiMock);

vi.mock("../../src/api/referans.api", () => ({
  fetchBagliAmirOptions: vi.fn(async () => []),
  fetchBirimOptions: vi.fn(async () => []),
  fetchBolumOptions: vi.fn(async () => []),
  fetchDepartmanOptions: vi.fn(async () => []),
  fetchGorevOptions: vi.fn(async () => []),
  fetchPersonelTipiOptions: vi.fn(async () => []),
  fetchPozisyonOptions: vi.fn(async () => []),
  fetchPrimKuraliOptions: vi.fn(async () => []),
  fetchUcretTipiOptions: vi.fn(async () => [])
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({ session: { active_sube_id: 1 } })
}));

type ListParams = {
  search?: string;
  page?: number;
  calisan_kapsami?: string;
  signal?: AbortSignal;
};

function personel(id: number, ad: string, soyad: string): Personel {
  return {
    id,
    tc_kimlik_no: String(id).padStart(11, "0"),
    ad,
    soyad,
    aktif_durum: "AKTIF"
  } as Personel;
}

function listResult(items: Personel[]) {
  return {
    items,
    pagination: {
      page: 1,
      limit: 10,
      total: items.length,
      totalPages: 1,
      hasNextPage: false,
      hasPreviousPage: false
    },
    missingPersonelTotal: 0
  };
}

const ILKER = personel(212, "İlker", "AKEL");

function resetAppDataCache(): void {
  window.appData = {
    schemaVersion: APP_DATA_SCHEMA_VERSION,
    revision: 0,
    updatedAt: null,
    cache: {}
  };
}

/** Search values the list API was actually called with, in order. */
function searchCalls(): (string | undefined)[] {
  return personellerApiMock.fetchPersonellerList.mock.calls.map(
    (call: [ListParams]) => call[0]?.search
  );
}

function callParams(): ListParams[] {
  return personellerApiMock.fetchPersonellerList.mock.calls.map((call: [ListParams]) => call[0]);
}

beforeEach(() => {
  vi.clearAllMocks();
  resetAppDataCache();
  personellerApiMock.fetchPersonellerList.mockImplementation(async () => listResult([ILKER]));
  vi.useFakeTimers({ shouldAdvanceTime: true });
});

afterEach(() => {
  vi.useRealTimers();
});

async function settle(): Promise<void> {
  await act(async () => {
    await Promise.resolve();
  });
}

async function advanceDebounce(extra = 25): Promise<void> {
  await act(async () => {
    vi.advanceTimersByTime(PERSONEL_SEARCH_DEBOUNCE_MS + extra);
    await Promise.resolve();
  });
}

async function renderPersoneller() {
  const view = renderHook(() => usePersoneller());
  await waitFor(() => {
    expect(personellerApiMock.fetchPersonellerList).toHaveBeenCalled();
  });
  await settle();
  return view;
}

describe("normalizePersonelSearchQuery", () => {
  it("collapses unicode whitespace and trims", () => {
    expect(normalizePersonelSearchQuery("  İlker\u00a0\u00a0 Akel  ")).toBe("İlker Akel");
    expect(normalizePersonelSearchQuery("\t\n ")).toBe("");
  });

  it("clamps to the backend maximum length", () => {
    expect(normalizePersonelSearchQuery("a".repeat(500))).toHaveLength(PERSONEL_SEARCH_MAX_LENGTH);
  });
});

describe("personelSearchMatches (demo/e2e mock parity with the SQL predicate)", () => {
  const ilker = {
    ad: "İlker",
    soyad: "AKEL",
    sicil_no: "473",
    tc_kimlik_no: "12345678901",
    telefon: "05551112233"
  };

  it.each([
    "İlker Akel",
    "Akel İlker",
    "ilk ak",
    "İLKER AKEL",
    "ilker akel",
    "ILKER AKEL",
    "  İlker    Akel  ",
    "473",
    "Akel 473",
    "12345678901",
    "5551112"
  ])("matches %s", (needle) => {
    expect(personelSearchMatches(ilker, needle)).toBe(true);
  });

  it("ANDs tokens so two different people never match one row", () => {
    expect(personelSearchMatches(ilker, "Akel Yilmaz")).toBe(false);
  });

  it("folds Turkish characters the way the database collation does", () => {
    const sule = { ad: "Şule", soyad: "ÇAĞLAR", sicil_no: "101" };
    expect(personelSearchMatches(sule, "sule")).toBe(true);
    expect(personelSearchMatches(sule, "sule caglar")).toBe(true);
    const isik = { ad: "Irmak", soyad: "IŞIK", sicil_no: "103" };
    expect(personelSearchMatches(isik, "isik")).toBe(true);
    expect(personelSearchMatches(isik, "ısık")).toBe(true);
  });

  it("treats an empty or whitespace-only query as no filter", () => {
    expect(personelSearchMatches(ilker, "")).toBe(true);
    expect(personelSearchMatches(ilker, "   ")).toBe(true);
  });
});

describe("Personeller live search", () => {
  it("does not query while the debounce is pending and queries once it elapses", async () => {
    const view = await renderPersoneller();
    const initialCalls = personellerApiMock.fetchPersonellerList.mock.calls.length;

    act(() => {
      view.result.current.setDraftSearch("İ");
    });
    await settle();
    expect(personellerApiMock.fetchPersonellerList.mock.calls.length).toBe(initialCalls);

    await advanceDebounce();
    await waitFor(() => {
      expect(searchCalls()).toContain("İ");
    });
  });

  it("applies only the final value when the user types fast", async () => {
    const view = await renderPersoneller();
    const before = personellerApiMock.fetchPersonellerList.mock.calls.length;

    for (const value of ["İ", "İl", "İlk", "İlke", "İlker", "İlker ", "İlker A", "İlker Akel"]) {
      act(() => {
        view.result.current.setDraftSearch(value);
      });
      await act(async () => {
        vi.advanceTimersByTime(40);
      });
    }

    await advanceDebounce();
    await waitFor(() => {
      expect(searchCalls()).toContain("İlker Akel");
    });

    const issued = searchCalls().slice(before);
    expect(issued).toEqual(["İlker Akel"]);
  });

  it("Enter skips the remaining debounce and does not double fetch", async () => {
    const view = await renderPersoneller();
    const before = personellerApiMock.fetchPersonellerList.mock.calls.length;

    act(() => {
      view.result.current.setDraftSearch("Akel İlker");
    });
    act(() => {
      view.result.current.submitFilters();
    });
    await settle();

    await waitFor(() => {
      expect(searchCalls()).toContain("Akel İlker");
    });

    // The cancelled debounce must not fire a second request afterwards.
    await advanceDebounce();
    expect(searchCalls().slice(before)).toEqual(["Akel İlker"]);
  });

  it("returns to the default list immediately when the input is cleared", async () => {
    const view = await renderPersoneller();

    act(() => {
      view.result.current.setDraftSearch("ilk ak");
    });
    await advanceDebounce();
    await waitFor(() => {
      expect(searchCalls()).toContain("ilk ak");
    });

    act(() => {
      view.result.current.setDraftSearch("");
    });
    await settle();

    await waitFor(() => {
      expect(view.result.current.listQuery.applied.search).toBe("");
    });
    expect(searchCalls().at(-1)).toBeUndefined();
  });

  it("does not refetch when only surrounding whitespace changes", async () => {
    const view = await renderPersoneller();

    act(() => {
      view.result.current.setDraftSearch("473");
    });
    await advanceDebounce();
    await waitFor(() => {
      expect(searchCalls()).toContain("473");
    });

    const after473 = personellerApiMock.fetchPersonellerList.mock.calls.length;
    act(() => {
      view.result.current.setDraftSearch("  473   ");
    });
    await advanceDebounce();
    expect(personellerApiMock.fetchPersonellerList.mock.calls.length).toBe(after473);
    expect(view.result.current.listQuery.applied.search).toBe("473");
  });

  it("applies a dropdown filter instantly and resets pagination to page 1", async () => {
    const view = await renderPersoneller();

    act(() => {
      view.result.current.setPage(3);
    });
    await settle();
    await waitFor(() => {
      expect(view.result.current.listQuery.page).toBe(3);
    });

    act(() => {
      view.result.current.setDraftCalisanKapsami("DIS_KAYNAK");
    });
    await settle();

    expect(view.result.current.listQuery.applied.calisanKapsami).toBe("DIS_KAYNAK");
    expect(view.result.current.listQuery.page).toBe(1);
    await waitFor(() => {
      expect(
        callParams().some((p) => p.calisan_kapsami === "DIS_KAYNAK" && p.page === 1)
      ).toBe(true);
    });
  });

  it("a pending search is committed together with an instant filter change", async () => {
    const view = await renderPersoneller();

    act(() => {
      view.result.current.setDraftSearch("Akel");
    });
    act(() => {
      view.result.current.setDraftEksikBilgi("eksik");
    });
    await settle();

    expect(view.result.current.listQuery.applied.search).toBe("Akel");
    expect(view.result.current.listQuery.applied.eksikBilgi).toBe("eksik");
  });

  it("search text is not queried mid IME composition", async () => {
    const view = await renderPersoneller();
    const before = personellerApiMock.fetchPersonellerList.mock.calls.length;

    act(() => {
      view.result.current.setSearchComposing(true);
    });
    act(() => {
      view.result.current.setDraftSearch("il");
    });
    await advanceDebounce();
    expect(personellerApiMock.fetchPersonellerList.mock.calls.length).toBe(before);

    act(() => {
      view.result.current.setDraftSearch("ilker");
    });
    act(() => {
      view.result.current.setSearchComposing(false);
    });
    await advanceDebounce();

    await waitFor(() => {
      expect(searchCalls()).toContain("ilker");
    });
    expect(searchCalls().slice(before)).toEqual(["ilker"]);
  });

  it("a slow superseded response never overwrites the newer result", async () => {
    const other = personel(9, "Ayse", "YILMAZ");
    let releaseSlow: (() => void) | null = null;

    personellerApiMock.fetchPersonellerList.mockImplementation(async (params: ListParams) => {
      if (params.search === "ak") {
        await new Promise<void>((resolve) => {
          releaseSlow = resolve;
        });
        return listResult([other]);
      }
      return listResult([ILKER]);
    });

    const view = await renderPersoneller();

    act(() => {
      view.result.current.setDraftSearch("ak");
    });
    await advanceDebounce();
    await waitFor(() => {
      expect(searchCalls()).toContain("ak");
    });

    // Newer query wins while the older one is still in flight.
    act(() => {
      view.result.current.setDraftSearch("İlker Akel");
    });
    await advanceDebounce();
    await waitFor(() => {
      expect(view.result.current.personeller.map((p) => p.id)).toEqual([212]);
    });

    await act(async () => {
      releaseSlow?.();
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(view.result.current.personeller.map((p) => p.id)).toEqual([212]);
    expect(view.result.current.listQuery.applied.search).toBe("İlker Akel");
  });

  it("an aborted request does not surface an error state", async () => {
    personellerApiMock.fetchPersonellerList.mockImplementation(async (params: ListParams) => {
      if (params.search === "ak") {
        throw new ApiRequestError("İstek iptal edildi.", 0, { code: "REQUEST_ABORTED" });
      }
      return listResult([ILKER]);
    });

    const view = await renderPersoneller();

    act(() => {
      view.result.current.setDraftSearch("ak");
    });
    await advanceDebounce();
    await waitFor(() => {
      expect(searchCalls()).toContain("ak");
    });
    await settle();

    expect(view.result.current.errorMessage).toBeNull();
  });

  it("keeps the previous rows visible instead of blanking the list while refetching", async () => {
    let releaseNext: (() => void) | null = null;
    personellerApiMock.fetchPersonellerList.mockImplementation(async (params: ListParams) => {
      if (params.search === "zzz") {
        await new Promise<void>((resolve) => {
          releaseNext = resolve;
        });
        return listResult([]);
      }
      return listResult([ILKER]);
    });

    const view = await renderPersoneller();
    expect(view.result.current.personeller.map((p) => p.id)).toEqual([212]);

    act(() => {
      view.result.current.setDraftSearch("zzz");
    });
    await advanceDebounce();

    // In flight: rows stay, only the refreshing flag is on, no empty state yet.
    expect(view.result.current.personeller.map((p) => p.id)).toEqual([212]);
    expect(view.result.current.isRefreshing).toBe(true);
    expect(view.result.current.isLoading).toBe(false);

    await act(async () => {
      releaseNext?.();
      await Promise.resolve();
      await Promise.resolve();
    });

    await waitFor(() => {
      expect(view.result.current.personeller).toEqual([]);
    });
    expect(view.result.current.isCurrentQueryResolved).toBe(true);
    expect(view.result.current.isRefreshing).toBe(false);
  });

  it("clearFilters restores the default query in a single fetch", async () => {
    const view = await renderPersoneller();

    act(() => {
      view.result.current.setDraftCalisanKapsami("DIS_KAYNAK");
    });
    act(() => {
      view.result.current.setDraftSearch("akel");
    });
    await advanceDebounce();
    await waitFor(() => {
      expect(view.result.current.listQuery.applied.search).toBe("akel");
    });

    const before = personellerApiMock.fetchPersonellerList.mock.calls.length;
    act(() => {
      view.result.current.clearFilters();
    });
    await advanceDebounce();

    expect(view.result.current.listQuery.applied).toEqual({
      search: "",
      aktiflik: "aktif",
      departmanId: "",
      personelTipiId: "",
      calisanKapsami: "",
      calismaLokasyonuId: "",
      eksikBilgi: "tum"
    });
    expect(personellerApiMock.fetchPersonellerList.mock.calls.length).toBeLessThanOrEqual(before + 1);
  });
});
