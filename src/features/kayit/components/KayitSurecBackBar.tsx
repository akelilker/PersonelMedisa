import { BackBar } from "../../../components/BackBar";
import type { KayitSurecReturnContext } from "../kayit-surec-navigation";
import { buildKayitSurecReturnState } from "../kayit-surec-navigation";

type KayitSurecBackBarProps = {
  context: KayitSurecReturnContext;
  /** Target screen label (where back navigates), e.g. Puantaj, Belge Takip */
  label: string;
  testId?: string;
};

export function KayitSurecBackBar({ context, label, testId = "kayit-surec-back-bar" }: KayitSurecBackBarProps) {
  return (
    <BackBar to="/" label={label} testId={testId} state={buildKayitSurecReturnState(context)} />
  );
}
