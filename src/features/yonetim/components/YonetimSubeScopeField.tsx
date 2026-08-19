import type { YonetimSube } from "../../../types/yonetim";

type YonetimSubeScopeFieldProps = {
  subeler: YonetimSube[];
  selectedSubeIds: number[];
  onToggleSube: (subeId: number) => void;
};

export function YonetimSubeScopeField(props: YonetimSubeScopeFieldProps) {
  return (
    <div className="yonetim-checkbox-section" data-testid="yonetim-sube-scope-field">
      <p className="yonetim-checkbox-title">Şube Yetkisi</p>
      <p className="yonetim-hint">Boş bırakırsan kullanıcı tüm şubelerde çalışır.</p>
      <div className="yonetim-selection-grid">
        {props.subeler.map((sube) => (
          <button
            key={sube.id}
            type="button"
            className={`yonetim-selection-pill${props.selectedSubeIds.includes(sube.id) ? " is-selected" : ""}`}
            aria-pressed={props.selectedSubeIds.includes(sube.id)}
            onClick={() => props.onToggleSube(sube.id)}
          >
            <strong>{sube.ad}</strong>
            <span>{sube.departman_adlari.join(", ") || "Departman tanımlı değil"}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
