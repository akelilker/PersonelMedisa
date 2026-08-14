# 129 — Pack7H Full Data Reconciliation + Final Import Readiness

**Tarih:** 2026-08-14
**Pack:** `Pack7H`
**Mode:** `FULL_BLOCKER_RESOLUTION_FINAL_IMPORT_READINESS`
**Base main:** `1ddbe59780c76cdf6543e0049e61d33d67bfc919`

## Deployment and repository baseline

| Alan | Sonuç |
| --- | --- |
| `PACK7G_C_MERGE_SHA` | `1ddbe59780c76cdf6543e0049e61d33d67bfc919` |
| `PACK7G_C_MERGE` | `YES` |
| `PACK7G_C_CI` | `PASS` |
| `PACK7G_C_DEPLOY` | `PASS` |
| `PACK7G_C_DEPLOY_RUN` | `31835129729` |
| `SOURCE_RECONCILIATION_MUTATION` | `NO` |

The Pack7G-C branch was not reused. This work starts from the exact merged main commit.

## Trusted sources

| Source | SHA-256 | Use |
| --- | --- | --- |
| Canonical workbook | `C449594165BF27F338D0D295D771CB54F5AA002EE86A2B8B989075498416806F` | Primary 122-person source |
| External predecessor | `50142B64A2CFD982196E6AA25DBF13612B3453CFC783348E0D44659B126027B0` | Historical Pack7E evidence |
| External successor | `C6E8476423101E06F34A6CDF7ACB1A566CAF7199A894DB89F8957F6E12A80AE2` | Current External-13 source |
| Updated EOS workbook | `5777457AFF86CD5B6E3F7410121FD2C6E00E96B2B17A58CE6101BFD4C3E49BE1` | Current EOS/KBS row data |
| EOS personnel bank | `490FE38469D499CBCD351E19FD8D33B90135E38ED23560324AC51AA1ABECAEF7` | Existing Pack7 evidence and cross-check |

The deterministic private reconciliation artifact is retained outside the repository because it contains personal data:

```text
C:\Users\Akel\Documents\medisa-ops-tmp\personel-import-122\pack7h-final-reconciliation.json
SHA256 = 82FD90AB4F24F158AB0E66F614919C075940A64105AE3DB267B14289B8599F78
```

## Canonical reconciliation

| Alan | Before | After | Provenance/result |
| --- | ---: | ---: | --- |
| Canonical rows | 122 | 122 | Exact source row count |
| Missing sicil | 24 | 24 | Personel Listesi.xls exact-TC crosswalk has no sicil for these canonical TCs; no safe alternative source found |
| Unresolved name split | 23 | 23 | Trusted sources contain combined Ad Soyad only; no exact Ad/Soyad structure |
| Required birth date missing | 5 | 5 | Updated EOS actual cells do not supply these values |
| Required phone missing | 26 | 26 | Updated EOS/KBS actual cells do not supply additional values for the final 26 |
| Distinct blocked canonical records | 55 | 55 | Overlap-aware count; categories are not summed |

The updated EOS workbook was inspected by actual row data, not summary text. Its `KBS Yüklenecekler` sheet contains 85 data rows and the actual `İşe Giriş Tarihi` cells are populated. That data does not provide the remaining sicil, separate surname, birth-date, or phone values needed by the 55 blocked canonical records.

No last-token surname split, fuzzy identity join, guessed sicil, guessed date, or guessed phone was applied.

## External reconciliation

| Alan | Before | After |
| --- | ---: | ---: |
| External rows | 13 | 13 |
| Unique sicil | 13 | 13 |
| Organizational blockers | 13 | 13 |
| Görev Kodu blockers | 8 | 8 |

External source coverage is sufficient for name, sicil and işe giriş for all 13. It does not establish all importer-required normal organizational references: branch, location, personnel type, and complete task mapping. Existing Pack7F design deliberately keeps normal organizational references required for both scopes; removing them would weaken directory authorization/display semantics and was not justified by source evidence.

