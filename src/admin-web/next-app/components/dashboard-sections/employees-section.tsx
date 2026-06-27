import { Fragment, type FormEvent, useState } from "react";
import { fiscalYearStartValue } from "@/lib/date-defaults";
import type { Employee, EmployeeUpdatePayload } from "@/types";

type EmployeesSectionProps = {
  data: {
    employees: Employee[];
    activePanel: string;
  };
  form: {
    employeeImportResult: string;
  };
  actions: {
    onEmployeeImport: (formData: FormData) => Promise<void>;
    onTemplateDownload: (kind: "employees") => Promise<void>;
    onEmployeeUpdate: (id: number, payload: EmployeeUpdatePayload) => Promise<void>;
  };
  formatters: {
    formatEmploymentType: (value?: string | null) => string;
    formatEmployeeStatus: (value?: string | null) => string;
  };
};

const employmentTypeOptions = [
  { value: "FULL_TIME", label: "常勤" },
  { value: "PART_TIME", label: "非常勤" },
  { value: "CONTRACT", label: "契約" },
  { value: "TEMPORARY", label: "臨時" },
];

const statusOptions = [
  { value: "ACTIVE", label: "在職" },
  { value: "INACTIVE", label: "停止" },
  { value: "RETIRED", label: "退職" },
];

function toDateInputValue(value?: string | null) {
  return value ? value.slice(0, 10) : "";
}

function toEditPayload(employee: Employee): EmployeeUpdatePayload {
  return {
    employeeCode: employee.employeeCode,
    name: employee.name,
    kana: employee.kana ?? "",
    departmentName: employee.departmentName ?? "",
    locationName: employee.locationName ?? "",
    employmentType: employee.employmentType,
    status: employee.status,
    joinedOn: toDateInputValue(employee.joinedOn),
    retiredOn: toDateInputValue(employee.retiredOn),
    googleChatUserId: employee.googleChatUserId ?? "",
    loginEmail: employee.loginEmail ?? "",
  };
}

