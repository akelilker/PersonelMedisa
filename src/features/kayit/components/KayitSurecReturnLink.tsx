import { Link } from "react-router-dom";
import type { KayitSurecReturnContext } from "../kayit-surec-navigation";
import { buildKayitSurecReturnState } from "../kayit-surec-navigation";

export function KayitSurecReturnLink({ context }: { context: KayitSurecReturnContext }) {
  return (
    <Link
      className="universal-btn-aux"
      data-testid="kayit-surec-return-link"
      to="/"
      state={buildKayitSurecReturnState(context)}
    >
      Kayıt ve Süreç&apos;e dön
    </Link>
  );
}
