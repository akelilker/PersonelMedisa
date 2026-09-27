import { BackgroundlessNoticeModal } from "./BackgroundlessNoticeModal";
import type { SelfServiceYillikIzinView } from "../personel-self-service-yillik-izin-view";

type Props = {
  open: boolean;
  view: SelfServiceYillikIzinView | null;
  onClose: () => void;
};

export function SelfServiceYillikIzinInfoModal({ open, view, onClose }: Props) {
  if (!view) {
    return null;
  }

  return (
    <BackgroundlessNoticeModal
      open={open}
      title="Yıllık İzin"
      body={view.pendingMessage ?? view.rowText}
      onClose={onClose}
      testId="personel-leave-info-modal"
    >
      <dl className="pm-leave-detail" data-testid="personel-leave-detail">
        <div className="pm-leave-detail__row">
          <dt>İşe giriş tarihi</dt>
          <dd>{view.iseGirisLabel}</dd>
        </div>
        <div className="pm-leave-detail__row">
          <dt>Kıdem</dt>
          <dd>{view.kidemLabel}</dd>
        </div>
        <div className="pm-leave-detail__row">
          <dt>Toplam izin</dt>
          <dd>{view.toplamLabel}</dd>
        </div>
        <div className="pm-leave-detail__row">
          <dt>Kullanılan</dt>
          <dd>{view.kullanilanLabel}</dd>
        </div>
        <div className="pm-leave-detail__row">
          <dt>Kalan</dt>
          <dd>{view.kalanLabel}</dd>
        </div>
      </dl>
    </BackgroundlessNoticeModal>
  );
}