For the eight task values, the source column labelled `Görev Kodu` contains descriptions rather than exact active catalog values; `Görev Adı` is blank for those rows. Exact catalog comparison found no active match and no formatting-only match:

| Sicil | Ad | Result |
| --- | --- | --- |
| 176 | RAED FAWAZ | Blocked — active task catalog exact match absent |
| 197 | SAIF TAREQ JASIM AL-GBURI | Blocked — active task catalog exact match absent |
| 206 | MUHAMMED IRAKLI | Blocked — active task catalog exact match absent |
| 213 | FETİYAN | Blocked — active task catalog exact match absent |
| 275 | ALADDİN DEREBAŞI | Blocked — active task catalog exact match absent |
| 283 | ABDULLAH | Blocked — active task catalog exact match absent |
| 355 | SEFİNE ÖZCAN | Blocked — active task catalog exact match absent |
| 375 | MUQTADA MAZIN KHALEE | Blocked — active task catalog exact match absent |

No fuzzy mapping or neighboring-worker copy was performed. No repository catalog row was invented without an authoritative catalog ID/provenance.

## Identity and production matching

| Kontrol | Sonuç |
| --- | --- |
| `CROSS_DATASET_TC_CONFLICTS` | `0` |
| `CROSS_DATASET_SICIL_CONFLICTS` | `0` |
| `IDENTITY_CONFLICTS` | `0` |
| `AMBIGUOUS` | `0` |
| `PRODUCTION_EXISTING_COUNT` | `4` |
| `PRODUCTION_MATCHES` | `0` |

The four production rows are smoke/fixture identities and are not present in either locked source population:

| Production ID | Masked TC | Sicil | Source result |
| ---: | --- | --- | --- |
| 1 | `111******11` | `P-0001` | Canonical: no; External: no |
| 2 | `222******22` | `P-0002` | Canonical: no; External: no |
| 3 | `999******50` | `S23B-1782775050` | Canonical: no; External: no |
| 4 | `999******74` | `S24B-0325` | Canonical: no; External: no |

Therefore the no-match projection remains valid and no existing row is overwritten.

## Final projection

```text
CANONICAL_COUNT = 122
EXTERNAL_COUNT = 13
SOURCE_TOTAL = 135
PRODUCTION_EXISTING_COUNT = 4
PRODUCTION_MATCHES = 0
NEW_IC_PERSONEL = 122
NEW_DIS_KAYNAK = 13
EXPECTED_IC_PERSONEL_AFTER_IMPORT = 126
EXPECTED_DIS_KAYNAK_AFTER_IMPORT = 13
EXPECTED_TOTAL_AFTER_IMPORT = 139
```

Arithmetic: `4 + 122 + 13 - 0 = 139`.

## Safety and validation

```text
VALIDATION_BLOCKED = 68
DRY_RUN = FAIL
IMPORT_ATOMICITY = PASS
IMPORT_IDEMPOTENCY = PASS
TC_UNIQUE = PASS
SICIL_UNIQUE = PASS
REFERENCE_INTEGRITY = FAIL
IC_VALIDATION = FAIL
DIS_VALIDATION = FAIL
SGK_ISOLATION = PASS
BORDRO_ISOLATION = PASS
PUANTAJ_ISOLATION = PASS
BLANK_TC_NORMALIZED_TO_NULL = YES
BLANK_SICIL_NORMALIZED_TO_NULL = YES
```

The importer and domain owners were not weakened. The real dry-run cannot pass while 55 canonical identity records and 13 external organization records remain unresolved. The existing all-or-nothing transaction and idempotency tests remain green.

## User-facing language and production restrictions

```text
USER_FACING_MESSAGES_TURKISH = YES
TECHNICAL_ERROR_CODES_PRESERVED = YES
PRODUCTION_MUTATED = NO
IMPORT_APPLY = NO
MERGE = NO
DEPLOY = NO
```

