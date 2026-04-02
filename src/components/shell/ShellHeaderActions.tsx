import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useAppDataRevision } from "../../data/data-manager";
import { useBildirimlerHeaderPreview } from "../../hooks/useBildirimler";
import { useRoleAccess } from "../../hooks/use-role-access";
import { formatBildirimTuruLabel } from "../../lib/display/enum-display";
import { useAuth } from "../../state/auth.store";

type NotificationLevel = "neutral" | "warning" | "critical";

type HeaderNotification = {
  id: string;
  title: string;
  subtitle: string;
  level: NotificationLevel;
  route: string;
  unread: boolean;
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

function getReminderSubtitle(daysLeft: number, dueDate: Date) {
  if (daysLeft <= 0) {
    return `Bugün son gün (${formatDate(dueDate)})`;
  }

  return `${daysLeft} gün kaldı (${formatDate(dueDate)})`;
}

function buildReminderNotifications(baseDate: Date, route: string): HeaderNotification[] {
  const start = startOfDay(baseDate);
  const reminders = [
    {
      key: "salary",
      dayOfMonth: 5,
      title: "Maaş ödeme zamanı yaklaşıyor",
      route
    },
    {
      key: "sgk",
      dayOfMonth: 26,
      title: "SGK prim ödeme takibini kontrol et",
      route
    }
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

      return {
        id: `reminder-${reminder.key}`,
        title: reminder.title,
        subtitle: getReminderSubtitle(daysLeft, dueDate),
        level: daysLeft <= 2 ? "critical" : "warning",
        route: reminder.route,
        unread: true
      };
    })
    .filter((item): item is HeaderNotification => item !== null);
}

function formatBildirimTuru(value: string) {
  return formatBildirimTuruLabel(value);
}

function mapBildirimLevel(bildirimTuru: string): NotificationLevel {
  const normalized = bildirimTuru.toLocaleUpperCase(TR_LOCALE);

  if (
    normalized.includes("DEVAMSIZLIK") ||
    normalized.includes("GELMEDI") ||
    normalized.includes("GELMEDİ") ||
    normalized.includes("IZINSIZ") ||
    normalized.includes("İZİNSİZ") ||
    normalized.includes("UYARI")
  ) {
    return "critical";
  }

  if (
    normalized.includes("GEC") ||
    normalized.includes("GEÇ") ||
    normalized.includes("RAPOR") ||
    normalized.includes("YAKLASAN") ||
    normalized.includes("YAKLAŞAN")
  ) {
    return "warning";
  }

  return "neutral";
}

export function ShellHeaderActions() {
  const rootRef = useRef<HTMLDivElement | null>(null);

  const navigate = useNavigate();
  const location = useLocation();
  const { logout, session, setActiveSubeId } = useAuth();
  const { hasPermission, uiProfile } = useRoleAccess();

  useAppDataRevision();
  const activeSubeId = session?.active_sube_id ?? null;

  const canViewBildirimler = hasPermission("bildirimler.view");
  const canViewBildirimDetay = hasPermission("bildirimler.detail.view");
  const canViewRaporlar = hasPermission("raporlar.view");
  const canViewFinans = hasPermission("finans.view");

  const [isNotificationsOpen, setIsNotificationsOpen] = useState(false);
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);
  const [isSubeOpen, setIsSubeOpen] = useState(false);
  const [notificationActionError, setNotificationActionError] = useState<string | null>(null);
  const [readNotificationIds, setReadNotificationIds] = useState<Record<string, true>>({});

  const {
    items: headerBildirimler,
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

  const notifications = useMemo(() => {
    const reminderItems =
      uiProfile === "birim_amiri" ? [] : buildReminderNotifications(new Date(), reminderRoute);
    const apiItems: HeaderNotification[] = headerBildirimler.map((item) => {
      const tarihText = item.tarih ? `Tarih: ${item.tarih}` : "";
      const personelText = item.personel_id ? `Personel: ${item.personel_id}` : "";
      const subtitle = [tarihText, personelText].filter(Boolean).join(" | ") || "İşlem gerektiriyor";

      return {
        id: `api-${item.id}`,
        title: formatBildirimTuru(item.bildirim_turu),
        subtitle,
        level: mapBildirimLevel(item.bildirim_turu),
        route: canViewBildirimDetay ? `/bildirimler/${item.id}` : "/bildirimler",
        unread: item.state !== "IPTAL" && item.okundu_mi !== true
      };
    });

    return [...reminderItems, ...apiItems];
  }, [canViewBildirimDetay, headerBildirimler, reminderRoute, uiProfile]);

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

  const subeControl = useMemo(() => {
    if (subeIds.length === 0) {
      return { kind: "all" as const };
    }
    if (subeIds.length === 1) {
      const id = subeIds[0];
      const label = subeList.find((s) => s.id === id)?.ad ?? `Şube ${id}`;
      return { kind: "single" as const, id, label };
    }
    return { kind: "multi" as const };
  }, [subeIds, subeList]);

  useEffect(() => {
    setIsNotificationsOpen(false);
    setIsSettingsOpen(false);
    setIsSubeOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    function handleDocumentClick(event: MouseEvent) {
      const target = event.target as Node;
      if (rootRef.current && !rootRef.current.contains(target)) {
        setIsNotificationsOpen(false);
        setIsSettingsOpen(false);
      }
    }

    function handleEscape(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setIsNotificationsOpen(false);
        setIsSettingsOpen(false);
      }
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
    navigate(path);
  }

  function handleNotificationClick(notification: HeaderNotification) {
    if (notification.id.startsWith("api-")) {
      const numericId = Number.parseInt(notification.id.slice(4), 10);
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
      .filter((item) => item.id.startsWith("api-"))
      .map((item) => Number.parseInt(item.id.slice(4), 10))
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
    <div className="icons-row" ref={rootRef}>
      <div className="icons-row-left" />
      <div className="pwa-install-center" />
      <div className="icons-row-right">
        {subeControl.kind === "all" ? (
          <span className="sube-header-badge" title="Aktif şube filtresi yok">
            Tüm şubeler
          </span>
        ) : null}
        {subeControl.kind === "single" ? (
          <span className="sube-header-badge" title="Atanan şube">
            {subeControl.label}
          </span>
        ) : null}
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
                  ? subeList.find((s) => s.id === activeSubeId)?.ad ?? `Şube ${activeSubeId}`
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
              {subeIds.map((id) => {
                const label = subeList.find((s) => s.id === id)?.ad ?? `Şube ${id}`;
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
            height="20"
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

        <div id="settings-menu" className={`settings-dropdown${isSettingsOpen ? " open" : ""}`}>
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
  );
}
