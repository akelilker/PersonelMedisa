import type { KayitSurecReturnContext } from "../kayit-surec-navigation";
import { KayitSurecBackBar } from "./KayitSurecBackBar";

/** @deprecated Use KayitSurecBackBar with explicit parent label */
export function KayitSurecReturnLink({
  context,
  label = "Kayıt ve Süreç"
}: {
  context: KayitSurecReturnContext;
  label?: string;
}) {
  return <KayitSurecBackBar context={context} label={label} testId="kayit-surec-return-link" />;
}