## Final status

```text
IMPORT_READY = NO
FINAL_STATUS = BLOCKED
BLOCKERS = 55 distinct canonical records (24 sicil, 23 name split, 5 birth date, 26 phone overlapping); 13 external organizational records; 8 external task catalog mappings
```

No further source-safe resolution was available. The remaining records are listed with exact field/source-search results in the private deterministic artifact above. Import requires user-provided or separately authorized authoritative values for those records; no production import/apply is proposed or executed.

## Authoritative continuation — supplemental source

The first-pass result above is historical evidence and is preserved unchanged. The continuation was run deterministically from the same branch using the user-approved secondary source:

```text
MODE = AUTHORITATIVE_SOURCE_RECONCILIATION_CONTINUATION
SUPPLEMENTAL_SOURCE_PATH = C:\Users\Akel\Downloads\Personel_Listesi_Bolumlere_Gore_Duzenli.xlsx
SUPPLEMENTAL_SOURCE_HASH = D0BB5DB62DFE43A3C190E8D17252D98A6B15855C62F980454337CD6DA4DBEB15
SUPPLEMENTAL_SHEET_COUNT = 1
SUPPLEMENTAL_SHEET = Personel Listesi
SUPPLEMENTAL_PERSONNEL_ROWS = 146
```

The workbook contains these actual columns: `Doc`, `Personel Kodu`, `Adı Soyadı`, `KartNo`, `TC Kimlik No`, `GSM`, `Tel`, `Departman Kodu` (two source columns), `Cinsiyet`, `Kan Grubu`, `Öğrenim Durumu`, `Ünvan Kodu`, `Ünvan Adı`, `Bölüm Kodu`, `Bölüm Adı`, `Departman Adı`, `Görev Kodu`, `Görev Adı`, `Giriş Tarihi`, `Doğum Tarihi`, `Doğum Yeri`, `Durumu`, `Toplam İzin Hakkı`, `Hizmet Süresi`, `Kullanılan İzin`, `Kalan Izin`.

```text
HAS_TC = YES
HAS_SICIL = YES
HAS_FIRST_NAME = NO
HAS_SURNAME = NO
HAS_FULL_NAME = YES
HAS_PHONE = YES
HAS_BIRTH_DATE = YES
HAS_HIRE_DATE = YES
HAS_DEPARTMENT_CODE = YES
HAS_DEPARTMENT_NAME = YES
HAS_TASK_CODE = YES
HAS_TASK_NAME = YES
HAS_BRANCH = NO
HAS_LOCATION = NO
HAS_PERSONNEL_TYPE = NO
```

No source workbook was modified. Canonical and External lineage/hash records remain intact. Canonical matching used exact normalized TC; External matching used exact normalized Personel Kodu. No name-only or fuzzy join was used.

### Continuation results

| Alan | Before | After | Supplemental result |
| --- | ---: | ---: | --- |
| Canonical rows / unique | 122 / 122 | 122 / 122 | unchanged |
| External rows / unique | 13 / 13 | 13 / 13 | unchanged |
| Missing sicil | 24 | 4 | 20 resolved by exact TC |
| Unresolved name split | 23 | 23 | 0; no separate Ad/Soyad columns |
| Required birth date missing | 5 | 5 | 0; no valid value for the five exact-TC rows |
| Required phone missing | 26 | 26 | 0; no valid phone for the remaining exact-TC rows |
| Distinct canonical blockers | 55 | 41 | overlap-aware; no guessed fields |
| External organization blockers | 13 | 13 | branch/location/personnel type absent; department alone is insufficient |
| External Görev Kodu blockers | 8 | 8 | `Görev Adı` is blank and source code values are descriptions, not active catalog IDs |

