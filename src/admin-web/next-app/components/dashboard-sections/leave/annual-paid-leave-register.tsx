import { useEffect, useState } from "react";
import { loadPaidLeaveRegister, loadRegisterEmployees, type RegisterEmployee, type RegisterQuery } from "@/lib/api/paid-leave-register";
import { saveDownloadBlob } from "@/lib/api/core";
import { formatEmployeeStatus } from "@/lib/api/formatters";
import { createRegisterCsv, daysText, detailEntries, entryStatus, fiscalEntryKind, fiscalOpeningText, fiscalTableTitle, minutesText, openingLabel, registerTitle } from "@/lib/paid-leave-register";
import type { FiscalLeaveYear, PaidLeaveRegister } from "@/types/paid-leave-register";
import "./annual-paid-leave-register.css";

export function AnnualPaidLeaveRegister({ initialEmployeeId = "" }: { initialEmployeeId?: string }) {
  const [employees, setEmployees] = useState<RegisterEmployee[] | null>(null);
  const [directoryError, setDirectoryError] = useState("");
  const [attempt, setAttempt] = useState(0);
  const [employeeId, setEmployeeId] = useState(initialEmployeeId);
  const [today] = useState(() => new Date().toLocaleDateString("sv-SE", { timeZone: "Asia/Tokyo" }));
  const [from, setFrom] = useState(() => `${Number(today.slice(0, 4)) - (today.slice(5, 7) < "04" ? 1 : 0)}-04-01`);
  const [to, setTo] = useState(today);

  useEffect(() => {
    const controller = new AbortController();
    loadRegisterEmployees(controller.signal).then(rows => {
      if (!controller.signal.aborted) setEmployees(rows);
    }).catch((error: unknown) => {
      if (!controller.signal.aborted) setDirectoryError(error instanceof Error ? error.message : "職員一覧を取得できませんでした。");
    });
    return () => controller.abort();
  }, [attempt]);

  const inverted = Boolean(from && to && from > to);
  const selectedEmployee = employees?.find(person => String(person.id) === employeeId);
  const validPeriod = /^\d{4}-\d{2}-\d{2}$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(to) && !inverted;
  return <section id="leave-register" className="panel action-panel anchor-panel annual-register">
    <div className="panel-header"><h3>{registerTitle}</h3></div>
    <div className="annual-register-controls">
      <label>対象職員（退職者を含む）
        <select required value={employeeId} disabled={!employees} onChange={event => setEmployeeId(event.target.value)}>
          <option value="">{!employees && !directoryError ? "職員を読み込み中…" : "職員を選択"}</option>
          {employees?.map(person => <option key={person.id} value={person.id}>{person.employeeCode} ／ {person.name}{person.status === "ACTIVE" ? "" : `（${formatEmployeeStatus(person.status)}）`}</option>)}
        </select>
      </label>
      <label>開始日<input type="date" required value={from} onChange={event => setFrom(event.target.value)} /></label>
      <label>終了日<input type="date" required value={to} min={from || undefined} onChange={event => setTo(event.target.value)} /></label>
    </div>
    {inverted && <p role="alert" className="feedback">終了日は開始日以降を指定してください。</p>}
    {directoryError && <div role="alert" className="feedback">{directoryError} <button type="button" className="secondary" onClick={() => { setDirectoryError(""); setAttempt(value => value + 1); }}>職員一覧を再読込</button></div>}
    {selectedEmployee && validPeriod && <RegisterResult key={`${selectedEmployee.id}/${from}/${to}`} employeeId={selectedEmployee.id} from={from} to={to} />}
  </section>;
}

