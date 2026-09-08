# 141 — Business truth & branch-manager model lock

**Tür:** Bağlayıcı iş gerçeği + kanonik ürün kuralı (no-apply).
**Faz:** `BUSINESS_TRUTH_AND_BRANCH_MANAGER_MODEL_LOCK`
**Tarih:** 2026-09-07
**Mutation:** 0 — production write / deploy / apply yok.
**Hosting:** EXTERNAL_PROVIDER_INCIDENT / CONTROL_PLANE_DEGRADED — live verify blocked.

## A1 formal SGK pair (temporary business assignment)

| Slot | Identity | Notes |
| --- | --- | --- |
| PREPARER | `sedanurB` / `IK_SORUMLUSU` | prepare YES; approve NO; needs explicit `user_subeler` 12/13 |
| APPROVER | Sinem Hamaloğlu | intended formal SGK approver role/model = `BOLUM_YONETICISI` |

Bu seçim geçici business assignment’tır; ileride değiştirilebilir.
Sinem’in production username / user_id / personel_id / rol / actor / branch scope / bolum_ids değerleri hosting recovery olmadan tahmin edilmez → `VERIFY_LIVE_REQUIRED`.

Local historical keys (not live-certified): `user_id=110`, `personel_id=173`, last closed username `sinemH`.

Locked hashes (no rerun unless payload/preimage changes):

- sube 12 = `43e3a75e2c3f4c5f6eef72b2036d9498c4c847c9594923bab9e61691da4559af`
- sube 13 = `c155365fb2670836ff6388248fd755634b832454872ed441efa8b94372d4b4bb`

## Physical work location truth

`calisma_lokasyonu_id = 5` = Fabrika / Karabük.

| PERSONEL_ID | Name | Class | TARGET_LOCATION |
| --- | ---: | --- | --- |
| 200 | RAED FAWAZ | BUSINESS_TRUTH_RESOLVED | 5 |
| 201 | SAIF TAREQ JASIM AL-GBURI | BUSINESS_TRUTH_RESOLVED | 5 |
| 203 | Muhammed Mahmud (name correction also required) | BUSINESS_TRUTH_RESOLVED | 5 |
| 204 | Abdullah Fetiyan | BUSINESS_TRUTH_RESOLVED | 5 |
| 205 | ALADDİN DEREBAŞI | BUSINESS_TRUTH_RESOLVED | 5 |
| 206 | ABDULLAH | BUSINESS_TRUTH_RESOLVED | 5 |
| 209 | MUQTADA MAZIN KHALEE | BUSINESS_TRUTH_RESOLVED | 5 |
| 210 | FAHRİ TAYLAN MERCAN | BUSINESS_TRUTH_RESOLVED | 5 |
| 212 | İlker AKEL | BUSINESS_TRUTH_RESOLVED | 5 |
| 217 | Görkem Vural | BUSINESS_TRUTH_RESOLVED | 5 |
| 160 | Sedanur Bulut | CLOSED_DO_NOT_REOPEN | 5 (already applied) |
| 211 | Zeynep Günal | CLOSED_DO_NOT_REOPEN | 5 (already applied) |

Future live apply requires preimage guard per target:
`PERSONEL_ID / STATUS / COMPANY / BRANCH / SGK / CURRENT_LOCATION / TARGET_LOCATION=5`.
Drift → STOP.

## Personel 203 name correction (no-apply)

Canonical schema: `personeller.ad`, `personeller.soyad` (not `ad_soyad`).

| Field | Wrong/current known | Correct |
| --- | --- | --- |
| display | MUHAMMED IRAKLI | Muhammed Mahmud |
| `ad` | (live VERIFY) | `Muhammed` |
| `soyad` | (live VERIFY) | `Mahmud` |

Only canonical name fields may change. No production write in this phase.

## Branch manager — canonical product rule (GENERAL)

A branch manager assignment is an **authorization/responsibility** relationship.

It is **NOT**:

- personnel branch transfer
- physical location transfer
- company / SGK transfer
- department / bolum / birim / gorev / pozisyon transfer

Canonical separation:

```
PERSONNEL_HOME_BRANCH
  != PHYSICAL_WORK_LOCATION
  != MANAGED_BRANCH_ASSIGNMENTS
```

Rules:

1. A branch MAY have zero managers (not mandatory).
2. A person MAY manage a branch different from their home branch.
3. A person MAY physically work at Fabrika/Karabük and manage another branch.
4. The same person MAY manage multiple branches.
5. Assigning manager of branch X MUST NOT mutate:
   `personel.sube_id`, `personel.calisma_lokasyonu_id`, company, `sgk_isveren_id`,
   departman, bolum, birim, gorev, pozisyon.