The eight task-review rows are `176 RAED FAWAZ`, `197 SAIF TAREQ JASIM AL-GBURI`, `206 MUHAMMED IRAKLI`, `213 FETİYAN`, `275 ALADDİN DEREBAŞI`, `283 ABDULLAH`, `355 SEFİNE ÖZCAN`, and `375 MUQTADA MAZIN KHALEE`. For the first six and `375`, the supplemental source supplies a non-empty description in `Görev Kodu` but no `Görev Adı`; `355` has no supplemental row. None has an exact active-catalog code/name pair, so all remain blocked. No repository catalog row, fuzzy mapping, or neighboring-worker value was invented.

### Current validation and projection

```text
CANONICAL_COUNT = 122
CANONICAL_UNIQUE = 122
EXTERNAL_COUNT = 13
EXTERNAL_UNIQUE = 13
SOURCE_TOTAL = 135

MISSING_SICIL_BEFORE = 24
MISSING_SICIL_AFTER = 4
SICIL_RESOLVED = 20
UNRESOLVED_NAME_SPLIT_BEFORE = 23
UNRESOLVED_NAME_SPLIT_AFTER = 23
NAME_SPLITS_RESOLVED = 0
MISSING_REQUIRED_BIRTH_DATE_BEFORE = 5
MISSING_REQUIRED_BIRTH_DATE_AFTER = 5
BIRTH_DATES_RESOLVED = 0
MISSING_REQUIRED_PHONE_BEFORE = 26
MISSING_REQUIRED_PHONE_AFTER = 26
PHONES_RESOLVED = 0
CANONICAL_BLOCKED_DISTINCT_BEFORE = 55
CANONICAL_BLOCKED_DISTINCT_AFTER = 41
EXTERNAL_ORG_BLOCKERS_BEFORE = 13
EXTERNAL_ORG_BLOCKERS_AFTER = 13
EXTERNAL_ORG_RESOLVED_FROM_SUPPLEMENTAL_SOURCE = 0
EXTERNAL_GOREV_BLOCKERS_BEFORE = 8
EXTERNAL_GOREV_BLOCKERS_AFTER = 8

DUPLICATE_TC_GROUPS = 0
DUPLICATE_SICIL_GROUPS = 0
CROSS_DATASET_TC_CONFLICTS = 0
CROSS_DATASET_SICIL_CONFLICTS = 0
IDENTITY_CONFLICTS = 0
AMBIGUOUS = 23

PRODUCTION_EXISTING_COUNT = 4
PRODUCTION_MATCHES = 0
NEW_IC_PERSONEL = 122
NEW_DIS_KAYNAK = 13
EXPECTED_IC_PERSONEL_AFTER_IMPORT = 126
EXPECTED_DIS_KAYNAK_AFTER_IMPORT = 13
EXPECTED_TOTAL_AFTER_IMPORT = 139

DRY_RUN = FAIL
VALIDATION_BLOCKED = 54
REFERENCE_INTEGRITY = FAIL
IC_VALIDATION = FAIL
DIS_VALIDATION = FAIL
TC_UNIQUE = PASS
SICIL_UNIQUE = PASS
IMPORT_ATOMICITY = PASS
IMPORT_IDEMPOTENCY = PASS
DIS_KAYNAK_SGK_ISOLATED = YES
DIS_KAYNAK_BORDRO_ISOLATED = YES
DIS_KAYNAK_PUANTAJ_ISOLATED = YES
USER_FACING_MESSAGES_TURKISH = YES
IMPORT_READY = NO
FINAL_STATUS = BLOCKED
```

`VALIDATION_BLOCKED = 54` is `41` overlap-aware canonical records plus `13` External organization records. The four existing production fixture rows remain unmatched, so the projection remains `4 + 122 + 13 = 139`. Production was not mutated and no import/apply, merge, or deploy was performed.

### Continuation artifacts