export function EmployeesSection(props: EmployeesSectionProps) {
  const activePanel = props.data.activePanel || "employees-list";
  const activeEmployees = props.data.employees.filter((employee) => employee.status === "ACTIVE").length;
  const retiredEmployees = props.data.employees.filter((employee) => employee.status === "RETIRED").length;
  const chatLinkedEmployees = props.data.employees.filter((employee) => Boolean(employee.googleChatUserId)).length;
  const [editingEmployeeId, setEditingEmployeeId] = useState<number | null>(null);
  const [editDraft, setEditDraft] = useState<EmployeeUpdatePayload | null>(null);
  const [editMessage, setEditMessage] = useState("");
  const [isSaving, setIsSaving] = useState(false);
  const [employeeImportPreview, setEmployeeImportPreview] = useState<{
    fileName: string;
    rowCount: number;
    hasLoginEmail: boolean;
    missingRequiredHeaders: string[];
  } | null>(null);

  function startEditing(employee: Employee) {
    setEditingEmployeeId(employee.id);
    setEditDraft(toEditPayload(employee));
    setEditMessage("");
  }

  function cancelEditing() {
    setEditingEmployeeId(null);
    setEditDraft(null);
    setEditMessage("");
  }

  function updateDraft<K extends keyof EmployeeUpdatePayload>(key: K, value: EmployeeUpdatePayload[K]) {
    setEditDraft((current) => (current ? { ...current, [key]: value } : current));
  }

  async function saveEditing(employeeId: number) {
    if (!editDraft) {
      return;
    }

    setIsSaving(true);
    setEditMessage("");
    try {
      await props.actions.onEmployeeUpdate(employeeId, {
        ...editDraft,
        kana: editDraft.kana?.trim() || null,
        departmentName: editDraft.departmentName?.trim() || null,
        locationName: editDraft.locationName?.trim() || null,
        retiredOn: editDraft.retiredOn || null,
        googleChatUserId: editDraft.googleChatUserId?.trim() || null,
        loginEmail: editDraft.loginEmail?.trim() || null,
      });
      setEditMessage("職員情報を更新しました。");
      setEditingEmployeeId(null);
      setEditDraft(null);
    } catch (error) {
      setEditMessage(error instanceof Error ? error.message : "職員情報の更新に失敗しました。");
    } finally {
      setIsSaving(false);
    }
  }

  async function previewEmployeeCsv(file?: File) {
    if (!file) {
      setEmployeeImportPreview(null);
      return;
    }

    const text = await file.text();
    const lines = text.split(/\r?\n/).filter((line) => line.trim() !== "");
    const headers = (lines[0] ?? "").replace(/^\uFEFF/, "").split(",").map((item) => item.trim());
    const missingRequiredHeaders = ["社員番号", "姓", "名"].filter((header) => !headers.includes(header));
    setEmployeeImportPreview({
      fileName: file.name,
      rowCount: Math.max(0, lines.length - 1),
      hasLoginEmail: headers.includes("メールアドレス"),
      missingRequiredHeaders,
    });
  }

  async function submitEmployeeImport(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!employeeImportPreview || employeeImportPreview.missingRequiredHeaders.length > 0) {
      return;
    }

    const shouldImport = window.confirm(
      `職員CSVを取り込みます。\nファイル: ${employeeImportPreview.fileName}\n想定件数: ${employeeImportPreview.rowCount} 件\nメールアドレス列: ${employeeImportPreview.hasLoginEmail ? "あり" : "なし"}`,
    );
    if (!shouldImport) {
      return;
    }

    await props.actions.onEmployeeImport(new FormData(event.currentTarget));
  }

  return (
    <section className="stack-section employee-section section-enter delay-3">
      {activePanel === "employees-list" ? (
      <section id="employees-list" className="panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>職員一覧</h3>
          </div>
          <span className="panel-meta">{props.data.employees.length} 件</span>
        </div>
        <div className="summary-strip employee-summary-strip">
          <div>
            <span className="detail-label">在職</span>
            <strong>{activeEmployees} 件</strong>
          </div>
          <div>
            <span className="detail-label">退職</span>
            <strong>{retiredEmployees} 件</strong>
          </div>
          <div>
            <span className="detail-label">Chat連携</span>
            <strong>{chatLinkedEmployees} 件</strong>
          </div>
        </div>
        {editMessage && editingEmployeeId === null ? <p className="feedback">{editMessage}</p> : null}
        <div className="table-wrap inline-edit-table employee-table-wrap">
          <table>
            <thead>
              <tr>
                <th>職員番号</th>
                <th>氏名</th>
                <th>雇用区分</th>
                <th>状態</th>
              </tr>
            </thead>
            <tbody>
              {props.data.employees.length === 0 ? (
                <tr>
                  <td colSpan={4} className="table-empty-cell">データがありません</td>
                </tr>
              ) : (
                props.data.employees.map((employee) => {
                  const isEditing = editingEmployeeId === employee.id && editDraft !== null;

                  return (
                    <Fragment key={employee.id}>
                      <tr className={isEditing ? "is-editing" : undefined}>
                        <td>
                          {isEditing ? (
                            <input
                              value={editDraft.employeeCode}
                              onChange={(event) => updateDraft("employeeCode", event.currentTarget.value)}
                            />
                          ) : (
                            employee.employeeCode
                          )}
                        </td>
                        <td>
                          {isEditing ? (
                            <input value={editDraft.name} onChange={(event) => updateDraft("name", event.currentTarget.value)} />
                          ) : (
                            <button type="button" className="text-link-button" onClick={() => startEditing(employee)}>
                              {employee.name}
                            </button>
                          )}
                        </td>
                        <td>
                          {isEditing ? (
                            <select
                              value={editDraft.employmentType}
                              onChange={(event) => updateDraft("employmentType", event.currentTarget.value)}
                            >
                              {employmentTypeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                  {option.label}
                                </option>
                              ))}
                            </select>
                          ) : (
                            props.formatters.formatEmploymentType(employee.employmentType)
                          )}
                        </td>
                        <td>
                          {isEditing ? (
                            <select value={editDraft.status} onChange={(event) => updateDraft("status", event.currentTarget.value)}>
                              {statusOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                  {option.label}
                                </option>
                              ))}
                            </select>
                          ) : (
                            props.formatters.formatEmployeeStatus(employee.status)
                          )}
                        </td>
                      </tr>
                      {isEditing ? (
                        <tr className="inline-edit-detail-row">
                          <td colSpan={4}>
                            <div className="inline-edit-detail-card">
                              <div className="form-grid">
                                <label>
                                  ふりがな
                                  <input value={editDraft.kana ?? ""} onChange={(event) => updateDraft("kana", event.currentTarget.value)} />
                                </label>
                                <label>
                                  所属
                                  <input
                                    value={editDraft.departmentName ?? ""}
                                    onChange={(event) => updateDraft("departmentName", event.currentTarget.value)}
                                  />
                                </label>
                                <label>
                                  勤務場所
                                  <input
                                    value={editDraft.locationName ?? ""}
                                    onChange={(event) => updateDraft("locationName", event.currentTarget.value)}
                                  />
                                </label>
                                <label>
                                  入職日
                                  <input
                                    type="date"
                                    value={editDraft.joinedOn}
                                    onChange={(event) => updateDraft("joinedOn", event.currentTarget.value)}
                                  />
                                </label>
                                <label>
                                  退職日
                                  <input
                                    type="date"
                                    value={editDraft.retiredOn ?? ""}
                                    onChange={(event) => updateDraft("retiredOn", event.currentTarget.value)}
                                  />
                                </label>
                                <label>
                                  メールアドレス
                                  <input
                                    type="email"
                                    value={editDraft.loginEmail ?? ""}
                                    onChange={(event) => updateDraft("loginEmail", event.currentTarget.value)}
                                    placeholder="staff@example.com"
                                  />
                                </label>
                                <label>
                                  Google Chat ID
                                  <input
                                    value={editDraft.googleChatUserId ?? ""}
                                    onChange={(event) => updateDraft("googleChatUserId", event.currentTarget.value)}
                                    placeholder="users/..."
                                  />
                                </label>
                              </div>
                              <div className="button-row inline-actions">
                                <button type="button" onClick={() => void saveEditing(employee.id)} disabled={isSaving}>
                                  {isSaving ? "保存中..." : "保存"}
                                </button>
                                <button type="button" className="secondary" onClick={cancelEditing} disabled={isSaving}>
                                  キャンセル
                                </button>
                              </div>
                              {editMessage ? <p className="feedback">{editMessage}</p> : null}
                            </div>
                          </td>
                        </tr>
                      ) : null}
                    </Fragment>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </section>
      ) : null}
      {activePanel === "employees-import" ? (
      <section id="employees-import" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>職員 CSV 取込</h3>
          </div>
          <button type="button" className="secondary table-action" onClick={() => void props.actions.onTemplateDownload("employees")}>
            雛形 CSV
          </button>
        </div>
        <form className="stack-form" onSubmit={(event) => void submitEmployeeImport(event)}>
          <div className="form-grid">
            <label>
              初期所属
              <input name="defaultDepartmentName" defaultValue="未設定" />
            </label>
            <label>
              雇用区分
              <select name="defaultEmploymentType" defaultValue="FULL_TIME">
                {employmentTypeOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              状態
              <select name="defaultStatus" defaultValue="ACTIVE">
                {statusOptions.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              入職日
              <input name="defaultJoinedOn" type="date" defaultValue={fiscalYearStartValue()} />
            </label>
          </div>
          <label>
            CSVファイル
            <input name="file" type="file" accept=".csv,text/csv" onChange={(event) => void previewEmployeeCsv(event.currentTarget.files?.[0])} />
          </label>
          <p className="compact-empty">
            未入力の所属・雇用区分・状態・入職日は、この初期値で補完されます。メールアドレス列がある場合は職員ログインに同期します。
          </p>
          {employeeImportPreview ? (
            <div className={employeeImportPreview.missingRequiredHeaders.length > 0 ? "import-preview import-preview-error" : "import-preview"}>
              <strong>取込プレビュー</strong>
              <span>ファイル: {employeeImportPreview.fileName}</span>
              <span>想定件数: {employeeImportPreview.rowCount} 件</span>
              <span>メールアドレス列: {employeeImportPreview.hasLoginEmail ? "あり" : "なし"}</span>
              {employeeImportPreview.missingRequiredHeaders.length > 0 ? (
                <span>不足ヘッダー: {employeeImportPreview.missingRequiredHeaders.join(" / ")}</span>
              ) : null}
            </div>
          ) : null}
          <button type="submit" disabled={!employeeImportPreview || employeeImportPreview.missingRequiredHeaders.length > 0}>
            プレビュー内容で取り込む
          </button>
        </form>
        {props.form.employeeImportResult ? <p className="feedback">{props.form.employeeImportResult}</p> : null}
      </section>
      ) : null}
    </section>
  );
}