function RegisterResult({ employeeId, from, to }: RegisterQuery) {
  const [report, setReport] = useState<PaidLeaveRegister | null>(null);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    loadPaidLeaveRegister({ employeeId, from, to }, controller.signal).then(data => {
      if (controller.signal.aborted) return;
      if (data.employee.id !== employeeId || data.from !== from || data.to !== to) throw new Error("取得した管理簿の職員または期間が選択条件と一致しません。");
      if (!data.fiscalLedger?.years?.length) throw new Error("年度別の残数を取得できませんでした。再読込してください。");
      setReport(data);
    }).catch((failure: unknown) => {
      if (!controller.signal.aborted) setError(failure instanceof Error ? failure.message : "管理簿を取得できませんでした。");
    });
    return () => controller.abort();
  }, [employeeId, from, to, retry]);
  if (error) return <div className="feedback" role="alert">{error} <button type="button" className="secondary" onClick={() => { setError(""); setRetry(value => value + 1); }}>再読込</button></div>;
  if (!report) return <p role="status" className="muted">管理簿を読み込み中…</p>;

  const { employee, fiscalLedger } = report;
  return <>
    <div className="button-row annual-register-actions">
      <button className="secondary" type="button" onClick={() => window.print()}>管理簿を印刷</button>
      <button className="secondary" type="button" onClick={() => saveDownloadBlob(new Blob([createRegisterCsv(report)], { type: "text/csv;charset=utf-8" }), `年次有給休暇管理簿_${employee.code.replace(/[^\p{L}\p{N}_-]/gu, "_")}_${report.from}_${report.to}.csv`)}>管理簿CSVを保存</button>
    </div>
    <article className="annual-register-sheet" aria-label={`${employee.name}の年次有給休暇管理簿`}>
      <header className="annual-register-heading"><h2>{registerTitle}</h2><h3>{employee.name} <small>職員番号 {employee.code} ／ {formatEmployeeStatus(employee.status)}</small></h3><p>対象期間　{report.from} ～ {report.to}</p></header>
      {fiscalLedger.years.map(year => <FiscalYearTable key={year.fiscalYear} year={year} />)}
    </article>
  </>;
}

function QuantityChange({ used, change, format }: { used: number | null; change: number | null; format: (value: number) => string }) {
  const hasUse = used !== null && used !== 0;
  const hasChange = change !== null && change !== 0 && (!hasUse || Math.abs(change + used) > 0.000001);
  if (!hasUse && !hasChange) return <>—</>;
  return <>
    {hasUse && <span>取得 {format(used)}</span>}
    {hasChange && <small>増減 {change > 0 ? "+" : ""}{format(change)}</small>}
  </>;
}

function FiscalYearTable({ year }: { year: FiscalLeaveYear }) {
  const rows = detailEntries(year);
  const days = fiscalOpeningText(year, "days");
  const annual = fiscalOpeningText(year, "annualMinutes");
  const total = fiscalOpeningText(year, "totalMinutes");
  return <div className="annual-register-table" tabIndex={0} role="region" aria-label={`${year.fiscalYear}年度の明細表`}>
      <table><caption>{fiscalTableTitle(year.fiscalYear)}</caption><colgroup span={1} /><colgroup span={2} /><colgroup span={3} /><thead>
        <tr><th scope="col" rowSpan={2}>日付</th><th scope="colgroup" colSpan={2}>日単位</th><th scope="colgroup" colSpan={3}>時間単位</th></tr>
        <tr><th scope="col">取得・増減</th><th scope="col">日数残</th><th scope="col">取得・増減</th><th scope="col">年度枠残</th><th scope="col">総残</th></tr>
      </thead><tbody>
        <tr className="annual-register-opening"><th scope="row"><time dateTime={year.openingDate}>{year.openingDate}</time><small>{openingLabel}</small></th><td>—</td><td>{days}</td><td>—</td><td>{annual}</td><td>{total}</td></tr>
        {rows.map((row, index) => <tr key={`${row.date}-${index}`}>
          <th scope="row"><time dateTime={row.date}>{row.date}</time>
            {row.kind !== "USE" && <small>{fiscalEntryKind[row.kind]}</small>}
            {row.status !== "RECORDED" && <small>{entryStatus[row.status]}</small>}
            {row.includedInOpening && <small>年度開始残に含む</small>}
          </th>
          <td><QuantityChange used={row.usedDays} change={row.dayChange} format={daysText} /></td><td>{daysText(row.daysAfter)}</td>
          <td><QuantityChange used={row.usedMinutes} change={row.minutesChange} format={minutesText} /></td><td>{minutesText(row.annualMinutesAfter)}</td><td>{minutesText(row.totalMinutesAfter)}</td>
        </tr>)}
        {year.closingDate && year.closing && <tr className="annual-register-closing"><th scope="row"><time dateTime={year.closingDate}>{year.closingDate}</time><small>集計日の残</small></th><td>—</td><td>{daysText(year.closing.days)}</td><td>—</td><td>{minutesText(year.closing.annualMinutes)}</td><td>{minutesText(year.closing.totalMinutes)}</td></tr>}
      </tbody></table>
    </div>;
}