```text
ENRICHED_ARTIFACT_PATH = C:\Users\Akel\Documents\medisa-ops-tmp\personel-import-122\pack7h-final-reconciliation-v2.json
ENRICHED_ARTIFACT_HASH = 10F6FD4DE097283BAADA195F6367BEC2E692FB1138C7EC49D6568FD8CEAFD3C2
USER_INPUT_WORKBOOK_CREATED = YES
USER_INPUT_WORKBOOK_PATH = C:\Users\Akel\Documents\medisa-ops-tmp\personel-import-122\pack7h-kullanici-tamamlamasi-gerekenler.xlsx
```

The successor artifact contains all 122 canonical and 13 External rows, original/resolved values, source hash/row, strong identity key, reason, and confidence for supplemental enrichment. The user-input workbook contains only remaining unresolved fields and Turkish instructions; neither private artifact is committed.

## Current authoritative continuation — 2026-08-15

The following continuation records the user's authoritative business decisions without changing
the canonical workbook or applying any production mutation:

```text
PACK = Pack7H
MODE = USER_AUTHORITATIVE_COMPLETION
BRANCH = feat/pack7h-full-reconciliation
PR = #168
PREVIOUS_HEAD = 1092594cad4ef5435ab040decc202bb84e8099aa

USER_NAME_SPLITS_CONFIRMED = 23
USER_NAME_SPLITS_APPLIED = 23
UNRESOLVED_NAME_SPLIT_AFTER = 0
NAME_SPLITS_RESOLVED_BY_USER = 23

USER_EXTERNAL_FACTORY_CONFIRMED = 13
USER_EXTERNAL_KARABUK_CONFIRMED = 13
USER_EXTERNAL_SUREKLI_PERSONEL_CONFIRMED = 13
EXACT_SUBE_REFERENCE = BLOCKED (no active Fabrika row)
EXACT_LOCATION_REFERENCE = Karabük
EXACT_PERSONEL_TIPI_REFERENCE = BLOCKED (no active Sürekli Personel row)
EXTERNAL_SUBE_BLOCKERS_AFTER = 13
EXTERNAL_LOCATION_BLOCKERS_AFTER = 0
EXTERNAL_PERSONEL_TIPI_BLOCKERS_AFTER = 13
EXTERNAL_ORG_BLOCKERS_AFTER = 13
```

The live read-only reference export returned one usable active `CALISMA_LOKASYONU=Karabük`
row. It returned no active exact `SUBE=Fabrika` or `PERSONEL_TIPI=Sürekli Personel` row;
no reference row was created and no fuzzy semantic mapping was used.

### External task reconciliation

The completion workbook contained 13 task rows, so the previous report of eight was stale.
The live catalog and supplemental source were reconciled for every External worker:

| SICIL | NAME | EXTERNAL_SUCCESSOR_RAW_GOREV | SUPPLEMENTAL_GOREV_KODU | SUPPLEMENTAL_GOREV_ADI | ACTIVE_GOREV_CATALOG_MATCH | FINAL_GOREV_REFERENCE | STATUS |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 176 | RAED FAWAZ | RAED FAWAZ | İSKELETHANE | — | NO | — | BLOCKED |
| 197 | SAIF TAREQ JASIM AL-GBURI | SAIF TAREQ JASIM AL-GBURI | ÇAKIMA HAZIRLIK | — | NO | — | BLOCKED |
| 201 | AHMED KHALIL ALSAMAR | AHMED KHALIL ALSAMAR | — | — | NO | — | BLOCKED |
| 206 | MUHAMMED IRAKLI | MUHAMMED IRAKLI | DEPO VE SEVKİYAT ELE | — | NO | — | BLOCKED |
| 213 | FETİYAN | FETİYAN | PVC + HERİŞ | — | NO | — | BLOCKED |
| 275 | ALADDİN DEREBAŞI | ALADDİN DEREBAŞI | DEPO VE SEVKİYAT ELE | — | NO | — | BLOCKED |
| 283 | ABDULLAH | ABDULLAH | DEPO VE SEVKİYAT ELE | — | NO | — | BLOCKED |
| 285 | OKTAY ERSÖZ | OKTAY ERSÖZ | — | — | NO | — | BLOCKED |
| 355 | SEFİNE ÖZCAN | SEFİNE ÖZCAN | — | — | NO | — | BLOCKED |
| 375 | MUQTADA MAZIN KHALEE | MUQTADA MAZIN KHALEE | DEPO VE SEVKİYAT ELE | — | NO | — | BLOCKED |
| 398 | MUHAMMAT FAWAZ | MUHAMMAT FAWAZ | — | — | NO | — | BLOCKED |
| 407 | FAHRİ TAYLAN MERCAN | FAHRİ TAYLAN MERCAN | — | — | NO | — | BLOCKED |
| 427 | MUSTAFA HAMİD | MUSTAFA HAMİD | — | — | NO | — | BLOCKED |

