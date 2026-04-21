export function formatCurrency(value?: number | null) {
  if (value == null) {
    return "-";
  }

  return `${value.toLocaleString("ja-JP")} 円`;
}
