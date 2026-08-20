export type PersonelFirstLoginFilter = "all" | "pending" | "completed";

export const PERSONEL_FIRST_LOGIN_PENDING_LABEL = "İlk Giriş Bekliyor";
export const PERSONEL_FIRST_LOGIN_COMPLETE_LABEL = "İlk Giriş Tamamlandı";

export type PersonelFirstLoginUserLike = {
  personel_id?: number | null;
  must_change_password?: boolean;
};

export function isPersonelBoundUser(item: PersonelFirstLoginUserLike): boolean {
  return item.personel_id != null && Number(item.personel_id) > 0;
}

/** PERSONEL-bound only; unbound legacy/admin accounts get no first-login label. */
export function resolvePersonelFirstLoginLabel(item: PersonelFirstLoginUserLike): string | null {
  if (!isPersonelBoundUser(item)) {
    return null;
  }

  if (item.must_change_password === true) {
    return PERSONEL_FIRST_LOGIN_PENDING_LABEL;
  }

  if (item.must_change_password === false) {
    return PERSONEL_FIRST_LOGIN_COMPLETE_LABEL;
  }

  return null;
}

export function matchesPersonelFirstLoginFilter(
  item: PersonelFirstLoginUserLike,
  filter: PersonelFirstLoginFilter
): boolean {
  if (filter === "all") {
    return true;
  }

  if (!isPersonelBoundUser(item)) {
    return false;
  }

  if (filter === "pending") {
    return item.must_change_password === true;
  }

  return item.must_change_password === false;
}

export function countPersonelFirstLoginStatus(items: PersonelFirstLoginUserLike[]): {
  pending: number;
  completed: number;
} {
  let pending = 0;
  let completed = 0;

  for (const item of items) {
    if (!isPersonelBoundUser(item)) {
      continue;
    }
    if (item.must_change_password === true) {
      pending += 1;
    } else if (item.must_change_password === false) {
      completed += 1;
    }
  }

  return { pending, completed };
}
