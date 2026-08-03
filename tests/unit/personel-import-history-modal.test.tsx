// @vitest-environment jsdom

import "@testing-library/jest-dom/vitest";
import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { cleanup, render, screen, fireEvent, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { PersonelImportHistoryModal } from "../../src/features/personeller/components/PersonelImportHistoryModal";

const listMock = vi.fn();
const detailMock = vi.fn();
const evidenceMock = vi.fn();

vi.mock("../../src/api/personeller.api", () => ({
  listPersonelImportRuns: (...args: unknown[]) => listMock(...args),
  getPersonelImportRunDetail: (...args: unknown[]) => detailMock(...args),
  downloadPersonelImportEvidenceCsv: (...args: unknown[]) => evidenceMock(...args)
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: {
      active_sube_id: 1,
      sube_list: [{ id: 1, ad: "Merkez" }]
    }
  })
}));

describe("PersonelImportHistoryModal", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
  });

  it("shows empty state without write actions", async () => {
    listMock.mockResolvedValue({ items: [], next_cursor: null });

    render(
      <MemoryRouter>
        <PersonelImportHistoryModal open onClose={() => undefined} />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByTestId("personel-import-history-empty")).toHaveTextContent(
        "Henüz tamamlanmış veya başarısız bir personel import işlemi bulunmuyor."
      );
    });
    expect(screen.queryByText(/Retry|Tekrar çalıştır|Sil|İptal|Personelleri Sisteme Aktar/i)).toBeNull();
    expect(listMock).toHaveBeenCalled();
  });

  it("lists synthetic run, opens detail with masked TC, downloads evidence", async () => {
    listMock.mockResolvedValue({
      items: [
        {
          import_id: 91,
          status: "COMPLETED",
          status_label: "Tamamlandı",
          schema_version: "personel-import-v1",
          import_mode: "CREATE_ONLY_ALL_OR_NOTHING",
          row_count: 1,
          valid_row_count: 1,
          created_count: 1,
          actor_id: 1,
          actor_display_name: "Test Actor",
          scope_summary: "Merkez",
          active_sube_id: 1,
          source_sha256: "a".repeat(64),
          manifest_hash: "b".repeat(64),
          idempotency_fingerprint: "abcdef123456",
          created_at: "2026-08-01 10:00:00",
          completed_at: "2026-08-01 10:00:01",
          failed_at: null,
          duration_ms: 1000,
          failure_code: null
        }
      ],
      next_cursor: null
    });
    detailMock.mockResolvedValue({
      import_id: 91,
      status: "COMPLETED",
      status_label: "Tamamlandı",
      schema_version: "personel-import-v1",
      import_mode: "CREATE_ONLY_ALL_OR_NOTHING",
      row_count: 1,
      valid_row_count: 1,
      created_count: 1,
      failed_row_count: 0,
      actor_id: 1,
      actor_display_name: "Test Actor",
      scope_summary: "Merkez",
      active_sube_id: 1,
      source_sha256: "a".repeat(64),
      manifest_hash: "b".repeat(64),
      idempotency_fingerprint: "abcdef123456",
      created_at: "2026-08-01 10:00:00",
      completed_at: "2026-08-01 10:00:01",
      failed_at: null,
      duration_ms: 1000,
      failure_code: null,
      failure_message: null,
      idempotent_replay: null,
      satirlar: [
        {
          row_number: 1,
          personel_id: 501,
          sicil_no: "S1",
          tc_kimlik_no_masked: "123******45",
          row_hash: "a".repeat(64),
          row_status: "CREATED",
          personel_display_name: "Ayşe Yılmaz",
          ad_soyad: "Ayşe Yılmaz",
          personel_detail_path: "/personeller/501"
        }
      ]
    });
    evidenceMock.mockResolvedValue(undefined);

    render(
      <MemoryRouter>
        <PersonelImportHistoryModal open onClose={() => undefined} />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByTestId("personel-import-history-list")).toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId("personel-import-history-open-91"));

    await waitFor(() => {
      expect(screen.getByTestId("personel-import-history-detail")).toBeInTheDocument();
    });
    expect(screen.getByText("123******45")).toBeInTheDocument();
    expect(screen.queryByText(/12345678901/)).toBeNull();
    expect(screen.queryByText(/pir-/)).toBeNull();

    fireEvent.click(screen.getByTestId("personel-import-history-evidence"));
    await waitFor(() => {
      expect(evidenceMock).toHaveBeenCalledWith(91);
    });
  });

  it("shows schema-not-ready error without crashing", async () => {
    const { ApiRequestError } = await import("../../src/api/api-client");
    listMock.mockRejectedValue(
      new ApiRequestError("Personel import şeması henüz hazır değil.", 409, {
        code: "SCHEMA_NOT_READY",
        field: undefined
      })
    );

    render(
      <MemoryRouter>
        <PersonelImportHistoryModal open onClose={() => undefined} />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByTestId("personel-import-history-error")).toBeInTheDocument();
    });
    expect(screen.getByText(/şeması henüz hazır değil/i)).toBeInTheDocument();
    expect(screen.queryByTestId("personel-import-history-retry")).toBeNull();
  });
});
