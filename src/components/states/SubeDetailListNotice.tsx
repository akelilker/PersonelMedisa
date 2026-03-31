import { useSubeDetailListFlash } from "../../hooks/useSubeDetailListFlash";

export function SubeDetailListNotice() {
  const { flash, dismiss } = useSubeDetailListFlash();
  if (!flash) {
    return null;
  }

  return (
    <div className="state-card state-error" role="status">
      <p>{flash}</p>
      <button type="button" className="universal-btn-aux" onClick={dismiss}>
        Kapat
      </button>
    </div>
  );
}
