import { type FormEvent, useState } from "react";
import { currentDateValue, fiscalYearStartValue } from "@/lib/date-defaults";
import { importHarmosMigration, previewHarmosMigration } from "@/lib/api";
import type { HarmosMigrationImportType, HarmosMigrationResult, ImportHistory } from "@/types";

type HarmosMigrationSectionProps = {
  data: {
    activePanel: string;
    importHistory: ImportHistory[];
  };
  actions: {
    onRefresh: () => void;
  };
};

const importTypeOptions: Array<{ value: HarmosMigrationImportType; label: string; note: string }> = [
  { value: "HARMOS_EMPLOYEE_CSV", label: "職員マスタ", note: "職員番号と氏名を基準に新規登録・更新します。" },
  { value: "HARMOS_ATTENDANCE_DAILY_CSV", label: "日次勤怠", note: "過去勤怠を日次勤怠へHARMOS移行データとして登録します。" },
  { value: "HARMOS_ATTENDANCE_MONTHLY_CSV", label: "月次勤怠", note: "月次CSVを参照ファイルとして履歴保存します。" },
  { value: "HARMOS_PAID_LEAVE_BALANCE_CSV", label: "有給残数", note: "移行基準日時点の残数を移行付与として登録します。" },
];

const importTypeLabels = Object.fromEntries(importTypeOptions.map((option) => [option.value, option.label])) as Record<HarmosMigrationImportType, string>;

