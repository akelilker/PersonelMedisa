-- S76 Donem kapanis ve etki adayi rapor indexleri
-- Additive indexes only.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE onayli_bildirim_puantaj_etki_adaylari
  ADD KEY idx_obpea_sube_ay_state (sube_id, ay, state);

ALTER TABLE onayli_bildirim_puantaj_etki_adaylari
  ADD KEY idx_obpea_sube_ay_conflict (sube_id, ay, conflict_code);

ALTER TABLE onayli_bildirim_puantaj_etki_adaylari
  ADD KEY idx_obpea_sube_ay_uygulama (sube_id, ay, uygulama_modu);

ALTER TABLE gunluk_puantaj
  ADD KEY idx_gp_personel_tarih_kontrol (personel_id, tarih, kontrol_durumu);

ALTER TABLE aylik_bildirim_onaylari
  ADD KEY idx_abo_sube_ay_amir (sube_id, ay, birim_amiri_user_id);

ALTER TABLE genel_yonetici_bildirim_onaylari
  ADD KEY idx_gybo_sube_ay_state (sube_id, ay, state);
