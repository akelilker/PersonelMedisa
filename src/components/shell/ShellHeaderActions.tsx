import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { shouldPreferDemoApi } from "../../api/api-client";
import { fetchSelfDuyurular } from "../../api/self-product.api";
import { getAppData, useAppDataRevision } from "../../data/data-manager";
import { BugunPersonelDurumuModal } from "../../features/bildirimler/components/BugunPersonelDurumuModal";
import { useBildirimlerHeaderPreview } from "../../hooks/useBildirimler";
import { useRoleAccess } from "../../hooks/use-role-access";
import {
  formatHeaderGunlukTamamlamaCopy,
  formatHeaderReminderCopy
} from "../../lib/bildirim/header-notification-copy";
import { canonicalizeUserRole } from "../../lib/authorization/canonicalize-user-role";
import { useAuth } from "../../state/auth.store";
import { GLOBAL_SCOPE_ROLES } from "../../types/auth";
import { ackInboxPopup, fetchInboxNotifications, type InboxNotification } from "../../api/attendance-mobile.api";
import { fetchBugunPersonelDurumu } from "../../api/bildirimler.api";
import {
  OPEN_BUGUN_PERSONEL_DURUMU_EVENT,
  REFRESH_BUGUN_PERSONEL_DURUMU_EVENT
} from "../../lib/bildirim/bugun-personel-durumu-events";
import { istanbulBusinessDate } from "../../features/self-service/birim-amiri-operational";

type NotificationLevel = "neutral" | "warning" | "critical";

type HeaderNotification = {
  id: string;
  title: string;
  subtitle: string;
  level: NotificationLevel;
  route: string;
  unread: boolean;
};

type ShellHeaderActionsProps = {
  contextLabel: string;
  minimal?: boolean;
};

const DAY_MS = 24 * 60 * 60 * 1000;
const TR_LOCALE = "tr-TR";

