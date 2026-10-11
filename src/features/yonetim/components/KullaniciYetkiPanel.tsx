import { useCallback, useEffect, useMemo, useState } from "react";
import { getApiErrorMessage, isApiRequestError } from "../../../api/api-client";
import {
  fetchKullaniciYetkiAuditleri,
  fetchKullaniciYetkileri,
  iptalKullaniciYetkisi,
  verKullaniciYetkisi
} from "../../../api/yonetim.api";
import { FormField } from "../../../components/form/FormField";
import {
  aksiyonEtiketi,
  aktifIstisnalar,
  bitisApiDegeri,
  bitisHatasi,
  etkiEtiketi,
  hassasYetkiMi,
  istisnaKaldirilabilir,
  kapanmisIstisnalar,
  kaynakEtiketi,
  kendiHesabiMi,
  izinAra,
  secilebilirIzinler,
  uyariMetinleri,
  yetkiEtiketi,
  yetkiOzeti,
  YETKI_SEMA_HAZIR_DEGIL,
  YETKI_SEMA_UYARI,
  type KullaniciYetkiAudit,
  type KullaniciYetkiOkuma,
  type YetkiEtki
} from "../../../lib/yonetim/kullanici-yetki-ekrani";

type KullaniciYetkiPanelProps = {
  userId: number;
  actorUserId: number | null;
  canManage: boolean;
  canViewAudit: boolean;
};

function tarihMetni(value: string | null): string {
  if (!value) return "Kalıcı";
  return `${value} UTC`;
}

