import { type FormEvent, useState } from "react";
import { HarmosHistoryPanel } from "./harmos-history-panel";
import { ConfirmDialog } from "@/components/confirm-dialog";
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
    onFileHistoryDownload: (id: number, fileName?: string) => Promise<unknown>;
  };
};

const importTypeOptions: Array<{ value: HarmosMigrationImportType; label: string; note: string }> = [
  { value: "HARMOS_EMPLOYEE_CSV", label: "職員マスタ（拠点・雇用形態同期）", note: "職員番号と氏名を基準に新規登録・更新し、部門・拠点・雇用形態の設定マスタも同期します。" },
  { value: "HARMOS_WORK_TYPE_CSV", label: "勤務区分", note: "HARMOSの勤務区分CSVから、勤務区分名・始終業時刻・休憩時間・所定時間を同期します。休日・休暇の区分は取り込みません。" },
  { value: "HARMOS_ATTENDANCE_DAILY_CSV", label: "日次勤怠", note: "過去勤怠を日次勤怠へHARMOS移行データとして登録します。" },
  { value: "HARMOS_ATTENDANCE_MONTHLY_CSV", label: "月次勤怠", note: "月次CSVを参照ファイルとして履歴保存します。" },
  { value: "HARMOS_PAID_LEAVE_BALANCE_CSV", label: "有給残数", note: "移行基準日時点の残数を移行付与として登録します。" },
];

const importTypeLabels = Object.fromEntries(importTypeOptions.map((option) => [option.value, option.label])) as Record<HarmosMigrationImportType, string>;

function formatImportType(importType: string) {
  if (importType === "HARMOS_SOURCE_ARCHIVE") return "HRMOS移行原本";
  return importTypeLabels[importType as HarmosMigrationImportType] ?? "その他の取込";
}

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
  const [isImportConfirmOpen, setIsImportConfirmOpen] = useState(false);

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

  function handleImportRequest() {
    if (!selectedFile || !previewResult) {
      setMessage("先にCSVをプレビューしてください。");
      return;
    }

    setIsImportConfirmOpen(true);
  }

  async function handleImport() {
    if (!selectedFile || !previewResult) {
      setMessage("先にCSVをプレビューしてください。");
      return;
    }

    setIsWorking(true);
    setMessage("");
    try {
      const formData = buildFormData(selectedFile);
      if (previewResult.previewFingerprint) formData.set("previewFingerprint", previewResult.previewFingerprint);
      const result = await importHarmosMigration(formData);
      setImportResult(result);
      setPreviewResult(null);
      setMessage(`取込完了: ${result.successCount}件を処理しました。エラー${result.errorCount}件。`);
      try { await props.actions.onRefresh(); } catch { setMessage(`取込は完了しています（${result.successCount}件）。履歴の更新に失敗しました。再取込せず、再読込してください。`); }
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
                    setIsImportConfirmOpen(false);
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
                    setIsImportConfirmOpen(false);
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
            <div className="operation-guide" role="note">
              <strong>取込前の確認</strong>
              <span>
                まずCSVをプレビューし、取込可能件数とエラー内容を確認してください。確定取込はプレビュー完了後に実行できます。
              </span>
            </div>
            <div className="button-row">
              <button
                type="submit"
                disabled={isWorking || !selectedFile}
                title={!selectedFile ? "CSVファイルを選択するとプレビューできます" : undefined}
              >
                {isWorking ? "確認中..." : "CSVをプレビュー"}
              </button>
              <button
                type="button"
                className="secondary confirm-action"
                disabled={isWorking || !previewResult}
                title={!previewResult ? "CSVプレビュー完了後に確定取込できます" : "プレビュー結果を確定取込します"}
                onClick={handleImportRequest}
              >
                確定取込
              </button>
            </div>
          </form>

          {message ? <p className="feedback">{message}</p> : null}
          {previewResult ? <MigrationResultPanel result={previewResult} committed={Boolean(importResult)} /> : null}
          <ConfirmDialog
            open={isImportConfirmOpen}
            title="HARMOS CSVを確定取込しますか？"
            message={`${importTypeLabels[importType]}: 取込可能 ${previewResult?.successCount ?? 0}件、エラー ${previewResult?.errorCount ?? 0}件。エラー行は取り込まれません。`}
            confirmLabel="確定取込を実行"
            onCancel={() => setIsImportConfirmOpen(false)}
            onConfirm={() => {
              setIsImportConfirmOpen(false);
              void handleImport();
            }}
          />
        </section>
      ) : null}

      {activePanel === "harmos-history" && (
        <HarmosHistoryPanel recent={harmosHistory} formatType={formatImportType} onDownload={props.actions.onFileHistoryDownload} />
      )}
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
