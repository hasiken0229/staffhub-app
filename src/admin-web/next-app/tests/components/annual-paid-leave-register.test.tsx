import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AnnualPaidLeaveRegister } from "@/components/dashboard-sections/leave/annual-paid-leave-register";
import { loadPaidLeaveRegister, loadRegisterEmployees } from "@/lib/api/paid-leave-register";
import { createRegisterCsv, daysText } from "@/lib/paid-leave-register";
import type { PaidLeaveRegister } from "@/types/paid-leave-register";

vi.mock("@/lib/api/paid-leave-register", () => ({
  loadPaidLeaveRegister: vi.fn(), loadRegisterEmployees: vi.fn(),
}));

const employees = [
  { id: 1, employeeCode: "E001", name: "架空 在職", status: "ACTIVE" },
  { id: 2, employeeCode: "E002", name: "架空 退職", status: "RETIRED" },
];
function record(id: number, from: string, to: string): PaidLeaveRegister {
  const e = employees[id - 1];
  return {
    employee: { id, code: e.employeeCode, name: e.name, status: e.status },
    from, to, openingBalanceAsOf: "2026-03-31", balanceAsOf: to,
    openingBalanceDays: null, closingBalanceDays: 0, openingHourlyBalance: null, closingHourlyBalance: { annualMinutes: 90, totalMinutes: null },
    fiscalLedger: {years: [{fiscalYear: 2026, openingDate: "2026-04-01", opening: {days: 19.5, annualMinutes: 720, totalMinutes: null},
      closingDate: to, closing: {days: 19, annualMinutes: 645, totalMinutes: null}, status: "AVAILABLE", message: "4月1日の調整後残を確認しました。", entries: [
      {date: from, kind: "USE", status: "RECORDED", usedDays: 0.5, usedMinutes: null, dayChange: -0.5, minutesChange: null, daysAfter: 19, annualMinutesAfter: 720, totalMinutesAfter: null},
      {date: to, kind: "USE", status: "RECORDED", usedDays: null, usedMinutes: 75, dayChange: null, minutesChange: -75, daysAfter: 19, annualMinutesAfter: 645, totalMinutesAfter: null},
    ]}]},
    warnings: [{ date: from, kind: "OVERLAPPING_LEDGER_DEDUCTIONS", message: "元帳の重複を確認してください。" }],
    baseDates: [{ date: null, recordedOn: from, kind: "MIGRATION_OPENING", days: 2, expiresOn: null, evidence: "旧台帳" }],
    entries: [{ date: from, kind: "USE", unit: "HALF_DAY", days: 0.5, minutes: null, deltaDays: -0.5, balanceAfterDays: 0,
      status: "RECORDED", evidence: " =1+1", sourceType: "旧台帳", sourceId: 3 }],
    review: { status: "REVIEW_REQUIRED", message: "残数を原簿と照合してください。", asOf: to, officialDays: 1, appDays: 0, sourceFile: "原票.csv", sourceRow: 8 },
    totals: { usedDays: 0.5, usedMinutes: 75, halfDayCount: 1 },
  };
}
beforeEach(() => { vi.resetAllMocks(); vi.mocked(loadRegisterEmployees).mockResolvedValue(employees); });
describe("年次有給休暇管理簿", () => {
  it("職員選択で自動表示し、遅れて届いた前の職員の管理簿を適用しない", async () => {
    let finish!: (result: PaidLeaveRegister) => void;
    vi.mocked(loadPaidLeaveRegister).mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }))
      .mockImplementation(async q => record(q.employeeId, q.from, q.to));
    render(<AnnualPaidLeaveRegister />);
    const select = screen.getByRole("combobox", {name: /対象職員/});
    await screen.findByRole("option", {name: /架空 退職（退職）/});
    expect(loadPaidLeaveRegister).not.toHaveBeenCalled();
    expect(screen.queryByRole("button", {name: "管理簿を表示"})).toBeNull();
    fireEvent.change(select, {target: {value: "1"}});
    await waitFor(() => expect(loadPaidLeaveRegister).toHaveBeenCalledTimes(1));
    const [first, signal] = vi.mocked(loadPaidLeaveRegister).mock.calls[0];
    fireEvent.change(select, {target: {value: "2"}});
    expect(signal.aborted).toBe(true);
    await screen.findByRole("article", {name: "架空 退職の年次有給休暇管理簿"});
    finish(record(1, first.from, first.to));
    await waitFor(() => expect(screen.queryByRole("article", {name: "架空 在職の年次有給休暇管理簿"})).not.toBeInTheDocument());
    expect(screen.getByRole("row", {name: /4月1日・調整後の残/})).toHaveTextContent("19.5日");
    expect(screen.getByRole("columnheader", {name: "日単位"})).toBeVisible();
    expect(screen.getByRole("columnheader", {name: "時間単位"})).toBeVisible();
    expect(screen.queryByRole("columnheader", {name: "区分"})).toBeNull();
    expect(screen.getByText("取得 1時間15分")).toBeVisible();
    expect(screen.queryByText(/原簿との照合|基準日・付与の記録|根拠・記録元/)).not.toBeInTheDocument();
    const article = screen.getByRole("article");
    const table = screen.getByRole("table", {name: /2026年度\s+取得・残数の明細/});
    expect(article.querySelector("header")?.nextElementSibling?.firstElementChild).toBe(table);
    expect(article.querySelector("dl")).toBeNull();
    expect(article).not.toHaveTextContent(/期間内に記帳された取得|年度開始の残|調整後残を確認しました|年度開始の残は4月1日時点/);
  });
  it("期間変更のたびに古い出力を外し、空欄・逆順では再取得しない", async () => {
    vi.mocked(loadPaidLeaveRegister).mockImplementation(async q => record(q.employeeId, q.from, q.to));
    render(<AnnualPaidLeaveRegister initialEmployeeId="1" />);
    await screen.findByRole("article");
    const last = screen.getByLabelText("終了日");
    fireEvent.change(last, {target: {value: ""}});
    expect(screen.queryByRole("article")).toBeNull();
    expect(screen.queryByRole("button", {name: "管理簿を印刷"})).toBeNull();
    expect(screen.queryByRole("button", {name: "管理簿CSVを保存"})).toBeNull();
    fireEvent.change(last, {target: {value: "2000-02-01"}});
    expect(screen.getByRole("alert")).toHaveTextContent("終了日は開始日以降");
    expect(loadPaidLeaveRegister).toHaveBeenCalledTimes(1);
    fireEvent.change(last, {target: {value: "2030-01-30"}});
    await screen.findByRole("article");
    expect(loadPaidLeaveRegister).toHaveBeenCalledTimes(2);
    expect(vi.mocked(loadPaidLeaveRegister).mock.lastCall?.[0].to).toBe("2030-01-30");
  });
  it("条件の異なる応答を拒否し、読込失敗の再試行で現在の条件を使う", async () => {
    vi.mocked(loadPaidLeaveRegister).mockImplementationOnce(async q => record(2, q.from, q.to))
      .mockRejectedValueOnce(new Error("通信エラー"))
      .mockImplementationOnce(async q => record(q.employeeId, q.from, q.to));
    render(<AnnualPaidLeaveRegister initialEmployeeId="1" />);
    expect(await screen.findByRole("alert")).toHaveTextContent("選択条件と一致しません");
    expect(screen.queryByRole("button", {name: "管理簿を印刷"})).toBeNull();
    fireEvent.click(screen.getByRole("button", {name: "再読込"}));
    expect(await screen.findByRole("alert")).toHaveTextContent("通信エラー");
    fireEvent.click(screen.getByRole("button", {name: "再読込"}));
    await screen.findByRole("article", {name: "架空 在職の年次有給休暇管理簿"});
  });
  it("CSVは4月1日残と日別・時間別の数量だけを出力し、0と未確認、数式風文字を区別する", () => {
    const r = record(1, "2026-06-01", "2026-11-24");
    r.employee.name = '\t@TEST("A","B")';
    const year = r.fiscalLedger.years[0];
    year.opening.annualMinutes = 0;
    year.entries.push({...year.entries[0], status: "REFERENCE", usedDays: 3});
    year.entries.push({...year.entries[1], status: "FUTURE", usedMinutes: 999});
    year.entries.push({...year.entries[0], kind: "OPENING", usedDays: null});
    year.entries[1].daysAfter = 8 - 1 / 6;
    const csv = createRegisterCsv(r);
    expect(csv.charCodeAt(0)).toBe(0xfeff);
    expect(csv).toContain(`"'\t@TEST(""A"",""B"")"`);
    expect(csv).toContain('"2026-04-01","4月1日・調整後の残","","","19.5","","","0","",""');
    expect(csv).toContain('"対象期間","2026-06-01","2026-11-24"\r\n\r\n"2026年度　取得・残数の明細"\r\n"日付","記帳区分"');
    expect(csv).not.toContain('"区分"');
    expect(csv).toContain('"2026-06-01","記帳済","0.5","-0.5","19","","","720",""');
    expect(csv).toContain(String(year.entries[1].daysAfter));
    expect(daysText(year.entries[1].daysAfter)).toBe("約7.833日");
    expect(csv).not.toMatch(/原簿との照合|基準日・付与の記録|根拠|記録元|原票.csv|旧台帳/);
    expect(csv).not.toMatch(/期間内に記帳された取得|参考の取得（集計外・件）|年度開始の残|記録の範囲|明細の範囲/);
    expect(csv).toContain('"参考・集計外","3"');
    expect(csv).toContain('"予定・集計外"');
    expect(csv.match(/"2026-04-01","4月1日・調整後の残"/g)).toHaveLength(1);
  });
  it("年をまたぐと両年度の4月1日残を出し、4月1日の時間休を日単位取得として再計上しない", async () => {
    vi.mocked(loadPaidLeaveRegister).mockImplementation(async q => {
      const result = record(q.employeeId, q.from, q.to);
      const first = result.fiscalLedger.years[0];
      first.entries = [];
      result.fiscalLedger.years.push({...first, fiscalYear: 2027, openingDate: "2027-04-01",
        opening: {days: 32.5, annualMinutes: 2280, totalMinutes: 4680}, entries: [{
          date: "2027-04-01", kind: "USE", status: "RECORDED", usedDays: 0, usedMinutes: 120, dayChange: -0.5,
          minutesChange: -120, daysAfter: 32.5, annualMinutesAfter: 2280, totalMinutesAfter: 4680, includedInOpening: true,
        }]});
      return result;
    });
    render(<AnnualPaidLeaveRegister />);
    await screen.findByRole("option", {name: /架空 在職/});
    fireEvent.change(screen.getByLabelText("開始日"), {target: {value: "2026-04-01"}});
    fireEvent.change(screen.getByLabelText("終了日"), {target: {value: "2027-04-30"}});
    fireEvent.change(screen.getByRole("combobox"), {target: {value: "1"}});
    await screen.findByRole("article");
    expect(screen.getAllByRole("row", {name: /4月1日・調整後の残/})).toHaveLength(2);
    expect(screen.getByRole("table", {name: /2026年度\s+取得・残数の明細/})).toBeVisible();
    expect(screen.getByRole("table", {name: /2027年度\s+取得・残数の明細/})).toBeVisible();
    expect(screen.getByRole("row", {name: /年度開始残に含む/})).toHaveTextContent("増減 -0.5日");
    expect(screen.getByText("取得 2時間")).toBeVisible();
    expect(screen.queryByText("取得 0.5日")).toBeNull();
  });
  it("4月1日未入職の残欄を画面・印刷元とCSVで入職前と示す", async () => {
    const data = record(1, "2026-04-01", "2026-11-24");
    Object.assign(data.fiscalLedger.years[0], {openingReason: "NOT_EMPLOYED",
      opening: {days: null, annualMinutes: null, totalMinutes: null}, message: "4月1日時点は入職前です。"});
    vi.mocked(loadPaidLeaveRegister).mockImplementation(async q => ({...data, from: q.from, to: q.to}));
    render(<AnnualPaidLeaveRegister initialEmployeeId="1" />);
    await screen.findByRole("article");
    const opening = screen.getByRole("row", {name: /4月1日・調整後の残/});
    expect(opening.textContent?.match(/入職前/g)).toHaveLength(3);
    expect(opening).not.toHaveTextContent("未確認");
    expect(createRegisterCsv(data)).toContain('"2026-04-01","4月1日・調整後の残","","","入職前","","","入職前","入職前",""');
  });
  it("日付の補足で参考・予定と期首反映を見分け、数量・残数を6列に揃える", async () => {
    vi.mocked(loadPaidLeaveRegister).mockImplementation(async q => {
      const report = record(q.employeeId, q.from, q.to);
      const entries = report.fiscalLedger.years[0].entries;
      entries[0] = {...entries[0], status: "REFERENCE", includedInOpening: true};
      entries[1] = {...entries[1], status: "FUTURE"};
      return report;
    });
    render(<AnnualPaidLeaveRegister initialEmployeeId="1" />);
    const article = await screen.findByRole("article");
    for (const row of article.querySelectorAll("tbody tr")) expect(row.children).toHaveLength(6);
    expect(screen.getByRole("rowheader", {name: /参考・集計外/})).toHaveTextContent("年度開始残に含む");
    expect(screen.getByRole("rowheader", {name: /予定・集計外/})).toContainElement(screen.getByText("予定・集計外"));
    const opening = screen.getByRole("row", {name: /4月1日・調整後の残/});
    expect(Array.from(opening.children).slice(1).map(cell => cell.textContent)).toEqual(["—", "19.5日", "—", "12時間", "未確認"]);
  });
  it("期間内の取得が無い日にも集計日残を出し、完全に未来の日付の残は表示しない", async () => {
    const result = record(1, "2026-11-23", "2026-11-24");
    result.fiscalLedger.years[0].entries = [];
    vi.mocked(loadPaidLeaveRegister).mockImplementation(async q => ({...result, from: q.from, to: q.to}));
    render(<AnnualPaidLeaveRegister initialEmployeeId="1" />);
    await screen.findByRole("article");
    const closing = screen.getByRole("row", {name: /集計日の残/});
    expect(closing).toHaveTextContent("2026-11-24");
    expect(closing).toHaveTextContent("19日");
    expect(closing).toHaveTextContent("10時間45分");
    expect(createRegisterCsv(result)).toContain('"2026-11-24","集計日の残","","","19","","","645","",""');
    result.fiscalLedger.years[0].closingDate = null;
    fireEvent.change(screen.getByLabelText("終了日"), {target: {value: "2040-01-15"}});
    fireEvent.change(screen.getByLabelText("開始日"), {target: {value: "2040-01-01"}});
    await screen.findByRole("article");
    expect(screen.queryByRole("row", {name: /集計日の残/})).toBeNull();
    expect(createRegisterCsv(result)).not.toContain('"集計日の残"');
  });
});