export function HarmosMigrationSection(props: HarmosMigrationSectionProps) {
  const activePanel = props.data.activePanel || "harmos-upload";
  const [importType, setImportType] = useState<HarmosMigrationImportType>("HARMOS_EMPLOYEE_CSV");
  const [targetPeriod, setTargetPeriod] = useState("");
  const [migrationDate, setMigrationDate] = useState(currentDateValue());
  const [defaultDepartmentName, setDefaultDepartmentName] = useState("未設定");
  const [defaultEmploymentType, setDefaultEmploymentType] = useState("FULL_TIME");
  const [defaultStatus, setDefaultStatus] = useState("ACTIVE");
  const [defaultJoinedOn, setDefaultJoinedOn] = useState(fiscalYearStartValue());
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [previewResult, setPreviewResult] = useState<HarmosMigrationResult | null>(null);
  const [importResult, setImportResult] = useState<HarmosMigrationResult | null>(null);
  const [message, setMessage] = useState("");
  const [isWorking, setIsWorking] = useState(false);

  function buildFormData(file: File) {
    const formData = new FormData();
    formData.set("importType", importType);
    formData.set("file", file);
    if (targetPeriod) {
      formData.set("targetPeriod", targetPeriod);
    }
    if (migrationDate) {
      formData.set("migrationDate", migrationDate);
    }
    formData.set("defaultDepartmentName", defaultDepartmentName);
    formData.set("defaultEmploymentType", defaultEmploymentType);
    formData.set("defaultStatus", defaultStatus);
    formData.set("defaultJoinedOn", defaultJoinedOn);
    return formData;
  }

  async function handlePreview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedFile) {
      setMessage("CSVファイルを選択してください。");
      return;
    }

    setIsWorking(true);
    setMessage("");
    setImportResult(null);
    try {
      const result = await previewHarmosMigration(buildFormData(selectedFile));
      setPreviewResult(result);
      setMessage(`プレビュー完了: ${result.successCount}件取込可能、エラー${result.errorCount}件。`);
    } catch (error) {
      setPreviewResult(null);
      setMessage(error instanceof Error ? error.message : "HARMOS移行プレビューに失敗しました。");
    } finally {
      setIsWorking(false);
    }
  }

  async function handleImport() {
    if (!selectedFile || !previewResult) {
      setMessage("先にCSVをプレビューしてください。");
      return;
    }
    if (!window.confirm(`${importTypeLabels[importType]}を確定取込します。\n取込可能: ${previewResult.successCount}件\nエラー: ${previewResult.errorCount}件`)) {
      return;
    }

    setIsWorking(true);
    setMessage("");
    try {
      const result = await importHarmosMigration(buildFormData(selectedFile));
      setImportResult(result);
      setPreviewResult(result);
      setMessage(`取込完了: ${result.successCount}件を処理しました。エラー${result.errorCount}件。`);
      props.actions.onRefresh();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "HARMOS移行の確定取込に失敗しました。");
    } finally {
      setIsWorking(false);
    }
  }

  const selectedOption = importTypeOptions.find((option) => option.value === importType) ?? importTypeOptions[0];
  const harmosHistory = props.data.importHistory.filter((item) => item.importType.startsWith("HARMOS_"));

  return (
    <section className="stack-section harmos-migration-section section-enter delay-3">
      {activePanel === "harmos-upload" ? (
        <section id="harmos-upload" className="panel anchor-panel">
          <div className="panel-header">
            <div>
              <h3>HARMOS CSV移行</h3>
            </div>
            <span className="panel-meta">{selectedOption.label}</span>
          </div>

          <form className="stack-form" onSubmit={(event) => void handlePreview(event)}>
            <div className="form-grid">
              <label>
                移行種別
                <select
                  value={importType}
                  onChange={(event) => {
                    setImportType(event.currentTarget.value as HarmosMigrationImportType);
                    setPreviewResult(null);
                    setImportResult(null);
                  }}
                >
                  {importTypeOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                対象月
                <input type="month" value={targetPeriod} onChange={(event) => setTargetPeriod(event.currentTarget.value)} />
              </label>
              <label>
                移行基準日
                <input type="date" value={migrationDate} onChange={(event) => setMigrationDate(event.currentTarget.value)} />
              </label>
              <label>
                CSVファイル
                <input
                  type="file"
                  accept=".csv,text/csv"
                  onChange={(event) => {
                    setSelectedFile(event.currentTarget.files?.[0] ?? null);
                    setPreviewResult(null);
                    setImportResult(null);
                  }}
                />
              </label>
            </div>

            {importType === "HARMOS_EMPLOYEE_CSV" ? (
              <div className="form-grid">
                <label>
                  初期所属
                  <input value={defaultDepartmentName} onChange={(event) => setDefaultDepartmentName(event.currentTarget.value)} />
                </label>
                <label>
                  初期雇用区分
                  <select value={defaultEmploymentType} onChange={(event) => setDefaultEmploymentType(event.currentTarget.value)}>
                    <option value="FULL_TIME">常勤</option>
                    <option value="PART_TIME">非常勤</option>
                    <option value="CONTRACT">契約</option>
                    <option value="TEMPORARY">臨時</option>
                  </select>
                </label>
                <label>
                  初期状態
                  <select value={defaultStatus} onChange={(event) => setDefaultStatus(event.currentTarget.value)}>
                    <option value="ACTIVE">在職</option>
                    <option value="INACTIVE">停止</option>
                    <option value="RETIRED">退職</option>
                  </select>
                </label>
                <label>
                  初期入職日
                  <input type="date" value={defaultJoinedOn} onChange={(event) => setDefaultJoinedOn(event.currentTarget.value)} />
                </label>
              </div>
            ) : null}

            <p className="compact-empty">{selectedOption.note}</p>
            <div className="button-row">
              <button type="submit" disabled={isWorking || !selectedFile}>
                {isWorking ? "確認中..." : "CSVをプレビュー"}
              </button>
              <button type="button" className="secondary" disabled={isWorking || !previewResult} onClick={() => void handleImport()}>
                確定取込
              </button>
            </div>
          </form>

          {message ? <p className="feedback">{message}</p> : null}
          {previewResult ? <MigrationResultPanel result={previewResult} committed={Boolean(importResult)} /> : null}
        </section>
      ) : null}

      {activePanel === "harmos-history" ? (
        <section id="harmos-history" className="panel anchor-panel">
          <div className="panel-header">
            <div>
              <h3>HARMOS取込履歴</h3>
            </div>
            <span className="panel-meta">{harmosHistory.length} 件</span>
          </div>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>日時</th>
                  <th>種別</th>
                  <th>ファイル</th>
                  <th>成功</th>
                  <th>エラー</th>
                </tr>
              </thead>
              <tbody>
                {harmosHistory.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="table-empty-cell">HARMOS取込履歴はまだありません</td>
                  </tr>
                ) : (
                  harmosHistory.map((item) => (
                    <tr key={item.id}>
                      <td>{item.createdAt?.slice(0, 16).replace("T", " ")}</td>
                      <td>{item.importType}</td>
                      <td>{item.downloadFileName ?? item.sourceFileName}</td>
                      <td>{item.successCount}</td>
                      <td>{item.errorCount}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}
    </section>
  );
}

function MigrationResultPanel({ result, committed }: { result: HarmosMigrationResult; committed: boolean }) {
  return (
    <div className="import-preview">
      <strong>{committed ? "取込結果" : "プレビュー結果"}</strong>
      <div className="summary-strip">
        <div>
          <span className="detail-label">処理</span>
          <strong>{result.processedCount}</strong>
        </div>
        <div>
          <span className="detail-label">成功</span>
          <strong>{result.successCount}</strong>
        </div>
        <div>
          <span className="detail-label">エラー</span>
          <strong>{result.errorCount}</strong>
        </div>
        <div>
          <span className="detail-label">職員一致</span>
          <strong>{result.summary.matchedEmployeeCount}</strong>
        </div>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>行</th>
              <th>処理</th>
              <th>職員番号</th>
              <th>職員</th>
              <th>対象</th>
              <th>内容</th>
            </tr>
          </thead>
          <tbody>
            {result.items.map((item) => (
              <tr key={`${item.line}-${item.employeeCode ?? ""}-${item.targetDate ?? ""}`}>
                <td>{item.line}</td>
                <td>{item.action}</td>
                <td>{item.employeeCode ?? "-"}</td>
                <td>{item.employeeName ?? "-"}</td>
                <td>{item.targetDate ?? "-"}</td>
                <td>{item.detail ?? "-"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {result.errors.length > 0 ? (
        <div className="import-preview import-preview-error">
          <strong>エラー行</strong>
          {result.errors.map((error) => (
            <span key={`${error.line}-${error.message}`}>
              {error.line}行目 {error.employeeCode ? `(${error.employeeCode}) ` : ""}
              {error.message}
            </span>
          ))}
        </div>
      ) : null}
    </div>
  );
}