```text
EXTERNAL_GOREV_WORKBOOK_ROWS = 13
EXTERNAL_GOREV_PREVIOUS_REPORTED_BLOCKERS = 8
EXTERNAL_GOREV_TRUE_BLOCKERS_AFTER_RECONCILIATION = 13
GOREV_BLOCKER_COUNT_INCONSISTENCY_RESOLVED = YES
```

The five rows 201, 285, 398, 407, and 427 have no valid exact supplemental task pair;
their blank task fields are therefore genuine blockers, not generator omissions. Missing
supplemental departments also remain unresolved where the exact sicil row has no department.

### Authoritative result and projection

```text
CANONICAL_COUNT = 122
EXTERNAL_COUNT = 13
MISSING_SICIL_AFTER = 4
MISSING_REQUIRED_BIRTH_DATE_AFTER = 5
MISSING_REQUIRED_PHONE_AFTER = 26
CANONICAL_BLOCKED_DISTINCT_AFTER = 26
IDENTITY_CONFLICTS = 0
AMBIGUOUS = 0

PRODUCTION_EXISTING_COUNT = 4
PRODUCTION_MATCHES = 0
NEW_IC_PERSONEL = 122
NEW_DIS_KAYNAK = 13
EXPECTED_IC_PERSONEL_AFTER_IMPORT = 126
EXPECTED_DIS_KAYNAK_AFTER_IMPORT = 13
EXPECTED_TOTAL_AFTER_IMPORT = 139

VALIDATION_BLOCKED = 58
REFERENCE_INTEGRITY = FAIL
IC_VALIDATION = FAIL
DIS_VALIDATION = FAIL
DRY_RUN = FAIL
IMPORT_READY = NO
FINAL_STATUS = BLOCKED
```

The real application dry-run returned HTTP 200 with `135` input rows, `77` valid rows,
and `58` invalid rows. The 58 value is the importer diagnostic count (not the
overlap-aware distinct canonical count plus External count); its error codes were
`PERSONEL_IMPORT_EKSIK_ALAN=58` and `PERSONEL_IMPORT_REFERANS_BULUNAMADI=13`.

The authoritative identity decisions remove all 23 name-split ambiguities. They do not
invent the remaining canonical sicil, birth-date, or phone values, and they do not relax
the IC contract. External location is resolved, while branch/personnel type and task
references remain fail-closed against the active catalog.

Private successor artifacts:

```text
ENRICHED_ARTIFACT_V3 = C:\Users\Akel\Documents\medisa-ops-tmp\personel-import-122\pack7h-final-reconciliation-v3.json
ENRICHED_ARTIFACT_V3_HASH = 0601D16DA05D85C4A525C78BDC4E0BCD628E32597EDA265EAD20149698F1F820
USER_INPUT_WORKBOOK_V2_CREATED = YES
USER_INPUT_WORKBOOK_V2_PATH = C:\Users\Akel\Documents\medisa-ops-tmp\personel-import-122\pack7h-kullanici-tamamlamasi-gerekenler-v2.xlsx
USER_INPUT_WORKBOOK_V2_HASH = 8032C7D50086F46A0A00D5DF86344ADF05734D2497EB75ADC4CD5B4C6767E0FF
```