export function KullaniciYetkiPanel(props: KullaniciYetkiPanelProps) {
  const [okuma, setOkuma] = useState<KullaniciYetkiOkuma | null>(null);
  const [audit, setAudit] = useState<KullaniciYetkiAudit[]>([]);
  const [auditSema, setAuditSema] = useState(true);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [basari, setBasari] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [etki, setEtki] = useState<YetkiEtki>("DENY");
  const [arama, setArama] = useState("");
  const [izin, setIzin] = useState("");
  const [gerekce, setGerekce] = useState("");
  const [bitis, setBitis] = useState("");
  const [listeArama, setListeArama] = useState("");
  const [iptalId, setIptalId] = useState<number | null>(null);
  const [iptalGerekce, setIptalGerekce] = useState("");

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const [yetki, gecmis] = await Promise.all([
        fetchKullaniciYetkileri(props.userId),
        props.canViewAudit
          ? fetchKullaniciYetkiAuditleri(props.userId)
          : Promise.resolve({ schemaReady: true, items: [] as KullaniciYetkiAudit[] })
      ]);
      setOkuma(yetki);
      setAudit(gecmis.items);
      setAuditSema(gecmis.schemaReady);
    } catch (error) {
      setOkuma(null);
      setAudit([]);
      setLoadError(getApiErrorMessage(error, "Kullanıcı yetkileri okunamadı."));
    } finally {
      setIsLoading(false);
    }
  }, [props.canViewAudit, props.userId]);

  useEffect(() => {
    setBasari(null);
    setActionError(null);
    setIzin("");
    setGerekce("");
    setBitis("");
    setIptalId(null);
    setIptalGerekce("");
    void load();
  }, [load]);

  const kendi = kendiHesabiMi(props.actorUserId, props.userId);
  const semaHazir = okuma?.schemaReady === true && auditSema;
  const yazilabilir = Boolean(okuma && semaHazir && props.canManage && !kendi && !isSaving);
  const ozet = okuma && semaHazir ? yetkiOzeti(okuma) : null;
  const secenekler = useMemo(() => {
    if (!okuma || !semaHazir) return [];
    return secilebilirIzinler({
      rol: okuma.rol,
      rolVarsayilanlari: okuma.rolVarsayilanlari,
      istisnalar: okuma.istisnalar,
      etki,
      arama
    });
  }, [arama, etki, okuma, semaHazir]);

  useEffect(() => {
    if (izin && !secenekler.includes(izin)) {
      setIzin("");
    }
  }, [izin, secenekler]);

  function semaKapandi() {
    setBasari(null);
    setActionError(null);
    setOkuma((prev) => (prev ? { ...prev, schemaReady: false, istisnalar: [] } : prev));
    setAuditSema(false);
    setAudit([]);
  }

  async function kaydet() {
    if (!yazilabilir || !okuma) return;
    const temizGerekce = gerekce.trim();
    if (!izin) {
      setBasari(null);
      setActionError("Bir yetki seçin.");
      return;
    }
    if (!temizGerekce) {
      setBasari(null);
      setActionError("Gerekçe zorunludur.");
      return;
    }
    const sureHatasi = bitisHatasi(bitis, etki);
    if (sureHatasi) {
      setBasari(null);
      setActionError(sureHatasi);
      return;
    }
    setIsSaving(true);
    setActionError(null);
    setBasari(null);
    try {
      const sonuc = await verKullaniciYetkisi(props.userId, {
        permission: izin,
        etki,
        gerekce: temizGerekce,
        gecerlilikBitis: bitisApiDegeri(bitis)
      });
      const uyarilar = sonuc.uyarilar.flatMap((kod) => uyariMetinleri(kod));
      setBasari(
        uyarilar.length > 0
          ? `Yetki kaydı alındı. ${uyarilar.join(" ")}`
          : etki === "DENY"
            ? "Kısıt kaydı alındı."
            : "İzin kaydı alındı."
      );
      setIzin("");
      setGerekce("");
      setBitis("");
      await load();
    } catch (error) {
      setBasari(null);
      if (isApiRequestError(error) && error.code === YETKI_SEMA_HAZIR_DEGIL) {
        semaKapandi();
        return;
      }
      setActionError(getApiErrorMessage(error, "Yetki kaydı alınamadı."));
    } finally {
      setIsSaving(false);
    }
  }

  async function kaldir(istisnaId: number) {
    if (!yazilabilir) return;
    const temiz = iptalGerekce.trim();
    if (!temiz) {
      setBasari(null);
      setActionError("Kaldırma gerekçesi zorunludur.");
      return;
    }
    setIsSaving(true);
    setActionError(null);
    setBasari(null);
    try {
      const sonuc = await iptalKullaniciYetkisi(props.userId, istisnaId, temiz);
      const uyarilar = sonuc.uyarilar.flatMap((kod) => uyariMetinleri(kod));
      setBasari(uyarilar.length > 0 ? `Kayıt kapatıldı. ${uyarilar.join(" ")}` : "Kayıt kapatıldı.");
      setIptalId(null);
      setIptalGerekce("");
      await load();
    } catch (error) {
      setBasari(null);
      if (isApiRequestError(error) && error.code === YETKI_SEMA_HAZIR_DEGIL) {
        semaKapandi();
        return;
      }
      setActionError(getApiErrorMessage(error, "Yetki kaydı kaldırılamadı."));
    } finally {
      setIsSaving(false);
    }
  }

  const aktif = okuma ? aktifIstisnalar(okuma.istisnalar) : [];
  const kapanmis = okuma && semaHazir ? kapanmisIstisnalar(okuma.istisnalar) : [];
  const rolListesi = okuma ? izinAra(okuma.rolVarsayilanlari, listeArama) : [];

  return (
    <section className="yonetim-yetki-panel" data-testid="yonetim-kullanici-yetki" aria-label="Kullanıcı yetkileri">
      <h3 className="yonetim-workspace-panel-title">Kullanıcı yetkileri</h3>
      <p className="yonetim-hint">
        Seçili hesabın rol varsayılanı, etkin yetkisi ve kişisel istisnaları. Şube bazlı istisna kapalıdır;
        kayıt tüm şubeler için geçerlidir.
      </p>

      {isLoading ? <p className="yonetim-hint">Yetkiler yükleniyor…</p> : null}
      {loadError ? (
        <p className="yonetim-inline-error" role="alert" data-testid="yonetim-yetki-yukleme-hata">
          {loadError}
        </p>
      ) : null}

      {okuma && !semaHazir ? (
        <p className="yonetim-yetki-uyari" role="status" data-testid="yonetim-yetki-sema-uyari">
          {YETKI_SEMA_UYARI}
        </p>
      ) : null}

      {okuma && semaHazir && kendi ? (
        <p className="yonetim-yetki-uyari" data-testid="yonetim-yetki-kendi">
          Kendi yetkinizi veya kendi üzerinizdeki kısıtı değiştiremezsiniz.
        </p>
      ) : null}

      {okuma && semaHazir && !props.canManage && !kendi ? (
        <p className="yonetim-hint">Bu hesabın yetkisini değiştirmek için yetki yönetimi izni gerekir. Liste salt okunur.</p>
      ) : null}

      {ozet ? (
        <div className="yonetim-yetki-ozet" data-testid="yonetim-yetki-ozet">
          <span>Etkin yetki: {ozet.etkinSayisi}</span>
          <span>Rol varsayılanı: {ozet.rolSayisi}</span>
          <span>Kişisel izin: {ozet.kisiselIzinler.length}</span>
          <span>Kısıt nedeniyle kapalı: {ozet.kisitlilar.length}</span>
        </div>
      ) : null}

      {okuma && semaHazir ? (
        <div className="yonetim-workspace-panel">
          <p className="yonetim-workspace-panel-title">Kişisel istisnalar</p>
          {aktif.length === 0 ? (
            <p className="yonetim-hint" data-testid="yonetim-yetki-istisna-yok">
              Bu kullanıcıda etkin kişisel istisna yok. Etkin yetkiler rol varsayılanıyla aynıdır.
            </p>
          ) : (
            <ul className="yonetim-yetki-liste">
              {aktif.map((istisna) => {
                const hassas = istisna.etki === "DENY" && hassasYetkiMi(istisna.permission);
                const kaldirilabilir = istisnaKaldirilabilir(istisna, kendi, props.canManage);
                return (
                  <li key={istisna.id} className="yonetim-yetki-satir" data-testid={`yonetim-yetki-istisna-${istisna.id}`}>
                    <div className="yonetim-yetki-satir-ust">
                      <strong>{yetkiEtiketi(istisna.permission)}</strong>
                      <span className={istisna.etki === "DENY" ? "yonetim-yetki-rozet yonetim-yetki-rozet--kisit" : "yonetim-yetki-rozet"}>
                        {etkiEtiketi(istisna.etki)}
                      </span>
                    </div>
                    <span className="yonetim-yetki-kod">{istisna.permission}</span>
                    <span className="yonetim-hint">
                      {tarihMetni(istisna.gecerlilikBaslangic)} — {istisna.gecerlilikBitis ? tarihMetni(istisna.gecerlilikBitis) : "kalıcı"}
                      {istisna.verenUsername ? ` · ${istisna.verenUsername}` : ""}
                    </span>
                    {istisna.gerekce ? <span className="yonetim-hint">{istisna.gerekce}</span> : null}
                    {hassas ? (
                      <span className="yonetim-hint" data-testid={`yonetim-yetki-hassas-${istisna.id}`}>
                        Bu hassas yetki bu ekrandan açılamaz.
                      </span>
                    ) : null}
                    {kaldirilabilir && iptalId !== istisna.id ? (
                      <button
                        type="button"
                        className="yonetim-panel-action"
                        data-testid={`yonetim-yetki-kaldir-${istisna.id}`}
                        disabled={isSaving}
                        onClick={() => {
                          setActionError(null);
                          setBasari(null);
                          setIptalId(istisna.id);
                          setIptalGerekce("");
                        }}
                      >
                        {istisna.etki === "DENY" ? "Kısıtı kaldır" : "İzni kaldır"}
                      </button>
                    ) : null}
                    {kaldirilabilir && iptalId === istisna.id ? (
                      <form
                        className="yonetim-yetki-form"
                        onSubmit={(event) => {
                          event.preventDefault();
                          void kaldir(istisna.id);
                        }}
                      >
                        <FormField
                          as="textarea"
                          label="Kaldırma gerekçesi"
                          name={`yonetim-yetki-iptal-gerekce-${istisna.id}`}
                          value={iptalGerekce}
                          onChange={setIptalGerekce}
                          required
                        />
                        <div className="yonetim-yetki-actions">
                          <button type="submit" className="universal-btn-save" disabled={isSaving}>
                            {isSaving ? "Kaydediliyor…" : "Kaldırmayı onayla"}
                          </button>
                          <button
                            type="button"
                            className="yonetim-panel-action"
                            disabled={isSaving}
                            onClick={() => setIptalId(null)}
                          >
                            Vazgeç
                          </button>
                        </div>
                      </form>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          )}
          {kapanmis.length > 0 ? (
            <details className="yonetim-yetki-details">
              <summary>Kapanmış kayıtlar ({kapanmis.length})</summary>
              <ul className="yonetim-yetki-liste">
                {kapanmis.map((istisna) => (
                  <li key={istisna.id} className="yonetim-yetki-satir">
                    <strong>{yetkiEtiketi(istisna.permission)}</strong>
                    <span className="yonetim-hint">
                      {etkiEtiketi(istisna.etki)} · kapanış {tarihMetni(istisna.iptalEdildiAt)}
                    </span>
                  </li>
                ))}
              </ul>
            </details>
          ) : null}
        </div>
      ) : null}

      {ozet && (ozet.kisitlilar.length > 0 || ozet.kisiselIzinler.length > 0) ? (
        <div className="yonetim-workspace-panel" data-testid="yonetim-yetki-fark">
          <p className="yonetim-workspace-panel-title">Etkin yetki farkı</p>
          {ozet.kisitlilar.length > 0 ? (
            <>
              <p className="yonetim-hint">Rolde var, kısıt nedeniyle kapalı</p>
              <ul className="yonetim-yetki-liste">
                {ozet.kisitlilar.map((permission) => (
                  <li key={permission} className="yonetim-yetki-satir">
                    <strong>{yetkiEtiketi(permission)}</strong>
                    <span className="yonetim-yetki-kod">{permission}</span>
                  </li>
                ))}
              </ul>
            </>
          ) : null}
          {ozet.kisiselIzinler.length > 0 ? (
            <>
              <p className="yonetim-hint">Rolün dışında, kişisel izinle açık</p>
              <ul className="yonetim-yetki-liste">
                {ozet.kisiselIzinler.map((permission) => (
                  <li key={permission} className="yonetim-yetki-satir">
                    <strong>{yetkiEtiketi(permission)}</strong>
                    <span className="yonetim-hint">{kaynakEtiketi(okuma?.kaynaklar[permission] ?? "USER_ALLOW")}</span>
                    <span className="yonetim-yetki-kod">{permission}</span>
                  </li>
                ))}
              </ul>
            </>
          ) : null}
        </div>
      ) : null}

      {okuma ? (
        <details className="yonetim-yetki-details" data-testid="yonetim-yetki-rol-varsayilan">
          <summary>Rol varsayılanları ({okuma.rolVarsayilanlari.length})</summary>
          <FormField
            label="Rol yetkisinde ara"
            name="yonetim-yetki-rol-ara"
            value={listeArama}
            onChange={setListeArama}
            type="search"
          />
          <ul className="yonetim-yetki-liste">
            {rolListesi.map((permission) => (
              <li key={permission} className="yonetim-yetki-satir">
                <strong>{yetkiEtiketi(permission)}</strong>
                <span className="yonetim-yetki-kod">{permission}</span>
              </li>
            ))}
          </ul>
          {rolListesi.length === 0 ? <p className="yonetim-hint">Aramayla eşleşen rol yetkisi yok.</p> : null}
        </details>
      ) : null}

      {yazilabilir ? (
        <form
          className="yonetim-workspace-panel yonetim-yetki-form"
          data-testid="yonetim-yetki-form"
          onSubmit={(event) => {
            event.preventDefault();
            void kaydet();
          }}
        >
          <p className="yonetim-workspace-panel-title">Yetki ver veya kısıtla</p>
          <p className="yonetim-hint">
            İzin, rolde olmayan bir yetkiyi açar. Kısıt, rolde olan bir yetkiyi kapatır. Hassas yetkiler
            listede yoktur. Bitiş boşsa kayıt kalıcıdır; doluysa süreli izinde en fazla 365 gündür.
          </p>
          <FormField
            as="select"
            label="İşlem"
            name="yonetim-yetki-etki"
            value={etki}
            onChange={(value) => setEtki(value === "ALLOW" ? "ALLOW" : "DENY")}
            selectOptions={[
              { value: "DENY", label: "Kısıtla" },
              { value: "ALLOW", label: "İzin ver" }
            ]}
          />
          <FormField
            label="Yetki ara"
            name="yonetim-yetki-arama"
            value={arama}
            onChange={setArama}
            type="search"
          />
          <FormField
            as="select"
            label="Yetki"
            name="yonetim-yetki-izin"
            value={izin}
            onChange={setIzin}
            placeholderOption={{ value: "", label: secenekler.length === 0 ? "Seçilecek yetki yok" : "Seçiniz" }}
            selectOptions={secenekler.map((permission) => ({
              value: permission,
              label: `${yetkiEtiketi(permission)} (${permission})`
            }))}
            required
          />
          <FormField
            as="textarea"
            label="Gerekçe"
            name="yonetim-yetki-gerekce"
            value={gerekce}
            onChange={setGerekce}
            required
          />
          <FormField
            label="Bitiş tarihi"
            name="yonetim-yetki-bitis"
            type="date"
            value={bitis}
            onChange={setBitis}
          />
          <div className="yonetim-yetki-actions">
            <button type="submit" className="universal-btn-save" data-testid="yonetim-yetki-kaydet" disabled={isSaving}>
              {isSaving ? "Kaydediliyor…" : etki === "DENY" ? "Kısıtı kaydet" : "İzni kaydet"}
            </button>
          </div>
        </form>
      ) : null}

      {basari ? (
        <p className="yonetim-yetki-basari" role="status" data-testid="yonetim-yetki-basari">
          {basari}
        </p>
      ) : null}
      {actionError ? (
        <p className="yonetim-inline-error" role="alert" data-testid="yonetim-yetki-hata">
          {actionError}
        </p>
      ) : null}

      {props.canViewAudit && okuma ? (
        <div className="yonetim-workspace-panel" data-testid="yonetim-yetki-gecmis">
          <p className="yonetim-workspace-panel-title">Yetki geçmişi</p>
          {!semaHazir ? (
            <p className="yonetim-hint">Geçmiş okunamadı. Kayıt yokmuş gibi gösterilmez.</p>
          ) : audit.length === 0 ? (
            <p className="yonetim-hint" data-testid="yonetim-yetki-gecmis-bos">
              Bu kullanıcı için yetki değişikliği kaydı yok.
            </p>
          ) : (
            <ul className="yonetim-yetki-liste">
              {audit.map((kayit) => (
                <li key={kayit.id} className="yonetim-yetki-satir">
                  <div className="yonetim-yetki-satir-ust">
                    <strong>{aksiyonEtiketi(kayit.aksiyon)}</strong>
                    <span className="yonetim-hint">{tarihMetni(kayit.createdAt)}</span>
                  </div>
                  {kayit.permission ? (
                    <span>
                      {yetkiEtiketi(kayit.permission)}
                      {kayit.etki ? ` · ${etkiEtiketi(kayit.etki)}` : ""}
                    </span>
                  ) : null}
                  <span className="yonetim-hint">{kayit.aktorUsername || "—"}</span>
                  {kayit.gerekce ? <span className="yonetim-hint">{kayit.gerekce}</span> : null}
                  {uyariMetinleri(kayit.uyariKodlari).map((metin) => (
                    <span key={metin} className="yonetim-hint">
                      {metin}
                    </span>
                  ))}
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}
    </section>
  );
}
