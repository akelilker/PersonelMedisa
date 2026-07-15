export const endpoints = {
  auth: {
    login: "/auth/login"
  },
  personeller: {
    list: "/personeller",
    detail: (id: number | string) => `/personeller/${id}`,
    belgeDurumu: (id: number | string) => `/personeller/${id}/belge-durumu`
  },
  personelBelgeKayitlari: {
    listByPersonel: (personelId: number | string) => `/personeller/${personelId}/belge-kayitlari`,
    create: (personelId: number | string) => `/personeller/${personelId}/belge-kayitlari`,
    detail: (id: number | string) => `/belge-kayitlari/${id}`,
    cancel: (id: number | string) => `/belge-kayitlari/${id}/iptal`
  },
  surecler: {
    list: "/surecler",
    detail: (id: number | string) => `/surecler/${id}`
  },
  zimmetler: {
    list: "/zimmetler",
    detail: (id: number | string) => `/zimmetler/${id}`
  },
  bildirimler: {
    list: "/bildirimler",
    birimAmiriSecenekleri: "/bildirimler/birim-amiri-secenekleri",
    detail: (id: number | string) => `/bildirimler/${id}`,
    submit: (id: number | string) => `/bildirimler/${id}/submit`,
    requestCorrection: (id: number | string) => `/bildirimler/${id}/request-correction`
  },
  haftalikBildirimMutabakatlari: {
    summary: "/haftalik-bildirim-mutabakatlari/ozet",
    approve: "/haftalik-bildirim-mutabakatlari",
    detail: (id: number | string) => `/haftalik-bildirim-mutabakatlari/${id}`
  },
  aylikBildirimOnaylari: {
    summary: "/aylik-bildirim-onaylari/ozet",
    approve: "/aylik-bildirim-onaylari",
    detail: (id: number | string) => `/aylik-bildirim-onaylari/${id}`
  },
  genelYoneticiBildirimOnaylari: {
    summary: "/genel-yonetici-bildirim-onaylari/ozet",
    approve: "/genel-yonetici-bildirim-onaylari"
  },
  puantaj: {
    detail: (personelId: number | string, tarih: string) =>
      `/gunluk-puantaj/${personelId}/${encodeURIComponent(tarih)}`,
    muhurle: "/puantaj/muhurle",
    bildirimEtkiAdaylari: {
      list: "/puantaj/bildirim-etki-adaylari",
      ozet: "/puantaj/bildirim-etki-adaylari/ozet",
      detail: (id: number | string) => `/puantaj/bildirim-etki-adaylari/${id}`,
      yokSay: (id: number | string) => `/puantaj/bildirim-etki-adaylari/${id}/yok-say`,
      manuelUygula: (id: number | string) => `/puantaj/bildirim-etki-adaylari/${id}/manuel-uygula`,
      uygula: (id: number | string) => `/puantaj/bildirim-etki-adaylari/${id}/uygula`
    }
  },
  haftalikKapanis: {
    close: "/haftalik-kapanis",
    detail: (id: number | string) => `/haftalik-kapanis/${id}`,
    yillikFazlaCalisma: (personelId: number | string, yil: number | string) =>
      `/haftalik-kapanis/yillik-fazla-calisma?personel_id=${personelId}&yil=${yil}`
  },
  revizyonTalepleri: {
    list: "/haftalik-kapanis/revizyon-talepleri",
    detail: (id: number | string) => `/haftalik-kapanis/revizyon-talepleri/${id}`,
    create: "/haftalik-kapanis/revizyon-talepleri",
    submit: (id: number | string) => `/haftalik-kapanis/revizyon-talepleri/${id}/gonder`,
    approve: (id: number | string) => `/haftalik-kapanis/revizyon-talepleri/${id}/onay`,
    reject: (id: number | string) => `/haftalik-kapanis/revizyon-talepleri/${id}/red`,
    cancel: (id: number | string) => `/haftalik-kapanis/revizyon-talepleri/${id}/iptal`
  },
  revizyonCorrections: {
    list: "/haftalik-kapanis/revizyon-corrections",
    detail: (id: number | string) => `/haftalik-kapanis/revizyon-corrections/${id}`,
    produce: (talepId: number | string) =>
      `/haftalik-kapanis/revizyon-talepleri/${talepId}/correction-uret`,
    cancel: (id: number | string) => `/haftalik-kapanis/revizyon-corrections/${id}/iptal`
  },
  fazlaCalismaOdemeTercihi: {
    resource: "/fazla-calisma-odeme-tercihi"
  },
  serbestZaman: {
    events: "/serbest-zaman/events",
    bakiye: "/serbest-zaman/bakiye",
    olusum: "/serbest-zaman/olusum",
    kullanim: "/serbest-zaman/kullanim",
    iptal: "/serbest-zaman/iptal",
    duzeltme: "/serbest-zaman/duzeltme"
  },
  raporlar: {
    personelOzet: "/raporlar/personel-ozet",
    izin: "/raporlar/izin",
    devamsizlik: "/raporlar/devamsizlik",
    tesvik: "/raporlar/tesvik",
    ceza: "/raporlar/ceza",
    ekstraPrim: "/raporlar/ekstra-prim",
    isKazasi: "/raporlar/is-kazasi",
    bildirim: "/raporlar/bildirim"
  },
  finans: {
    list: "/ek-odeme-kesinti",
    detail: (id: number | string) => `/ek-odeme-kesinti/${id}`
  },
  isg: {
    list: "/isg/makineler",
    detail: (id: number | string) => `/isg/makineler/${id}`,
    bakimlar: (id: number | string) => `/isg/makineler/${id}/bakimlar`
  },
  referans: {
    departmanlar: "/referans/departmanlar",
    gorevler: "/referans/gorevler",
    personelTipleri: "/referans/personel-tipleri",
    bagliAmirler: "/referans/bagli-amirler",
    bildirimTurleri: "/referans/bildirim-turleri",
    surecTurleri: "/referans/surec-turleri",
    ucretTipleri: "/referans/ucret-tipleri",
    primKurallari: "/referans/prim-kurallari"
  },
  yonetim: {
    kullanicilar: "/yonetim/kullanicilar",
    kullaniciDetail: (id: number | string) => `/yonetim/kullanicilar/${id}`,
    subeler: "/yonetim/subeler",
    subeDetail: (id: number | string) => `/yonetim/subeler/${id}`,
    aylikOzet: "/yonetim/aylik-ozet",
    aylikOzetBolumOnay: "/yonetim/aylik-ozet/bolum-onay",
    aylikOzetKapat: "/yonetim/aylik-ozet/ay-kapat"
  }
};
