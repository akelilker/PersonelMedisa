## ISG Module Archive

Bu klasor, aktif urun akisindan cikarilan ISG MVP calismasini saklar.

Arsivlenme nedeni:
- mevcut odak puantaj ve mevzuat uyumu
- ISG calismasi urun onceligi disina tasti
- kod kaybolmasin, ileride kontrollu sekilde geri alinabilsin

Aktif akistan cikarilan yuzeyler:
- `/isg`
- `/isg/:makineId`
- `Raporlar` icindeki ISG girisi
- aktif stil importu

Bu arsivde saklanan owner dosyalar:
- `src/features/isg/*`
- `src/hooks/useIsgMakineler.ts`
- `src/hooks/useIsgMakineDetay.ts`
- `src/api/isg.api.ts`
- `src/types/isg.ts`
- `src/styles/modules/isg.css`
- `tests/e2e/isg.spec.ts`

Not:
- Merkezi `data-manager`, `endpoints`, mock ve permission dosyalarindaki ISG izleri
  bilerek oldugu gibi birakildi. Bunlar aktif urun akisina bagli degil ve ileride geri
  alma ihtimalini zorlastirmamak icin korundu.
