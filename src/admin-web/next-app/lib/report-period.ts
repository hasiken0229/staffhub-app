export function reportPeriod(month: string, employmentType?: string | null) {
  const [year, monthNumber] = month.split("-").map(Number);
  const isTwentyDay = employmentType === "PART_TIME" || employmentType === "扶養内";
  const from = isTwentyDay ? new Date(year, monthNumber - 2, 21) : new Date(year, monthNumber - 1, 1);
  const to = isTwentyDay ? new Date(year, monthNumber - 1, 20) : new Date(year, monthNumber, 0);
  return { from, to };
}
