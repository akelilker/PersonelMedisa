import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersoneller } from "../../../hooks/usePersoneller";
import { useAuth } from "../../../state/auth.store";
import { CALISAN_KAPSAMI_SELECT_OPTIONS, formatCalisanKapsamiLabel } from "../../../lib/display/enum-display";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";
import { formatReferenceValue } from "../components/personel-dosya/personel-dosya-format-utils";
import { getPersonelMissingFields, resolvePersonelCompleteness } from "../personel-missing-info";
import { PERSONEL_SEARCH_MAX_LENGTH } from "../personel-search-query";
import type { PersonelKartPhase } from "../personel-kart-nav";
import { usePersonelScopeCounts } from "../hooks/usePersonelScopeCounts";
import { mapCalismaLokasyonuDisplayOptions } from "../personel-create-org-deps";
import type { PersonelListSortKey } from "../../../hooks/usePersoneller";

function IconSearch(props: { className?: string }) {
  return (
    <svg className={props.className} viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
      <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" strokeWidth="2" />
      <path d="m20 20-3.5-3.5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function IconFilter(props: { className?: string }) {
  return (
    <svg className={props.className} viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
      <path d="M4 5h16l-6 7v5l-4 2v-7L4 5z" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    </svg>
  );
}

function IconArchive(props: { className?: string }) {
  return (
    <svg className={props.className} viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
      <rect x="2" y="3" width="20" height="5" rx="1" fill="none" stroke="currentColor" strokeWidth="2" />
      <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" fill="none" stroke="currentColor" strokeWidth="2" />
      <path d="M10 12h4" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function toSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

function formatPersonelName(personel: Pick<Personel, "ad" | "soyad">) {
  return [personel.ad, personel.soyad].map((part) => String(part ?? "").trim()).filter(Boolean).join(" ");
}

function orgSecondaryLine(personel: Personel) {
  const gorev = formatReferenceValue(personel.gorev_adi, personel.gorev_id);
  const sube = personel.sube_adi?.trim();
  if (gorev !== "-" && sube) return `${gorev} · ${sube}`;
  if (sube) return sube;
  return gorev;
}

function bolumBirimLine(personel: Personel) {
  const bolum = formatReferenceValue(personel.bolum_adi, personel.bolum_id);
  const birim = formatReferenceValue(personel.birim_adi, personel.birim_id);
  if (bolum !== "-" && birim !== "-") return `${bolum} / ${birim}`;
  if (bolum !== "-") return bolum;
  if (birim !== "-") return birim;
  const dept = formatReferenceValue(personel.departman_adi, personel.departman_id);
  return dept;
}

export function PersonellerPage() {
  const {
    listQuery,
    personeller,
    hasNextPage,
    totalPages,
    isLoading,
    isRefreshing,
    isCurrentQueryResolved,
    errorMessage,
    refetch,
    refs,
    submitFilters,
    clearFilters,
    setDraftSearch,
    setSearchComposing,
    setDraftAktiflik,
    setDraftDepartmanId,
    setDraftPersonelTipiId,
    setDraftCalisanKapsami,
    setDraftCalismaLokasyonuId,
    setDraftEksikBilgi,
    setPage,
    sortKey,
    sortDir,
    setSort
  } = usePersoneller();

  const { session, setActiveSubeId } = useAuth();
  const { hasPermission } = useRoleAccess();
  const canOpenDetail = hasPermission("personeller.detail.view");
  const canViewArsiv = hasPermission("arsiv.view");
  const location = useLocation();
  const navigate = useNavigate();

  const [searchExpanded, setSearchExpanded] = useState(false);
  const [filterExpanded, setFilterExpanded] = useState(false);
  /** null = TÜMÜ last selected; undefined = no chrome yet */
  const [selectedScopeId, setSelectedScopeId] = useState<number | null | undefined>(undefined);
  const [searchParams, setSearchParams] = useSearchParams();

  const { draft, applied } = listQuery;
  const isArchiveRoute = location.pathname.startsWith("/arsiv/personeller");
  const isArchiveMode = canViewArsiv && (isArchiveRoute || draft.aktiflik === "pasif");
  const page = listQuery.page;
  const departmanFilterOptions = toSelectOptions(refs.departmanOptions);
  const personelTipiFilterOptions = toSelectOptions(refs.personelTipiOptions);
  // "Fabrikada kimler çalışıyor?": fiili çalışma yeri filtresi. Bordro/SGK
  // kaynağı farklı olsa bile fiilen o lokasyonda çalışan personel listelenir.
  const calismaLokasyonuFilterOptions = useMemo(
    () =>
      mapCalismaLokasyonuDisplayOptions(refs.calismaLokasyonuOptions).map((option) => ({
        value: String(option.id),
        label: option.label
      })),
    [refs.calismaLokasyonuOptions]
  );

  const branches = session?.sube_list ?? [];
  const multiScope = branches.length > 1;
  const scopeAll = searchParams.get("scope") === "all" || session?.active_sube_id == null;
  const showScopeColumn = multiScope && scopeAll && !isArchiveMode;

  const phase: PersonelKartPhase = (() => {
    if (isArchiveRoute) return "list";
    const view = searchParams.get("view");
    if (view === "search") return "search";
    if (view === "list") return "list";
    if (view === "scope" && multiScope) return "scope";
    return multiScope ? "scope" : "list";
  })();

  function setPhase(next: PersonelKartPhase) {
    const nextParams = new URLSearchParams(searchParams);
    if (next === "scope" && multiScope) {
      nextParams.delete("view");
    } else {
      nextParams.set("view", next);
    }
    setSearchParams(nextParams, { replace: true });
  }

  useEffect(() => {
    if (!canViewArsiv) return;
    const target = isArchiveRoute ? "pasif" : "aktif";
    if (draft.aktiflik !== target) setDraftAktiflik(target);
  }, [canViewArsiv, draft.aktiflik, isArchiveRoute, setDraftAktiflik]);

  const scopeEnabled = multiScope && phase === "scope" && !isArchiveRoute;
  const { counts: scopeCounts, loading: scopeCountsLoading } = usePersonelScopeCounts(branches, scopeEnabled);

  const tableShellRef = useRef<HTMLDivElement | null>(null);
  const tableWrapRef = useRef<HTMLDivElement | null>(null);

  /**
   * Mobilde tablo yatay kaydırılabilir: sağda devam eden kolon olduğunu
   * shell üzerindeki data-scroll-more ile bildirir (fade yalnız bu durumda görünür).
   */
  useEffect(() => {
    const shell = tableShellRef.current;
    const wrap = tableWrapRef.current;
    if (!shell || !wrap) {
      return;
    }

    const syncScrollMore = () => {
      const remaining = wrap.scrollWidth - wrap.clientWidth - wrap.scrollLeft;
      shell.dataset.scrollMore = remaining > 1 ? "true" : "false";
    };

    syncScrollMore();
    wrap.addEventListener("scroll", syncScrollMore, { passive: true });
    window.addEventListener("resize", syncScrollMore);
    const resizeObserver =
      typeof ResizeObserver === "undefined" ? null : new ResizeObserver(() => syncScrollMore());
    resizeObserver?.observe(wrap);
    if (wrap.firstElementChild) {
      resizeObserver?.observe(wrap.firstElementChild);
    }

    return () => {
      wrap.removeEventListener("scroll", syncScrollMore);
      window.removeEventListener("resize", syncScrollMore);
      resizeObserver?.disconnect();
    };
  }, [errorMessage, isLoading, personeller, phase, showScopeColumn]);

  function toggleSort(key: PersonelListSortKey) {
    setSort(key);
  }

  function openDetail(personelId: number, from: "list" | "search") {
    const backLabel = from === "search" ? "Arama Sonuçları" : "Personel Listesi";
    void navigate(`/personeller/${personelId}`, {
      state: {
        personelKartBack: {
          to: `${location.pathname}${location.search}`,
          label: backLabel
        }
      }
    });
  }

  function enterListFromScope(subeId: number | null) {
    setSelectedScopeId(subeId);
    setActiveSubeId(subeId);
    const nextParams = new URLSearchParams(searchParams);
    nextParams.set("view", "list");
    if (subeId === null) {
      nextParams.set("scope", "all");
    } else {
      nextParams.delete("scope");
    }
    setSearchParams(nextParams, { replace: true });
    setSearchExpanded(false);
    setFilterExpanded(false);
    clearFilters();
  }

  function openGlobalSearch() {
    if (multiScope) {
      setActiveSubeId(null);
      setSelectedScopeId(null);
    }
    setPhase("search");
    setSearchExpanded(true);
    setFilterExpanded(false);
  }

  const showInternalBack = !isArchiveRoute && multiScope && (phase === "list" || phase === "search");

  function onInternalBack() {
    if (phase === "search") {
      setPhase(multiScope ? "scope" : "list");
      setSearchExpanded(false);
      setDraftSearch("");
      clearFilters();
      if (multiScope) {
        setActiveSubeId(null);
      }
      return;
    }
    if (phase === "list" && multiScope) {
      const nextParams = new URLSearchParams(searchParams);
      nextParams.delete("view");
      nextParams.delete("scope");
      setSearchParams(nextParams, { replace: true });
      setFilterExpanded(false);
      setActiveSubeId(null);
    }
  }

  const emptyTitle = isArchiveMode
    ? "Arşivde personel bulunamadı."
    : phase === "search"
      ? "Aramanızla eşleşen personel bulunamadı."
      : "Bu şubede aktif personel bulunamadı.";

  const emptyMessage = isArchiveMode
    ? "Aktif olmayan personeller burada listelenir."
    : phase === "search"
      ? "Farklı bir arama deneyin veya şube seçerek listeye dönün."
      : "Kayıt yok veya filtre sonucu boş.";

  const archiveNavLabel = isArchiveRoute ? "Aktif" : "Arşiv";
  const searchOnlyPanel = searchExpanded && !filterExpanded;

  return (
    <section className="personeller-page personeller-page--kart" aria-labelledby="personeller-page-heading">
      <h2 id="personeller-page-heading" className="personeller-sr-only">
        Personel Kartı
      </h2>

      {isArchiveMode ? (
        <p className="personeller-archive-banner" data-testid="personeller-arsiv-banner" role="status">
          Arşiv — Medisa saklama politikası
        </p>
      ) : null}

      {/* Single toolbar row (Taşıt vehicles-toolbar): left back / empty, right icons */}
      <div className="personeller-toolbar personeller-toolbar--kart vehicles-toolbar" data-testid="personeller-toolbar">
        <div className="personeller-toolbar-main vt-left-right">
          <div className="personeller-toolbar-left vt-left">
            {showInternalBack ? (
              <button
                type="button"
                className="universal-back-btn personeller-toolbar-back"
                onClick={onInternalBack}
                data-testid="personeller-internal-back"
              >
                <svg
                  className="back-icon-svg"
                  xmlns="http://www.w3.org/2000/svg"
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden="true"
                >
                  <path d="M19 12H5" />
                  <path d="m12 19-7-7 7-7" />
                </svg>
                <span className="universal-back-label">Şubeler</span>
              </button>
            ) : null}
          </div>
          <div className="personeller-toolbar-right vt-right">
            <button
              type="button"
              className="personeller-icon-btn vt-icon-btn"
              aria-expanded={searchExpanded}
              aria-label="Ara"
              title="Ara"
              data-testid="personeller-search-toggle"
              onClick={() => {
                if (phase === "scope") openGlobalSearch();
                else setSearchExpanded((open) => !open);
              }}
            >
              <IconSearch />
            </button>
            {phase === "list" || phase === "search" ? (
              <button
                type="button"
                className="personeller-icon-btn vt-icon-btn"
                aria-expanded={filterExpanded}
                aria-label="Filtre"
                title="Filtre"
                data-testid="personeller-filter-toggle"
                onClick={() => setFilterExpanded((open) => !open)}
              >
                <IconFilter />
              </button>
            ) : null}
            {canViewArsiv ? (
              <Link
                to={isArchiveRoute ? "/personeller" : "/arsiv/personeller"}
                className={`personeller-icon-btn vt-icon-btn${isArchiveRoute ? " personeller-icon-btn--active" : ""}`}
                aria-label={archiveNavLabel}
                title={archiveNavLabel}
                aria-current={isArchiveRoute ? "page" : undefined}
                data-testid="personeller-archive-nav"
              >
                <IconArchive />
                {/* Görsel ikon-only toolbar; canonical "Arşiv" giriş metni DOM'da korunur. */}
                <span className="personeller-sr-only">Arşiv</span>
              </Link>
            ) : null}
          </div>
        </div>
      </div>

      <SubeDetailListNotice />

      {searchExpanded || filterExpanded ? (
        <form
          id="personeller-filter-form"
          className={`personeller-filter-panel${searchOnlyPanel ? " personeller-filter-panel--search-only" : ""}`}
          onSubmit={submitFilters}
        >
          {searchExpanded ? (
            <div className="personeller-filter-search form-field-grid">
              <FormField
                label="Ara"
                name="personel-filter-search"
                type="search"
                placeholder="Ad soyad, kimlik no, görev, bölüm, birim, şube"
                autoComplete="off"
                maxLength={PERSONEL_SEARCH_MAX_LENGTH}
                dataTestId="personeller-search-input"
                value={draft.search}
                onChange={setDraftSearch}
                onCompositionStart={() => setSearchComposing(true)}
                onCompositionEnd={() => setSearchComposing(false)}
                onKeyDown={(event) => {
                  if (event.key === "Enter") {
                    event.preventDefault();
                    submitFilters();
                    if (phase === "scope") setPhase("search");
                  }
                }}
              />
            </div>
          ) : null}

          {filterExpanded && (phase === "list" || phase === "search") ? (
            <div className="personeller-filter-secondary">
              {departmanFilterOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Bölüm / Departman"
                  name="personel-filter-departman"
                  value={draft.departmanId}
                  onChange={setDraftDepartmanId}
                  placeholderOption={{ value: "", label: "Tümü" }}
                  selectOptions={departmanFilterOptions}
                />
              ) : null}
              {personelTipiFilterOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Statü"
                  name="personel-filter-personel-tipi"
                  value={draft.personelTipiId}
                  onChange={setDraftPersonelTipiId}
                  placeholderOption={{ value: "", label: "Tümü" }}
                  selectOptions={personelTipiFilterOptions}
                />
              ) : null}
              <FormField
                as="select"
                label="Çalışan Kapsamı"
                name="personel-filter-calisan-kapsami"
                value={draft.calisanKapsami}
                onChange={(value) => setDraftCalisanKapsami(value as "" | "IC_PERSONEL" | "DIS_KAYNAK")}
                placeholderOption={{ value: "", label: "Tümü" }}
                selectOptions={CALISAN_KAPSAMI_SELECT_OPTIONS}
              />
              {calismaLokasyonuFilterOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Çalışma Lokasyonu"
                  name="personel-filter-calisma-lokasyonu"
                  value={draft.calismaLokasyonuId}
                  onChange={setDraftCalismaLokasyonuId}
                  placeholderOption={{ value: "", label: "Tümü" }}
                  selectOptions={calismaLokasyonuFilterOptions}
                />
              ) : null}
              <FormField
                as="select"
                label="Eksik Bilgi"
                name="personel-filter-eksik-bilgi"
                value={draft.eksikBilgi}
                onChange={(value) => setDraftEksikBilgi(value as "tum" | "eksik")}
                selectOptions={[
                  { value: "tum", label: "Tüm Personeller" },
                  { value: "eksik", label: "Eksik Bilgisi Olanlar" }
                ]}
              />
            </div>
          ) : null}

          <div className="form-actions-row personeller-filter-actions">
            <button type="button" className="universal-btn-aux" onClick={clearFilters}>
              Temizle
            </button>
          </div>
        </form>
      ) : null}

      {phase === "scope" && !isArchiveRoute ? (
        <div className="personeller-scope-stage" data-testid="personeller-scope-stage">
          <div className="personeller-scope-grid" data-testid="personeller-scope-grid">
            <button
              type="button"
              className={`personeller-scope-card${selectedScopeId === null ? " is-selected" : ""}`}
              data-testid="personeller-scope-all"
              aria-pressed={selectedScopeId === null}
              onClick={() => enterListFromScope(null)}
            >
              <span className="personeller-scope-card-title personeller-scope-card-name">TÜMÜ</span>
              <span className="personeller-scope-card-count-block personeller-scope-card-count">
                <span className="personeller-scope-card-count-num">
                  {scopeCountsLoading ? "…" : scopeCounts.all != null ? scopeCounts.all : "—"}
                </span>
                <span className="personeller-scope-card-count-label">Personel</span>
              </span>
            </button>
            {branches.map((branch) => (
              <button
                key={branch.id}
                type="button"
                className={`personeller-scope-card${selectedScopeId === branch.id ? " is-selected" : ""}`}
                data-testid={`personeller-scope-${branch.id}`}
                aria-pressed={selectedScopeId === branch.id}
                onClick={() => enterListFromScope(branch.id)}
              >
                <span className="personeller-scope-card-title personeller-scope-card-name">{branch.ad}</span>
                <span className="personeller-scope-card-count-block personeller-scope-card-count">
                  <span className="personeller-scope-card-count-num">
                    {scopeCountsLoading
                      ? "…"
                      : scopeCounts[String(branch.id)] != null
                        ? scopeCounts[String(branch.id)]
                        : "—"}
                  </span>
                  <span className="personeller-scope-card-count-label">Personel</span>
                </span>
              </button>
            ))}
          </div>
        </div>
      ) : null}

      {phase !== "scope" || isArchiveRoute ? (
        <>
          {isLoading ? <LoadingState label="Personel verileri yükleniyor..." /> : null}
          {isRefreshing ? (
            <p className="personeller-missing-summary" data-testid="personeller-list-refreshing" role="status">
              Sonuçlar güncelleniyor...
            </p>
          ) : null}
          {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void refetch()} /> : null}
          {!isLoading && !isRefreshing && isCurrentQueryResolved && !errorMessage && personeller.length === 0 ? (
            <EmptyState title={emptyTitle} message={emptyMessage} />
          ) : null}

          {!isLoading && !errorMessage && personeller.length > 0 ? (
            <div className="personeller-table-scroll-shell" ref={tableShellRef}>
              <div className="personeller-table-wrap" data-testid="personeller-dense-list" ref={tableWrapRef}>
              <table className={`personeller-table personeller-table--dense${showScopeColumn ? " personeller-table--scope" : ""}`}>
                <colgroup>
                  <col className="personeller-col personeller-col-kimlik" />
                  <col className="personeller-col personeller-col-ad" />
                  {showScopeColumn ? <col className="personeller-col personeller-col-sube" /> : null}
                  <col className="personeller-col personeller-col-bolum" />
                  <col className="personeller-col personeller-col-gorev" />
                  <col className="personeller-col personeller-col-statu" />
                </colgroup>
                <thead>
                  <tr>
                    <th scope="col">T.C. Kimlik</th>
                    <th scope="col">
                      <button type="button" className="personeller-sort-btn" onClick={() => toggleSort("ad")}>
                        Ad Soyad{sortKey === "ad" ? (sortDir === "asc" ? " ↑" : " ↓") : ""}
                      </button>
                    </th>
                    {showScopeColumn ? (
                      <th scope="col">
                        <button type="button" className="personeller-sort-btn" onClick={() => toggleSort("sube")}>
                          Şube{sortKey === "sube" ? (sortDir === "asc" ? " ↑" : " ↓") : ""}
                        </button>
                      </th>
                    ) : null}
                    <th scope="col">
                      <button type="button" className="personeller-sort-btn" onClick={() => toggleSort("bolum")}>
                        Bölüm / Birim{sortKey === "bolum" ? (sortDir === "asc" ? " ↑" : " ↓") : ""}
                      </button>
                    </th>
                    <th scope="col">
                      <button type="button" className="personeller-sort-btn" onClick={() => toggleSort("gorev")}>
                        Görev{sortKey === "gorev" ? (sortDir === "asc" ? " ↑" : " ↓") : ""}
                      </button>
                    </th>
                    <th scope="col">
                      <button type="button" className="personeller-sort-btn" onClick={() => toggleSort("statu")}>
                        Statü{sortKey === "statu" ? (sortDir === "asc" ? " ↑" : " ↓") : ""}
                      </button>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {personeller.map((personel: Personel) => {
                    const name = formatPersonelName(personel);
                    const missingFields = getPersonelMissingFields(personel);
                    const missingCount = resolvePersonelCompleteness(personel).missing_count;
                    const tip = personel.personel_tipi_adi?.trim() || "—";
                    const from: "list" | "search" = phase === "search" || Boolean(applied.search.trim()) ? "search" : "list";
                    const bolumText = bolumBirimLine(personel);
                    const gorevText = formatReferenceValue(personel.gorev_adi, personel.gorev_id);
                    const subeText = personel.sube_adi?.trim() || "—";
                    const kimlik = String(personel.tc_kimlik_no ?? "").trim() || "—";
                    return (
                      <tr
                        key={personel.id}
                        className={canOpenDetail ? "personeller-table-row-clickable" : undefined}
                        onClick={() => {
                          if (canOpenDetail) openDetail(personel.id, from);
                        }}
                        onKeyDown={(event) => {
                          if (!canOpenDetail) return;
                          if (event.key !== "Enter" && event.key !== " ") return;
                          event.preventDefault();
                          openDetail(personel.id, from);
                        }}
                        tabIndex={canOpenDetail ? 0 : undefined}
                        aria-label={canOpenDetail ? `${name} kartını aç` : undefined}
                      >
                        <td className="personeller-tc-cell" title={kimlik}>
                          <span className="personeller-tc-value">{kimlik}</span>
                          {missingCount > 0 ? (
                            <button
                              type="button"
                              className="personeller-missing-bang"
                              title={`Eksik Bilgiler\n${missingFields.map((f) => `• ${f.label}`).join("\n")}`}
                              aria-label={`Eksik bilgiler: ${missingFields.map((f) => f.label).join(", ")}`}
                              onClick={(event) => event.stopPropagation()}
                            >
                              !
                            </button>
                          ) : null}
                        </td>
                        <td className="personeller-table-cell-strong" title={name}>
                          <div className="personeller-name-stack">
                            <span className="personeller-name-primary">{name}</span>
                            {phase === "search" ? (
                              <span className="personeller-name-secondary">{orgSecondaryLine(personel)}</span>
                            ) : null}
                            {personel.calisan_kapsami === "DIS_KAYNAK" ? (
                              <span className="personeller-status-badge">{formatCalisanKapsamiLabel("DIS_KAYNAK")}</span>
                            ) : null}
                          </div>
                        </td>
                        {showScopeColumn ? (
                          <td className="personeller-cell-wrap" title={subeText}>
                            <span>{subeText}</span>
                          </td>
                        ) : null}
                        <td className="personeller-cell-wrap" title={bolumText}>
                          <span>{bolumText}</span>
                        </td>
                        <td className="personeller-cell-wrap" title={gorevText}>
                          <span>{gorevText}</span>
                        </td>
                        <td className="personeller-cell-wrap" title={tip}>
                          <span>{tip}</span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              </div>
            </div>
          ) : null}

          {!isLoading && !errorMessage && personeller.length > 0 && (hasNextPage || page > 1) ? (
            <div className="module-pagination personeller-pagination" data-testid="personeller-pagination">
              <button type="button" className="universal-btn-aux" disabled={page <= 1} onClick={() => setPage(Math.max(1, page - 1))}>
                Önceki
              </button>
              <span className="module-page-info">
                Sayfa {page}
                {totalPages != null ? ` / ${totalPages}` : ""}
              </span>
              <button type="button" className="universal-btn-aux" disabled={!hasNextPage} onClick={() => setPage(page + 1)}>
                Sonraki
              </button>
            </div>
          ) : null}
        </>
      ) : null}
    </section>
  );
}