The final workbook contains 81 unresolved-field rows: Telefon 26, Sicil 4, Doğum Tarihi
5, Şube 13, Personel Türü 13, Görev Kodu + Görev Adı 13, and Departman 7. Resolved names
and exact active Karabük location are intentionally absent.

```text
IMPORT_ATOMICITY = PASS
IMPORT_IDEMPOTENCY = PASS
DIS_KAYNAK_SGK_ISOLATED = YES
DIS_KAYNAK_BORDRO_ISOLATED = YES
DIS_KAYNAK_PUANTAJ_ISOLATED = YES
PRODUCTION_MUTATED = NO
IMPORT_APPLY = NO
MERGE = NO
DEPLOY = NO
UNRELATED_CHANGE = NO
```

## Final semantic reconciliation — 2026-08-15

The previous continuation's literal `Fabrika` lookup was a semantic error. Pack6B's
established mapping is `Medisa (Merkez Karabük) → MRK`; the live catalog now confirms
that `MRK` is uniquely active as `id=1`, `kod=MRK`, `ad=Medisa`. No production branch
row was renamed or changed.

```text
PACK = Pack7H
MODE = FINAL_SEMANTIC_RECONCILIATION
PREVIOUS_HEAD = 71ca3f2ba09a335f0f02ba982f2f8f31af4bfb3f
PR = #168

BRANCH_MAPPING_OWNER = Pack6B established canonical branch mapping
EXTERNAL_BRANCH_REFERENCE = MRK / Medisa (id=1, AKTIF)
EXTERNAL_SUBE_BLOCKERS_AFTER = 0
EXTERNAL_LOCATION_REFERENCE = Karabük (unique active location)
EXTERNAL_LOCATION_BLOCKERS_AFTER = 0
```

### Semantic column proof

The supplemental workbook was reopened and joined to the canonical control population
using exact TC only. The supplemental workbook has 146 personnel rows; 117 canonical
rows joined exactly by TC. Its source-column coverage was:

```text
SEMANTIC_CONTROL_ROWS = 117
SUPPLEMENTAL_UNVAN_ADI_NONEMPTY = 0 / 117
SUPPLEMENTAL_DEPARTMAN_ADI_NONEMPTY = 48 / 117
SUPPLEMENTAL_BOLUM_ADI_NONEMPTY = 0 / 117
SUPPLEMENTAL_BIRIM_COLUMN_PRESENT = NO
EXTERNAL_EXACT_SICIL_ROWS_IN_SUPPLEMENTAL = 10 / 13
```

Pack6B remains the authoritative application domain owner:

```text
SUPPLEMENTAL_UNVAN_TO_APPLICATION_GOREV = PROVEN_BY_PACK6B_DOMAIN_MAPPING
UNVAN_TO_GOREV_PROVEN = YES
SUPPLEMENTAL_GOREV_APPLICATION_OWNER = NO
GOREV_COLUMN_ACTUAL_SEMANTICS = source operational/function description, not application gorevler
DEPARTMAN_SOURCE_TO_APPLICATION_DEPARTMAN = NO_EXACT_CONTROL_PROOF
BOLUM_SOURCE_TO_APPLICATION_BOLUM = NO_SOURCE_VALUE_FOR_EXTERNAL
BIRIM_SOURCE_TO_APPLICATION_BIRIM = NO_SOURCE_COLUMN
```

Therefore values such as `İSKELETHANE`, `ÇAKIMA HAZIRLIK`, `DEPO VE SEVKİYAT ELE`,
and `PVC + HERİŞ` were not inserted into or guessed against `gorevler`. External
`Ünvan Adı` is empty in the exact supplemental rows, so the 13 job blockers remain
genuine human/business-data blockers.

