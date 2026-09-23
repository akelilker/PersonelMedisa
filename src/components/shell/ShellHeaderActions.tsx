import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
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

  const {
    items: headerTamamlamalar,
    isLoading: headerBildirimlerLoading,
    errorMessage: headerBildirimlerError,
    reload: reloadHeaderBildirimler,
    markOkundu
  } = useBildirimlerHeaderPreview(canViewBildirimler);

  const reminderRoute = canViewFinans
    ? "/finans"
    : canViewRaporlar
      ? "/raporlar"
      : canViewBildirimler
        ? "/bildirimler"
        : "/";

  const syncLabel = useMemo(() => formatSyncLabel(getAppData().updatedAt), [revision]);

  const notifications = useMemo(() => {
    const reminderItems =
      uiProfile === "birim_amiri" ? [] : buildReminderNotifications(new Date(), reminderRoute);
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
  }, [canViewBildirimDetay, headerTamamlamalar, reminderRoute, uiProfile]);

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
  const role = canonicalizeUserRole(session?.user.rol);
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

  useEffect(() => {
    setIsNotificationsOpen(false);
    setIsSettingsOpen(false);
    setIsSubeOpen(false);
  }, [location.pathname]);

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

  const isNotificationsLoading = canViewBildirimler ? headerBildirimlerLoading : false;
  const notificationError = notificationActionError ?? headerBildirimlerError;

  function navigateTo(path: string) {
    setIsNotificationsOpen(false);
    setIsSettingsOpen(false);
    setIsSubeOpen(false);
    navigate(path);
  }

  function handleNotificationClick(notification: HeaderNotification) {
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
    <div className={`icons-row${minimal ? " icons-row--minimal" : ""}`} ref={rootRef}>
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
         * overlay chrome without ShellHeaderActions after modules canonicalize. */}
        {subeControl.kind === "multi" ? (
          <div className="sube-selector-wrap">
            <button
              type="button"
              className="icon-btn sube-selector-toggle"
              onClick={() => {
                setIsSubeOpen((prev) => !prev);
                setIsNotificationsOpen(false);
                setIsSettingsOpen(false);
              }}
              aria-label="Şube seç"
              aria-expanded={isSubeOpen}
              title="Şube değiştir"
            >
              <span className="sube-selector-label">
                {activeSubeId != null
                  ? subeList.find((sube) => sube.id === activeSubeId)?.ad ?? `Şube ${activeSubeId}`
                  : isGlobalBranchSelector
                    ? "Tüm şubeler"
                    : "Şube"}
              </span>
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16"
                height="16"
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
          data-testid="header-settings-toggle"
          onClick={() => {
            setIsSettingsOpen((prev) => !prev);
            setIsNotificationsOpen(false);
            setIsSubeOpen(false);
          }}
          aria-label="Ayar menüsü"
          aria-expanded={isSettingsOpen}
        >
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
        </button>

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
