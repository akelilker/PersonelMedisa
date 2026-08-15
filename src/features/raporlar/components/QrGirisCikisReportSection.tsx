import { QrGirisCikisOperationSection } from "../../puantaj/components/QrGirisCikisOperationSection";

export function QrGirisCikisReportSection() {
  return (
    <section data-testid="raporlar-qr-giris-cikis">
      <header className="raporlar-panel-heading">
        <div>
          <p className="raporlar-panel-eyebrow">Toplu geçmiş / filtre / inceleme</p>
          <h3 className="raporlar-panel-title">Giriş / Çıkış Raporu</h3>
        </div>
        <p className="raporlar-panel-hint">Sonuç satırından Personel Kartı’na veya günlük puantaja geçebilirsiniz.</p>
      </header>
      <QrGirisCikisOperationSection />
    </section>
  );
}
