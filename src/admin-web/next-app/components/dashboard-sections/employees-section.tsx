import { Fragment, type FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { fiscalYearStartValue } from "@/lib/date-defaults";
import { previewEmployeesCsv } from "@/lib/api/employees";
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
    onUnsavedEditChange: (hasUnsavedEdit: boolean) => void;
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
  const [employeeSearch, setEmployeeSearch] = useState("");
  const [employeeDepartmentFilter, setEmployeeDepartmentFilter] = useState("");
  const [employeeEmploymentFilter, setEmployeeEmploymentFilter] = useState("");
  const [employeeStatusFilter, setEmployeeStatusFilter] = useState("ACTIVE");
  const [editingEmployeeId, setEditingEmployeeId] = useState<number | null>(null);
  const [editDraft, setEditDraft] = useState<EmployeeUpdatePayload | null>(null);
  const [editOriginal, setEditOriginal] = useState<EmployeeUpdatePayload | null>(null);
  const [editMessage, setEditMessage] = useState("");
  const [isSaving, setIsSaving] = useState(false);
  const [employeeImportPreview, setEmployeeImportPreview] = useState<{
    previewFingerprint?: string;
    fileName: string;
    rowCount: number;
    hasLoginEmail: boolean;
    createdCount: number;
    updatedCount: number;
    skippedCount: number;
    errors: Array<{ line: number; employeeCode?: string | null; message: string }>;
  } | null>(null);
  const [isPreviewingEmployeeImport, setIsPreviewingEmployeeImport] = useState(false);
  const employeePreviewRequest = useRef(0);
  const employeePreviewFormData = useRef<FormData | null>(null);
  const [pendingEmployeeAction, setPendingEmployeeAction] = useState<
    { type: "discard"; nextEmployee: Employee | null } | { type: "import"; formData: FormData }
  | null>(null);

  const hasUnsavedEdit = useMemo(
    () => editDraft !== null && editOriginal !== null && JSON.stringify(editDraft) !== JSON.stringify(editOriginal),
    [editDraft, editOriginal],
  );
  const departments = useMemo(
    () => Array.from(new Set(props.data.employees.map((employee) => employee.departmentName?.trim()).filter((value): value is string => Boolean(value))))
      .sort((left, right) => left.localeCompare(right, "ja")),
    [props.data.employees],
  );
  const visibleEmployees = useMemo(() => {
    const query = employeeSearch.trim().toLocaleLowerCase("ja");
    return props.data.employees.filter((employee) => {
      const matchesSearch = !query || [employee.employeeCode, employee.name, employee.kana ?? ""]
        .some((value) => value.toLocaleLowerCase("ja").includes(query));
      const matchesDepartment = !employeeDepartmentFilter || employee.departmentName === employeeDepartmentFilter;
      const matchesEmployment = !employeeEmploymentFilter || employee.employmentType === employeeEmploymentFilter;
      const matchesStatus = !employeeStatusFilter || employee.status === employeeStatusFilter;
      return (matchesSearch && matchesDepartment && matchesEmployment && matchesStatus) || employee.id === editingEmployeeId;
    });
  }, [props.data.employees, employeeSearch, employeeDepartmentFilter, employeeEmploymentFilter, employeeStatusFilter, editingEmployeeId]);

  useEffect(() => {
    props.actions.onUnsavedEditChange(hasUnsavedEdit);

    return () => props.actions.onUnsavedEditChange(false);
  }, [hasUnsavedEdit, props.actions]);

  useEffect(() => {
    if (!hasUnsavedEdit) {
      return;
    }

    const warnBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warnBeforeUnload);
    return () => window.removeEventListener("beforeunload", warnBeforeUnload);
  }, [hasUnsavedEdit]);

  function startEditing(employee: Employee) {
    if (editingEmployeeId !== null && editingEmployeeId !== employee.id && hasUnsavedEdit) {
      setPendingEmployeeAction({ type: "discard", nextEmployee: employee });
      return;
    }

    applyStartEditing(employee);
  }

  function applyStartEditing(employee: Employee) {
    const initialDraft = toEditPayload(employee);
    setEditingEmployeeId(employee.id);
    setEditDraft(initialDraft);
    setEditOriginal(initialDraft);
    setEditMessage("");
  }

  function cancelEditing() {
    if (hasUnsavedEdit) {
      setPendingEmployeeAction({ type: "discard", nextEmployee: null });
      return;
    }
    clearEditing();
  }

  function clearEditing() {
    setEditingEmployeeId(null);
    setEditDraft(null);
    setEditOriginal(null);
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
      setEditOriginal(null);
    } catch (error) {
      setEditMessage(error instanceof Error ? error.message : "職員情報の更新に失敗しました。");
    } finally {
      setIsSaving(false);
    }
  }

  async function previewEmployeeCsv(input: HTMLInputElement) {
    const requestId = ++employeePreviewRequest.current;
    employeePreviewFormData.current = null;
    setEmployeeImportPreview(null);
    setPendingEmployeeAction((current) => current?.type === "import" ? null : current);
    const file = input.files?.[0];
    if (!file || !input.form) {
      setIsPreviewingEmployeeImport(false);
      return;
    }

    const formData = new FormData(input.form);
    formData.set("file", file, file.name);
    const emptyPreview = {
      fileName: file.name,
      rowCount: 0,
      hasLoginEmail: false,
      createdCount: 0,
      updatedCount: 0,
      skippedCount: 0,
      errors: [] as Array<{ line: number; employeeCode?: string | null; message: string }>,
    };
    setIsPreviewingEmployeeImport(true);
    try {
      const preview = await previewEmployeesCsv(formData);
      if (requestId !== employeePreviewRequest.current) return;
      if (preview.previewFingerprint && !preview.errors?.length) {
        formData.set("previewFingerprint", preview.previewFingerprint);
        employeePreviewFormData.current = formData;
      }
      setEmployeeImportPreview({
        ...emptyPreview,
        rowCount: preview.rowCount ?? preview.processedCount,
        hasLoginEmail: preview.hasLoginEmail ?? false,
        previewFingerprint: preview.previewFingerprint,
        createdCount: preview.createdCount,
        updatedCount: preview.updatedCount,
        skippedCount: preview.skippedCount,
        errors: preview.errors ?? [],
      });
    } catch (error) {
      if (requestId !== employeePreviewRequest.current) return;
      setEmployeeImportPreview({
        ...emptyPreview,
        errors: [{ line: 0, message: error instanceof Error ? error.message : "CSVプレビューに失敗しました。" }],
      });
    } finally {
      if (requestId === employeePreviewRequest.current) setIsPreviewingEmployeeImport(false);
    }
  }

  function previewEmployeeImportForm(form: HTMLFormElement) {
    const input = form.elements.namedItem("file");
    if (input instanceof HTMLInputElement) void previewEmployeeCsv(input);
  }

  function submitEmployeeImport(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isPreviewingEmployeeImport || !employeeImportPreview?.previewFingerprint || employeeImportPreview.errors.length > 0 || !employeePreviewFormData.current) {
      return;
    }

    const formData = employeePreviewFormData.current;
    setPendingEmployeeAction({ type: "import", formData });
  }

  return (
    <section className="stack-section employee-section section-enter delay-3">
      {activePanel === "employees-list" ? (
      <section id="employees-list" className="panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>職員一覧</h3>
          </div>
          <span className="panel-meta">{visibleEmployees.length} / {props.data.employees.length} 件</span>
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
        <div className="filter-grid employee-filter-grid" aria-label="職員検索条件">
          <label>
            氏名・職員番号
            <input type="search" value={employeeSearch} onChange={(event) => setEmployeeSearch(event.target.value)} placeholder="名前・かな・番号" />
          </label>
          <label>
            所属
            <select value={employeeDepartmentFilter} onChange={(event) => setEmployeeDepartmentFilter(event.target.value)}>
              <option value="">すべて</option>
              {departments.map((department) => <option key={department} value={department}>{department}</option>)}
            </select>
          </label>
          <label>
            雇用区分
            <select value={employeeEmploymentFilter} onChange={(event) => setEmployeeEmploymentFilter(event.target.value)}>
              <option value="">すべて</option>
              {employmentTypeOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
          </label>
          <label>
            在職状態
            <select value={employeeStatusFilter} onChange={(event) => setEmployeeStatusFilter(event.target.value)}>
              <option value="">すべて</option>
              {statusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
          </label>
        </div>
        <p className="compact-empty">{visibleEmployees.length} 件を表示しています。在職状態の初期値は「在職」です。</p>
        {editMessage && editingEmployeeId === null ? <p className="feedback">{editMessage}</p> : null}
        <p className="table-scroll-hint">表を横にスクロールすると雇用区分・状態を確認できます。氏名から編集できます。</p>
        <div className="table-wrap inline-edit-table employee-table-wrap" role="region" aria-label="職員一覧（横スクロール）" tabIndex={0}>
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
              {visibleEmployees.length === 0 ? (
                <tr>
                  <td colSpan={4} className="table-empty-cell">{props.data.employees.length === 0 ? "職員データがありません" : "条件に一致する職員はいません"}</td>
                </tr>
              ) : (
                visibleEmployees.map((employee) => {
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
        <form className="stack-form" onSubmit={(event) => void submitEmployeeImport(event)} onChange={(event) => previewEmployeeImportForm(event.currentTarget)}>
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
            <input name="file" type="file" accept=".csv,text/csv" />
          </label>
          <p className="compact-empty">
            未入力の所属・雇用区分・状態・入職日は、この初期値で補完されます。メールアドレス列がある場合は職員ログインに同期します。
          </p>
          {isPreviewingEmployeeImport ? <p className="compact-empty" role="status">CSVを確認中です...</p> : null}
          {employeeImportPreview ? (
            <div className={employeeImportPreview.errors.length > 0 ? "import-preview import-preview-error" : "import-preview"}>
              <strong>取込プレビュー</strong>
              <span>ファイル: {employeeImportPreview.fileName}</span>
              <span>想定件数: {employeeImportPreview.rowCount} 件</span>
              <span>メールアドレス列: {employeeImportPreview.hasLoginEmail ? "あり" : "なし"}</span>
              <span>新規: {employeeImportPreview.createdCount} 件 / 更新: {employeeImportPreview.updatedCount} 件 / スキップ: {employeeImportPreview.skippedCount} 件</span>
              {employeeImportPreview.errors.length > 0 ? (
                <ul>
                  {employeeImportPreview.errors.slice(0, 10).map((error) => (
                    <li key={`${error.line}-${error.employeeCode ?? ""}`}>行 {error.line || "-"}{error.employeeCode ? `（${error.employeeCode}）` : ""}: {error.message}</li>
                  ))}
                </ul>
              ) : null}
            </div>
          ) : null}
          <button type="submit" disabled={isPreviewingEmployeeImport || !employeeImportPreview?.previewFingerprint || employeeImportPreview.errors.length > 0}>
            プレビュー内容で取り込む
          </button>
        </form>
        {props.form.employeeImportResult ? <p className="feedback">{props.form.employeeImportResult}</p> : null}
      </section>
      ) : null}
      <ConfirmDialog
        open={pendingEmployeeAction !== null}
        title={pendingEmployeeAction?.type === "import" ? "職員CSVを取り込みますか？" : "保存していない変更を破棄しますか？"}
        message={pendingEmployeeAction?.type === "import"
          ? `ファイル: ${employeeImportPreview?.fileName ?? "-"}／新規: ${employeeImportPreview?.createdCount ?? 0}件／更新: ${employeeImportPreview?.updatedCount ?? 0}件／スキップ: ${employeeImportPreview?.skippedCount ?? 0}件`
          : pendingEmployeeAction?.nextEmployee
            ? `別の職員を編集すると、現在の保存していない変更は破棄されます。次に編集する職員: ${pendingEmployeeAction.nextEmployee.employeeCode} / ${pendingEmployeeAction.nextEmployee.name}`
            : "編集をやめると、保存していない変更は破棄されます。"}
        confirmLabel={pendingEmployeeAction?.type === "import" ? "CSVを取り込む" : "変更を破棄"}
        onCancel={() => setPendingEmployeeAction(null)}
        onConfirm={() => {
          const action = pendingEmployeeAction;
          setPendingEmployeeAction(null);
          if (!action) return;
          if (action.type === "import") {
            void props.actions.onEmployeeImport(action.formData);
          } else if (action.nextEmployee) {
            applyStartEditing(action.nextEmployee);
          } else {
            clearEditing();
          }
        }}
      />
    </section>
  );
}
