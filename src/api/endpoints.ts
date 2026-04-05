export const endpoints = {
  auth: {
    login: "/auth/login"
  },
  personeller: {
    list: "/personeller",
    detail: (id: number | string) => `/personeller/${id}`
  },
  surecler: {
    list: "/surecler",
    detail: (id: number | string) => `/surecler/${id}`
  },
  bildirimler: {
    list: "/bildirimler",
    detail: (id: number | string) => `/bildirimler/${id}`
  },
  puantaj: {
    detail: (personelId: number | string, tarih: string) =>
      `/gunluk-puantaj/${personelId}/${encodeURIComponent(tarih)}`
  },
  haftalikKapanis: {
    close: "/haftalik-kapanis"
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
  referans: {
    departmanlar: "/referans/departmanlar",
    gorevler: "/referans/gorevler",
    personelTipleri: "/referans/personel-tipleri",
    bagliAmirler: "/referans/bagli-amirler",
    bildirimTurleri: "/referans/bildirim-turleri",
    surecTurleri: "/referans/surec-turleri"
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