6. MUST NOT invoke permanent branch transfer.
7. MUST NOT imply physical presence at the managed branch.
8. Not a Fabrika/Genel Merkez special-case — general product rule for all branches.

### Technical owner (semantics correction)

| Field | Value |
| --- | --- |
| USER_SUBELER_SEMANTIC | **ACCESS_SCOPE_ONLY** (visibility / A1 formal SGK scope — not manager responsibility) |
| BRANCH_MANAGER_ASSIGNMENT_OWNER | **dedicated** `sube_sorumlu_yoneticiler` via `OrganizasyonService.sorumlu_yonetici_user_ids` (PR **#278** / migration **088**) |
| MUST_NOT_ENCODE_MANAGERS_AS | `user_subeler` / forced `users.rol=SUBE_YONETICISI` |
| USER_OR_PERSON_LINK | optional `users.personel_id` (identity only; not authz) |
| MULTI_BRANCH_SUPPORTED | YES |
| ZERO_MANAGER_SUPPORTED | YES |
| MULTIPLE_MANAGERS_PER_BRANCH | YES |
| SAME_BRANCH_REQUIRED | NO |
| PERSONNEL_HOME_BRANCH_SIDE_EFFECT | NO |
| PHYSICAL_LOCATION_SIDE_EFFECT | NO |
| PRIMARY_ROLE_CHANGE_REQUIRED | NO |
| MULTI_ROLE_REQUIRED | NO |
| MANAGER_ASSIGNMENT_GRANTS_ACCESS | NO (access remains separate `user_subeler` / OrgScope) |
| BRANCH_MANAGER_TECHNICAL_STATUS | **TECHNICAL_GAP_LOCAL_FIXABLE** (prior ALREADY_SUPPORTED claim RETRACTED) |
| TECHNICAL_FIX_REQUIRED | YES → PR **#278** |
| TECHNICAL_FIX_IN_THIS_PR | NO (docs/business-truth lock only; tip pins remain production **087**) |

Generic branch access authorization remains `user_subeler` / OrgScope — independent of manager responsibility rows.
`PersonelKaliciSubeDegisikligiService` is a separate permanent branch-transfer owner and is not the manager-assignment path.
See also follow-on `docs/guncel/142-branch-manager-assignment-semantics.md` on PR #278.

## Medisa branch manager business map (no write)

| Branch | sube_id | Manager intent | Representation |
| --- | ---: | --- | --- |
| Fabrika/Karabük | 1 | Sinem Hamaloğlu | managed-branch assignment |
| Giresun | 2 | Halil Şenay | managed-branch assignment |
| Kayseri | 4 | Kübra [SURNAME UNKNOWN] | BUSINESS_IDENTITY_DECISION_REQUIRED |
| İzmir | 12 | Halil Şenay | managed-branch assignment |
| Ankara | 5 | temporary/central = Sinem Hamaloğlu | same canonical model if assigned |
| İstanbul | 6 | temporary/central = Sinem Hamaloğlu | same canonical model if assigned |
| Sakarya | 13 | temporary/central = Sinem Hamaloğlu | same canonical model if assigned |

Do not invent Kübra surname. Do not invent Sinem live account. Do not fake branch transfer.

## Local identity evidence class

| Person | Local keys (historical) | Live |
| --- | --- | --- |
| Sinem Hamaloğlu | user 110 / personel 173 / username `sinemH` (last closeout) | VERIFY_LIVE_REQUIRED |
| Halil Şenay | user 50 / username `040` / personel 112 / home Medisa(1) | VERIFY_LIVE_REQUIRED |
| Kübra (Kayseri) | no deterministic manager identity | BUSINESS_IDENTITY_DECISION_REQUIRED |

## Residuals after this lock

- Location targets above: BUSINESS_TRUTH_RESOLVED / NO_APPLY
- 160/211: CLOSED_DO_NOT_REOPEN
- 120/158/219: CROSS_COMPANY_SEMANTICALLY_VALID_DEFER
- Kayseri Kübra surname: unresolved
- Other PASIF/test/historical null locations: do not force loc5 unless explicitly covered

## Next gate

`HOSTING_RECOVERY_THEN_LIVE_PREIMAGE_VERIFY_AND_USER_APPROVED_APPLY`

## References

- `CURRENT_STATE.md`
- `ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json`
- `ops/organization-mapping/fabrika-karabuk-loc5-no-apply-preimage-plan.json`
- `ops/organization-mapping/personel-203-name-correction-no-apply.json`
- `ops/organization-mapping/branch-manager-assignment-no-apply-plan.json`
- `ops/organization-mapping/personnel-residual-decision-pack.json`
- `docs/guncel/122-org-hierarchy-authorization.md`
