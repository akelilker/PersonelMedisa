# 140 — Canonical company / SGK user-scope rollout inventory

**Tür:** Read-only production rollout plan. **PRODUCTION MUTATION = 0** in this phase.

## Canonical axes

| Axis | Table / field | Semantics |
| --- | --- | --- |
| Branch | `user_subeler` | Explicit physical branches only |
| Company | `user_sirketler` | Live `subeler` of granted companies (current + future); no duplicate `user_subeler` required |
| SGK / payroll | `user_sgk_isverenler` | Filters on `personeller.sgk_isveren_id`; never grants physical branch access |
| Identity binding | `users.personel_id` | PERSONEL self-service identity only — not manager authz |

Owner: `OrgScope` (+ `HrWriteScope` for `IK_PERSONELI` write companies). Session: `AuthMiddleware` / `LoginController`.

## Production inventory (last read-only recon — grants still empty)

| Object | Expected state at code rollout |
| --- | --- |
| `user_sirketler` | count = 0 |
| `user_sgk_isverenler` | count = 0 |
| Active users / roles | unchanged; no role delete/merge |
| Karyapı / Şenay operational users | **UNKNOWN / DEFERRED** — do not invent |

Re-verify with SELECT-only inventory before any mutation gate. Do not trust this doc as live truth after later production writes.

## Planned user-scope changes (FUTURE mutation gate only)

| User | Target | Notes |
| --- | --- | --- |
| `ilkerA` | keep `GENEL_YONETICI` | Cleanup of unnecessary per-branch grants = separate gate |
| `serhan.kose` | Fabrika Müdürü → target `GENEL_YONETICI` | Separate production mutation gate |
| `sedanurB` | keep `IK_SORUMLUSU` | Explicit org/company/SGK grants as needed; **not** `GENEL_YONETICI` |
| demo `bolum_yoneticisi` / `birim_amiri` accounts | possible removal | Separate gate; **roles themselves stay** |

**Do not apply any of the above in this phase.**

## Explicit non-goals

- Location → authorization axis
- Production grant writes / migration apply / deploy
- Multi-role remodel or role rename
- Parallel scope resolver