function startOfDay(date: Date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function formatDate(date: Date) {
  return new Intl.DateTimeFormat(TR_LOCALE, {
    day: "2-digit",
    month: "2-digit",
    year: "numeric"
  }).format(date);
}

function formatSyncLabel(updatedAt: string | null) {
  if (!updatedAt) {
    return "Veri hazır";
  }

  const parsed = new Date(updatedAt);
  if (Number.isNaN(parsed.getTime())) {
    return "Veri hazır";
  }

  return `Son veri ${new Intl.DateTimeFormat(TR_LOCALE, {
    hour: "2-digit",
    minute: "2-digit"
  }).format(parsed)}`;
}

function buildReminderNotifications(baseDate: Date, route: string): HeaderNotification[] {
  const start = startOfDay(baseDate);
  const reminders = [
    { key: "salary" as const, dayOfMonth: 5, route },
    { key: "sgk" as const, dayOfMonth: 26, route }
  ];

  return reminders
    .map((reminder): HeaderNotification | null => {
      const dueDate = new Date(start.getFullYear(), start.getMonth(), reminder.dayOfMonth);
      if (dueDate.getTime() < start.getTime()) {
        dueDate.setMonth(dueDate.getMonth() + 1);
      }

      const daysLeft = Math.ceil((startOfDay(dueDate).getTime() - start.getTime()) / DAY_MS);
      if (daysLeft > 10) {
        return null;
      }

      const copy = formatHeaderReminderCopy({
        key: reminder.key,
        daysLeft,
        dueDateLabel: formatDate(dueDate)
      });

      return {
        id: `reminder-${reminder.key}`,
        title: copy.title,
        subtitle: copy.subtitle,
        level: daysLeft <= 2 ? "critical" : "warning",
        route: reminder.route,
        unread: true
      };
    })
    .filter((item): item is HeaderNotification => item !== null);
}

export function ShellHeaderActions({ contextLabel, minimal = false }: ShellHeaderActionsProps) {
  const rootRef = useRef<HTMLDivElement | null>(null);

  const revision = useAppDataRevision();
  const navigate = useNavigate();
  const location = useLocation();
  const { logout, session, setActiveSubeId } = useAuth();
  const { hasPermission, uiProfile } = useRoleAccess();

  const activeSubeId = session?.active_sube_id ?? null;
  const role = canonicalizeUserRole(session?.user.rol);
  const isPersonelRole = role === "PERSONEL";

  const canViewBildirimler = hasPermission("bildirimler.view");
  const canViewBildirimDetay = hasPermission("bildirimler.detail.view");
  const canViewBugunPersonelDurumu = hasPermission("bugun_personel_durumu.view");
  const canViewRaporlar = hasPermission("raporlar.view");
  const canViewFinans = hasPermission("finans.view");
  const canViewYonetimPanel = hasPermission("yonetim-paneli.view");
  const canManageYonetimPanel = hasPermission("yonetim-paneli.manage");
  const canViewMevzuat = hasPermission("mevzuat_parametreleri.view");
  const canViewSaklama =
    hasPermission("legal_hold.manage") ||
    hasPermission("retention.destruction.approve") ||
    (hasPermission("retention.view") && canViewYonetimPanel);
  const canViewResmiTatilTakvimi = hasPermission("resmi_tatil_takvimi.view");

  const [isNotificationsOpen, setIsNotificationsOpen] = useState(false);
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);
  const [isSubeOpen, setIsSubeOpen] = useState(false);
  const [isBugunModalOpen, setIsBugunModalOpen] = useState(false);
  const [bugunAttentionCount, setBugunAttentionCount] = useState(0);
  const [notificationActionError, setNotificationActionError] = useState<string | null>(null);
  const [readNotificationIds, setReadNotificationIds] = useState<Record<string, true>>({});
  const [personelInboxItems, setPersonelInboxItems] = useState<InboxNotification[]>([]);
  const [personelInboxLoading, setPersonelInboxLoading] = useState(false);
  const [personelDuyuruUnread, setPersonelDuyuruUnread] = useState(0);

  const {
    items: headerTamamlamalar,
    isLoading: headerBildirimlerLoading,
    errorMessage: headerBildirimlerError,
    reload: reloadHeaderBildirimler,
    markOkundu
  } = useBildirimlerHeaderPreview(canViewBildirimler && !isPersonelRole);

  const reminderRoute = canViewFinans
    ? "/finans"
    : canViewRaporlar
      ? "/raporlar"
      : canViewBildirimler
        ? "/bildirimler"
        : "/";

  const syncLabel = useMemo(() => formatSyncLabel(getAppData().updatedAt), [revision]);

  const notifications = useMemo(() => {
    if (isPersonelRole) {
      return personelInboxItems.map((item) => ({
        id: `inbox-${item.id}`,
        title: item.title,
        subtitle: item.body,
        level: "neutral" as const,
        route: "/",
        unread: item.popup_required && !item.popup_consumed
      }));
    }

    const reminderItems =
      uiProfile === "birim_amiri" || isPersonelRole
        ? []
        : buildReminderNotifications(new Date(), reminderRoute);
    const apiItems: HeaderNotification[] = headerTamamlamalar.map((item) => {
      const copy = formatHeaderGunlukTamamlamaCopy(item);

      return {
        id: `tamamlama-${item.id}`,
        title: copy.title,
        subtitle: copy.subtitle,
        level: "neutral" as const,
        route: canViewBildirimDetay
          ? `/bildirimler/gunluk/${item.id}`
          : "/bildirimler",
        unread: item.okundu_mi !== true
      };
    });

    return [...reminderItems, ...apiItems];
  }, [
    canViewBildirimDetay,
    headerTamamlamalar,
    isPersonelRole,
    personelInboxItems,
    reminderRoute,
    uiProfile
  ]);

  const visibleNotifications = useMemo(
    () =>
      notifications.map((item) => ({
        ...item,
        unread: item.unread && !readNotificationIds[item.id]
      })),
    [notifications, readNotificationIds]
  );

  const unreadCount = visibleNotifications.filter((item) => item.unread).length;
  const hasCriticalUnread = visibleNotifications.some(
    (item) => item.unread && item.level === "critical"
  );
  const hasWarningUnread = visibleNotifications.some((item) => item.unread && item.level === "warning");

  const subeIds = session?.user.sube_ids ?? [];
  const subeList = session?.sube_list ?? [];
  const selectorIds =
    subeIds.length > 0 ? subeIds : subeList.map((sube) => sube.id).filter((id) => id > 0);
  const isGlobalBranchSelector =
    role != null &&
    (GLOBAL_SCOPE_ROLES as readonly string[]).includes(role) &&
    subeIds.length === 0 &&
    selectorIds.length > 1;

  const subeControl = useMemo(() => {
    if (selectorIds.length === 0) {
      return { kind: "all" as const };
    }
    if (selectorIds.length === 1) {
      const id = selectorIds[0];
      const label = subeList.find((sube) => sube.id === id)?.ad ?? `Şube ${id}`;
      return { kind: "single" as const, id, label };
    }
    return { kind: "multi" as const };
  }, [selectorIds, subeList]);

  const activeSubeFilterLabel = useMemo(() => {
    if (activeSubeId != null) {
      return subeList.find((sube) => sube.id === activeSubeId)?.ad ?? `Şube ${activeSubeId}`;
    }
    if (isGlobalBranchSelector || subeControl.kind === "all") {
      return "Tüm şubeler";
    }
    return "Şube";
  }, [activeSubeId, isGlobalBranchSelector, subeControl.kind, subeList]);

  useEffect(() => {
    setIsNotificationsOpen(false);
    setIsSettingsOpen(false);
    setIsSubeOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    if (!isPersonelRole || !minimal) {
      setPersonelInboxItems([]);
      return;
    }
    let cancelled = false;
    setPersonelInboxLoading(true);
    void fetchInboxNotifications()
      .then((data) => {
        if (!cancelled) {
          setPersonelInboxItems(data.items);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setPersonelInboxItems([]);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setPersonelInboxLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [isPersonelRole, minimal, location.pathname]);

  useEffect(() => {
    if (!isPersonelRole || !minimal || shouldPreferDemoApi()) {
      setPersonelDuyuruUnread(0);
      return;
    }
    let cancelled = false;
    void fetchSelfDuyurular()
      .then((result) => {
        if (!cancelled) {
          setPersonelDuyuruUnread(Math.max(0, result.unread_count));
        }
      })
      .catch(() => {
        if (!cancelled) {
          setPersonelDuyuruUnread(0);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [isPersonelRole, minimal, location.pathname]);

  useEffect(() => {
    if (!canViewBugunPersonelDurumu || !minimal) {
      return;
    }
    function openBugun() {
      setIsBugunModalOpen(true);
      setIsNotificationsOpen(false);
      setIsSettingsOpen(false);
      setIsSubeOpen(false);
    }
    function refreshBugunAttention() {
      void fetchBugunPersonelDurumu({ tarih: istanbulBusinessDate() })
        .then((data) => setBugunAttentionCount(Math.max(0, Number(data.attention_count) || 0)))
        .catch(() => setBugunAttentionCount(0));
    }
    window.addEventListener(OPEN_BUGUN_PERSONEL_DURUMU_EVENT, openBugun);
    window.addEventListener(REFRESH_BUGUN_PERSONEL_DURUMU_EVENT, refreshBugunAttention);
    return () => {
      window.removeEventListener(OPEN_BUGUN_PERSONEL_DURUMU_EVENT, openBugun);
      window.removeEventListener(REFRESH_BUGUN_PERSONEL_DURUMU_EVENT, refreshBugunAttention);
    };
  }, [canViewBugunPersonelDurumu, minimal]);

  useEffect(() => {
    if (!canViewBugunPersonelDurumu || !minimal) {
      return;
    }
    let cancelled = false;
    void fetchBugunPersonelDurumu({ tarih: istanbulBusinessDate() })
      .then((data) => {
        if (!cancelled) {
          setBugunAttentionCount(Math.max(0, Number(data.attention_count) || 0));
        }
      })
      .catch(() => {
        if (!cancelled) {
          setBugunAttentionCount(0);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [canViewBugunPersonelDurumu, minimal, location.pathname]);

  useEffect(() => {
    function handleDocumentClick(event: MouseEvent) {
      const target = event.target as Node;
      if (rootRef.current && !rootRef.current.contains(target)) {
        setIsNotificationsOpen(false);
        setIsSettingsOpen(false);
        setIsSubeOpen(false);
      }
    }

    function handleEscape(event: KeyboardEvent) {
      if (event.key !== "Escape") {
        return;
      }

      setIsNotificationsOpen(false);
      setIsSettingsOpen(false);
      setIsSubeOpen(false);
    }

    document.addEventListener("mousedown", handleDocumentClick);
    document.addEventListener("keydown", handleEscape);

    return () => {
      document.removeEventListener("mousedown", handleDocumentClick);
      document.removeEventListener("keydown", handleEscape);
    };
  }, []);

  const isNotificationsLoading = isPersonelRole
    ? personelInboxLoading
    : canViewBildirimler
      ? headerBildirimlerLoading
      : false;
  const notificationError = isPersonelRole ? notificationActionError : notificationActionError ?? headerBildirimlerError;

  function navigateTo(path: string) {
    setIsNotificationsOpen(false);
    setIsSettingsOpen(false);
    setIsSubeOpen(false);
    navigate(path);
  }

  function handleNotificationClick(notification: HeaderNotification) {
    if (notification.id.startsWith("inbox-")) {
      const numericId = Number.parseInt(notification.id.slice("inbox-".length), 10);
      if (Number.isFinite(numericId)) {
        void ackInboxPopup(numericId).catch(() => undefined);
      }
      setReadNotificationIds((prev) => ({
        ...prev,
        [notification.id]: true
      }));
      setIsNotificationsOpen(false);
      return;
    }

    if (notification.id.startsWith("tamamlama-")) {
      const numericId = Number.parseInt(notification.id.slice("tamamlama-".length), 10);
      if (Number.isFinite(numericId)) {
        void markOkundu(numericId)
          .then(() => {
            setNotificationActionError(null);
            void reloadHeaderBildirimler();
          })
          .catch((error) => {
            setNotificationActionError(
              error instanceof Error ? error.message : "Bildirim okundu işaretlenemedi."
            );
          });
      }
    } else {
      setReadNotificationIds((prev) => ({
        ...prev,
        [notification.id]: true
      }));
    }

    navigateTo(notification.route);
  }

  function markAllNotificationsAsRead() {
    const unreadItems = visibleNotifications.filter((item) => item.unread);
    const unreadInboxIds = unreadItems
      .filter((item) => item.id.startsWith("inbox-"))
      .map((item) => Number.parseInt(item.id.slice("inbox-".length), 10))
      .filter((id) => Number.isFinite(id));
    if (unreadInboxIds.length > 0) {
      void Promise.all(unreadInboxIds.map((id) => ackInboxPopup(id))).catch(() => undefined);
    }

    const unreadApiIds = unreadItems
      .filter((item) => item.id.startsWith("tamamlama-"))
      .map((item) => Number.parseInt(item.id.slice("tamamlama-".length), 10))
      .filter((id) => Number.isFinite(id));

    if (unreadApiIds.length > 0) {
      void Promise.all(unreadApiIds.map((id) => markOkundu(id)))
        .then(() => {
          setNotificationActionError(null);
          void reloadHeaderBildirimler();
        })
        .catch((error) => {
          setNotificationActionError(
            error instanceof Error ? error.message : "Bildirimler okundu işaretlenemedi."
          );
        });
    }

    const reminderMap: Record<string, true> = {};
    unreadItems.forEach((item) => {
      if (item.id.startsWith("reminder-")) {
        reminderMap[item.id] = true;
      }
    });

    if (Object.keys(reminderMap).length > 0) {
      setReadNotificationIds((prev) => ({
        ...prev,
        ...reminderMap
      }));
    }
  }

  const showPersonelHomeLogout = isPersonelRole && minimal;
  const showSettingsGearMenu = !showPersonelHomeLogout;

  const notificationButtonClassName = [
    "icon-btn",
    hasCriticalUnread ? "notification-red" : "",
    !hasCriticalUnread && hasWarningUnread ? "notification-orange" : "",
    hasCriticalUnread ? "notification-pulse" : ""
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <>
    <div
      className={`icons-row${minimal ? " icons-row--minimal" : ""}${isPersonelRole && minimal ? " icons-row--personel" : ""}`}
      ref={rootRef}
    >
      {isPersonelRole && minimal ? (
        <div className="icons-row-left">
          <Link
            to="/self/duyurular"
            className="pm-shell-duyurular-link"
            data-testid="personel-shell-duyurular-link"
            aria-label="Duyurular"
            onClick={() => {
              setIsNotificationsOpen(false);
              setIsSettingsOpen(false);
              setIsSubeOpen(false);
            }}
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="22"
              height="22"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <g transform="rotate(-14 12 12)">
                <path
                  fill="var(--brand-red, #c62828)"
                  d="M7.2 14.6h2a1.1 1.1 0 0 1 1.1 1.1v3.1a.9.9 0 0 1-.9.9H7.6a.7.7 0 0 1-.7-.7v-3.5c0-.4.2-.7.3-.9z"
                />
                <path
                  fill="var(--brand-red, #c62828)"
                  d="M3.4 9.4 13.2 6.3c.5-.2 1 .2 1 .7v9.8c0 .5-.5.9-1 .7L3.4 14.4c-.4-.2-.7-.6-.7-1.1V10.5c0-.5.3-.9.7-1.1z"
                />
                <path
                  fill="var(--brand-red, #c62828)"
                  d="M13.8 5.8c1.6.3 2.8 1.8 2.8 3.6s-1.2 3.3-2.8 3.6V5.8z"
                />
                <ellipse cx="15.1" cy="9.4" rx="1.5" ry="2.4" fill="#fff" />
                <circle cx="15.1" cy="9.4" r="0.55" fill="var(--brand-red, #c62828)" />
                <path
                  d="M17.2 7.4c1.4 1.2 1.4 3.2 0 4.4"
                  fill="none"
                  stroke="var(--brand-red, #c62828)"
                  strokeWidth="1.15"
                  strokeLinecap="round"
                />
                <path
                  d="M18.6 6c2 1.7 2 4.7 0 6.4"
                  fill="none"
                  stroke="var(--brand-red, #c62828)"
                  strokeWidth="1.15"
                  strokeLinecap="round"
                />
                <path
                  d="M20 4.6c2.6 2.2 2.6 6.2 0 8.4"
                  fill="none"
                  stroke="var(--brand-red, #c62828)"
                  strokeWidth="1.15"
                  strokeLinecap="round"
                />
              </g>
            </svg>
            {personelDuyuruUnread > 0 ? (
              <span className="pm-shell-duyurular-badge" data-testid="personel-shell-duyuru-badge">
                {personelDuyuruUnread > 99 ? "99+" : personelDuyuruUnread}
              </span>
            ) : null}
          </Link>
        </div>
      ) : null}
      {!minimal ? (
        <div className="icons-row-left">
          <span className="shell-context-label" title="Aktif ekran">
            {contextLabel}
          </span>
        </div>
      ) : null}
      {!minimal ? (
        <div className="pwa-install-center">
          <span className="shell-sync-label" title="Uygulama veri durumu">
            {syncLabel}
          </span>
        </div>
      ) : null}
      <div className="icons-row-right">
        {canViewBugunPersonelDurumu && minimal ? (
          <button
            type="button"
            className="icon-btn bugun-personel-header-btn"
            data-testid="bugun-personel-durumu-entry"
            aria-label={
              bugunAttentionCount > 0
                ? `Bugünkü Personel Durumu, ${bugunAttentionCount} dikkat`
                : "Bugünkü Personel Durumu"
            }
            title="Bugünkü Personel Durumu"
            onClick={() => {
              setIsBugunModalOpen(true);
              setIsNotificationsOpen(false);
              setIsSettingsOpen(false);
              setIsSubeOpen(false);
            }}
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <rect x="3" y="4" width="18" height="18" rx="2" />
              <path d="M16 2v4M8 2v4M3 10h18" />
              <path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01" />
            </svg>
            {bugunAttentionCount > 0 ? (
              <span className="bugun-personel-header-badge" aria-hidden="true">
                {bugunAttentionCount > 99 ? "99+" : bugunAttentionCount}
              </span>
            ) : null}
          </button>
        ) : null}
        {!minimal && subeControl.kind === "all" ? (
          <span className="sube-header-badge" title="Aktif şube filtresi yok">
            Tüm şubeler
          </span>
        ) : null}
        {!minimal && subeControl.kind === "single" ? (
          <span className="sube-header-badge" title="Atanan şube">
            {subeControl.label}
          </span>
        ) : null}
        {/* Multi-sube switch must remain on home (minimal): module routes use
         * overlay chrome without ShellHeaderActions after modules canonicalize.
         * PERSONEL product home: no org/şube chrome — employee has fixed assignment. */}
        {subeControl.kind === "multi" && !isPersonelRole ? (
          <div className="sube-selector-wrap">
            <button
              type="button"
              className="icon-btn sube-selector-toggle"
              data-testid="header-sube-selector-toggle"
              onClick={() => {
                setIsSubeOpen((prev) => !prev);
                setIsNotificationsOpen(false);
                setIsSettingsOpen(false);
              }}
              aria-label={`Şube filtresi: ${activeSubeFilterLabel}`}
              aria-expanded={isSubeOpen}
              title={`Şube filtresi: ${activeSubeFilterLabel}`}
            >
              <svg
                className="sube-selector-icon"
                xmlns="http://www.w3.org/2000/svg"
                width="19"
                height="19"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
              >
                <path
                  d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
                />
                <circle cx="12" cy="10" r="3" />
              </svg>
              <svg
                className="sube-selector-chevron"
                xmlns="http://www.w3.org/2000/svg"
                width="12"
                height="12"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                aria-hidden="true"
              >
                <path d="M6 9l6 6 6-6" />
              </svg>
            </button>
            <div
              id="sube-selector-menu"
              className={`settings-dropdown sube-selector-dropdown${isSubeOpen ? " open" : ""}`}
            >
              {isGlobalBranchSelector ? (
                <button
                  type="button"
                  className={activeSubeId === null ? "sube-option-active" : undefined}
                  onClick={() => {
                    setActiveSubeId(null);
                    setIsSubeOpen(false);
                  }}
                >
                  Tüm şubeler
                  {activeSubeId === null ? " (seçili)" : ""}
                </button>
              ) : null}
              {selectorIds.map((id) => {
                const label = subeList.find((sube) => sube.id === id)?.ad ?? `Şube ${id}`;
                return (
                  <button
                    key={id}
                    type="button"
                    className={activeSubeId === id ? "sube-option-active" : undefined}
                    onClick={() => {
                      setActiveSubeId(id);
                      setIsSubeOpen(false);
                    }}
                  >
                    {label}
                    {activeSubeId === id ? " (seçili)" : ""}
                  </button>
                );
              })}
            </div>
          </div>
        ) : null}

        <div className="notification-wrap">
          <button
            id="notifications-toggle-btn"
            type="button"
            className={notificationButtonClassName}
            onClick={() => {
              setIsNotificationsOpen((prev) => !prev);
              setIsSettingsOpen(false);
              setIsSubeOpen(false);
            }}
            aria-label="Bildirimleri aç"
            aria-expanded={isNotificationsOpen}
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
              <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
          </button>

          <div
            id="notifications-dropdown"
            className={`settings-dropdown notifications-dropdown${isNotificationsOpen ? " open" : ""}`}
          >
            {isNotificationsLoading ? (
              <button type="button" className="notification-item notification-empty" disabled>
                Bildirimler yükleniyor...
              </button>
            ) : null}

            {!isNotificationsLoading && unreadCount > 0 ? (
              <div className="notifications-toolbar">
                <button
                  type="button"
                  className="notifications-mark-all-read-btn"
                  onClick={markAllNotificationsAsRead}
                >
                  Tümünü okundu işaretle
                </button>
              </div>
            ) : null}

            {!isNotificationsLoading &&
              visibleNotifications.map((notification) => (
                <button
                  key={notification.id}
                  type="button"
                  className={[
                    "notification-item",
                    notification.level === "critical" ? "date-warning-red-border" : "",
                    notification.level === "warning" ? "date-warning-orange-border" : "",
                    notification.unread ? "notification-unread" : ""
                  ]
                    .filter(Boolean)
                    .join(" ")}
                  onClick={() => handleNotificationClick(notification)}
                >
                  <div className="notif-line1">{notification.title}</div>
                  <div className="notif-line2">{notification.subtitle}</div>
                </button>
              ))}

            {!isNotificationsLoading && visibleNotifications.length === 0 ? (
              <button type="button" className="notification-item notification-empty" disabled>
                Bildirim yok
              </button>
            ) : null}

            {notificationError ? <p className="notification-error">{notificationError}</p> : null}
          </div>
        </div>

        <button
          type="button"
          className="icon-btn"
          data-testid={showPersonelHomeLogout ? "header-logout-btn" : "header-settings-toggle"}
          onClick={() => {
            if (showPersonelHomeLogout) {
              setIsNotificationsOpen(false);
              setIsSubeOpen(false);
              logout();
              return;
            }
            setIsSettingsOpen((prev) => !prev);
            setIsNotificationsOpen(false);
            setIsSubeOpen(false);
          }}
          aria-label={showPersonelHomeLogout ? "Çıkış" : "Ayar menüsü"}
          aria-expanded={showPersonelHomeLogout ? undefined : isSettingsOpen}
        >
          {showPersonelHomeLogout ? (
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d="M10 17l5-5-5-5" />
              <path d="M15 12H3" />
              <path d="M21 19V5a2 2 0 0 0-2-2h-5" />
              <path d="M21 19a2 2 0 0 1-2 2h-5" />
            </svg>
          ) : (
            <svg
            xmlns="http://www.w3.org/2000/svg"
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
          >
            <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.6h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
          </svg>
          )}
        </button>

        {showSettingsGearMenu ? (
        <div
          id="settings-menu"
          className={`settings-dropdown settings-menu-dropdown${isSettingsOpen ? " open" : ""}`}
        >
          {canViewYonetimPanel ? (
            <button
              type="button"
              data-testid="settings-yonetim-paneli"
              onClick={() => {
                navigateTo("/yonetim-paneli?tab=kullanicilar");
              }}
            >
              Kullanıcı Yönetimi
            </button>
          ) : null}
          {canManageYonetimPanel ? (
            <button
              type="button"
              data-testid="settings-sube-yonetimi"
              onClick={() => {
                navigateTo("/yonetim-paneli?tab=subeler");
              }}
            >
              Şube Yönetimi
            </button>
          ) : null}
          {canViewYonetimPanel && canViewMevzuat ? (
            <button
              type="button"
              data-testid="settings-mevzuat-parametreleri"
              onClick={() => {
                navigateTo("/yonetim-paneli?tab=mevzuat");
              }}
            >
              Mevzuat Parametreleri
            </button>
          ) : null}
          {canViewSaklama ? (
            <button
              type="button"
              data-testid="settings-saklama-legal-hold"
              onClick={() => {
                navigateTo("/yonetim-paneli?tab=saklama");
              }}
            >
              Saklama ve İmha
            </button>
          ) : null}
          {canViewYonetimPanel ? (
            <button
              type="button"
              data-testid="settings-ucret-tipi-envanteri"
              onClick={() => {
                navigateTo("/yonetim-paneli?tab=ucret-tipi-envanteri");
              }}
            >
              Ücret Tipi Envanteri
            </button>
          ) : null}
          {canViewResmiTatilTakvimi ? (
            <button
              type="button"
              data-testid="settings-resmi-tatil-takvimi"
              onClick={() => {
                navigateTo("/resmi-tatil-takvimi");
              }}
            >
              Resmî Tatil Takvimi
            </button>
          ) : null}
          <button
            type="button"
            className="settings-logout-btn"
            onClick={() => {
              setIsSettingsOpen(false);
              logout();
            }}
          >
            Çıkış
          </button>
        </div>
        ) : null}
      </div>
    </div>
    {canViewBugunPersonelDurumu ? (
      <BugunPersonelDurumuModal
        open={isBugunModalOpen}
        onClose={() => {
          setIsBugunModalOpen(false);
          void fetchBugunPersonelDurumu({ tarih: istanbulBusinessDate() })
            .then((data) => setBugunAttentionCount(Math.max(0, Number(data.attention_count) || 0)))
            .catch(() => undefined);
        }}
      />
    ) : null}
    </>
  );
}
