import type { KeyOption } from "../../../../types/referans";

export function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}
