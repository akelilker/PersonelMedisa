import { fetchPersonelDetail } from "../../api/personeller.api";
import { fetchSureclerList } from "../../api/surecler.api";
import {
  dataCacheKeys,
  fetchWithCacheMerge,
  getActiveSube,
  getSubeIdForApiRequest
} from "../../data/data-manager";
import { runDeduped } from "../../lib/in-flight-dedupe";
import type { Personel } from "../../types/personel";
import { KAYIT_SUREC_LIST_PAGE_SIZE, KAYIT_SUREC_PERSONEL_HISTORY_LIMIT } from "./kayit-surec-constants";

export async function refetchSurecCachesForPersonel(personelId: number): Promise<void> {
  const activeSube = getActiveSube();
  const subeId = getSubeIdForApiRequest();
  const personelKey = String(personelId);

  const scopedKey = dataCacheKeys.sureclerList(activeSube, personelKey, "", "", "", "", 1);
  await fetchWithCacheMerge(scopedKey, () =>
    runDeduped(scopedKey, () =>
      fetchSureclerList({
        personel_id: personelId,
        sube_id: subeId,
        page: 1,
        limit: KAYIT_SUREC_PERSONEL_HISTORY_LIMIT
      })
    )
  );

  const globalKey = dataCacheKeys.sureclerList(activeSube, "", "", "", "", "", 1);
  await fetchWithCacheMerge(globalKey, () =>
    runDeduped(globalKey, () =>
      fetchSureclerList({
        sube_id: subeId,
        page: 1,
        limit: KAYIT_SUREC_LIST_PAGE_SIZE
      })
    )
  );
}

export async function refetchPersonelDetailAfterIstenAyrilma(personelId: number): Promise<Personel> {
  const activeSube = getActiveSube();
  const detailKey = dataCacheKeys.personelDetail(activeSube, personelId);
  return fetchWithCacheMerge(detailKey, () => runDeduped(detailKey, () => fetchPersonelDetail(personelId)));
}
