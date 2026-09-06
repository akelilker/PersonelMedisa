# 140 — Canonical company / SGK user-scope rollout inventory

**Tür:** Canonical scope axes + Medisa production poststate pin.
**PRODUCTION MUTATION = 0** in this doc reconcile.

## Canonical axes

| Axis | Table / field | Semantics |
| --- | --- | --- |
| Branch | `user_subeler` | Explicit physical branches only |
| Company | `user_sirketler` | Live `subeler` of granted companies (current + future); no duplicate `user_subeler` required |
| SGK / payroll | `user_sgk_isverenler` | Filters on `personeller.sgk_isveren_id`; never grants physical branch access |
| Identity binding | `users.personel_id` | PERSONEL self-service identity only — not manager authz |

Owner: `OrgScope` (+ `HrWriteScope` for `IK_PERSONELI` write companies). Session: `AuthMiddleware` / `LoginController`.

## Production poststate (fresh inventory `34062358628` @ deploy `fcee671…`)

| Object | Live state |
| --- | --- |
| `user_sirketler` | count = **3** |
| `user_sgk_isverenler` | count = **3** |
| `user_subeler` | count = **31** |
| MUHASEBE role `user_subeler` assignments | **7** (matches Medisa target branch set size) |
| Karyapı / Şenay operational company grants | **DEFERRED** — sirket-total stays 3; do not invent |
| Medisa accounting visibility ACL (`sube_muhasebe_yetkilileri`) | schema **087 APPLIED**; empty table = restriction **disabled**; inventory does not publish ACL row_count |

PR #271 Medisa user-scope grants = **APPLIED** (authoritative close). Organization inventory publishes totals/role assignment counts only — not per-username payloads. Username-level re-GET requires Yönetim API auth and is out of inventory owner surface.

## Planned / deferred (not Medisa PR271 reopen)

| User | Target | Notes |
| --- | --- | --- |
| `ilkerA` | keep `GENEL_YONETICI` | Cleanup of unnecessary per-branch grants = separate gate |
| `serhan.kose` | Fabrika Müdürü → target `GENEL_YONETICI` | Separate production mutation gate |
| Karyapı / Şenay users | deferred | Do not invent grants |
| demo `bolum_yoneticisi` / `birim_amiri` accounts | possible removal | Separate gate; **roles themselves stay** |

## Explicit non-goals

- Location → authorization axis
- Re-applying Medisa grants without new contradictory live evidence
- Multi-role remodel or role rename
- Parallel scope resolver