```text
EXTERNAL_GOREV_BLOCKERS_BEFORE = 13
EXTERNAL_GOREV_BLOCKERS_AFTER = 13
EXTERNAL_DEPARTMAN_BLOCKERS_AFTER = 13
EXTERNAL_BOLUM_BLOCKERS_AFTER = 13
EXTERNAL_BIRIM_BLOCKERS_AFTER = 13
```

### Personel tipi semantics

```text
ACTIVE_PERSONEL_TYPES = [Beyaz Yaka, Diğer, Mavi Yaka, Sozlesmeli, Tam Zamanli]
EXTERNAL_PERSONEL_TYPE_SOURCE_FOUND = NO
EXTERNAL_PERSONEL_TYPE_BLOCKERS_AFTER = 13
EXTERNAL_PERSONEL_TYPE_USER_DECISION_REQUIRED = YES
PROPOSED_COMMON_TYPE = NONE
```

The KBS/Jandarma phrase `Sürekli Personel` has no exact application `personel_tipleri`
equivalent. No `Grup`, yaka, or employee-class source was found for these 13 rows, so
one common type decision remains required; no new reference was created.

### Actual v4 dry-run and staging proof

The production dry-run used the deterministic v4 staging CSV, not the earlier raw/v2
payload. It contains 122 canonical and 13 External rows, user-confirmed name splits,
20 recovered sicils, `sube=Medisa` for External rows (the MRK display reference), and
`calisma_lokasyonu=Karabük`.

```text
REAL_DRY_RUN_TOTAL = 135
REAL_DRY_RUN_VALID_IC = 77
REAL_DRY_RUN_INVALID_IC = 45
REAL_DRY_RUN_VALID_DIS = 0
REAL_DRY_RUN_INVALID_DIS = 13
REAL_DRY_RUN_VALID_TOTAL = 77
REAL_DRY_RUN_INVALID_TOTAL = 58
ERROR_OCCURRENCES_PERSONEL_IMPORT_EKSIK_ALAN = 58
ERROR_OCCURRENCES_PERSONEL_IMPORT_REFERANS_BULUNAMADI = 13
CAN_APPLY = NO
PRODUCTION_EXISTING_COUNT = 4
PRODUCTION_MATCHES = 0
PRODUCTION_COUNT_UNCHANGED = YES
```

The 58 invalid rows are 45 IC row failures plus 13 External row failures. Error
occurrences are reported separately and are not treated as distinct-person counts.

### Final human-required inputs

```text
CANONICAL_MISSING_SICIL = 4
CANONICAL_MISSING_BIRTH_DATE = 5
CANONICAL_MISSING_PHONE = 26
CANONICAL_BLOCKED_DISTINCT = 26
```

The final decision workbook contains only human-required inputs: canonical identity
fields, External department/bölüm/birim/job decisions, and one common External
personel-type decision with exact active-catalog dropdown options. Resolved names,
Karabük, and MRK are absent.

```text
ENRICHED_ARTIFACT_V4 = C:\Users\Akel\Documents\medisa-ops-tmp\personel-import-122\pack7h-final-reconciliation-v4.json
ENRICHED_ARTIFACT_V4_HASH = 7A4F9D87C77375559F728C14B7D63EE0B0411A326681FA809C58793D7BAE9008
USER_WORKBOOK_V3_CREATED = YES
USER_WORKBOOK_V3_PATH = C:\Users\Akel\Documents\medisa-ops-tmp\personel-import-122\pack7h-kullanici-tamamlamasi-gerekenler-v3.xlsx
USER_WORKBOOK_V3_ROWS = 88
USER_WORKBOOK_V3_HASH = 7A499DC9C43CDAEDCD07072EA6FEE8AED9D7086755F32028891E7BFBB9AD173F
```

```text
PRODUCTION_MUTATED = NO
PRODUCTION_REFERENCE_MUTATED = NO
IMPORT_APPLY = NO
MERGE = NO
DEPLOY = NO
IMPORT_READY = NO
FINAL_STATUS = BLOCKED
```
