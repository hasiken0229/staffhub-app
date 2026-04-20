const state = {
  apiBaseUrl: "http://localhost:5000",
};

const sections = document.querySelectorAll(".panel-section");
const menuItems = document.querySelectorAll(".menu-item");

menuItems.forEach((button) => {
  button.addEventListener("click", () => {
    menuItems.forEach((item) => item.classList.remove("is-active"));
    sections.forEach((section) => section.classList.remove("is-visible"));
    button.classList.add("is-active");
    document.getElementById(`section-${button.dataset.section}`).classList.add("is-visible");
  });
});

document.getElementById("configForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  state.apiBaseUrl = document.getElementById("apiBaseInput").value.trim().replace(/\/$/, "");
  document.getElementById("apiBaseLabel").textContent = state.apiBaseUrl;
  await refreshAll();
});

document.querySelectorAll("[data-action]").forEach((button) => {
  button.addEventListener("click", async () => {
    await refreshAll();
  });
});

document.querySelectorAll("[data-decision]").forEach((button) => {
  button.addEventListener("click", async () => {
    const id = document.getElementById("leaveIdInput").value;
    const comment = document.getElementById("leaveCommentInput").value;
    const decision = button.dataset.decision;
    const actionMap = { approve: "approve", reject: "reject", return: "return" };
    const resultLabel = document.getElementById("leaveDecisionResult");

    try {
      const response = await apiFetch(`/api/admin/leave/requests/${id}/${actionMap[decision]}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ comment }),
      });
      resultLabel.textContent = `申請 ${response.data.id} を ${response.data.status} に更新しました。`;
      await refreshLeave();
      await refreshDashboard();
    } catch (error) {
      resultLabel.textContent = error.message;
    }
  });
});

document.getElementById("payrollUploadForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData();
  formData.append("employeeId", document.getElementById("payrollEmployeeId").value);
  formData.append("targetYearMonth", document.getElementById("payrollYearMonth").value);
  const publishedAt = document.getElementById("payrollPublishedAt").value;
  if (publishedAt) {
    formData.append("publishedAt", new Date(publishedAt).toISOString());
  }

  const fileInput = document.getElementById("payrollFile");
  if (fileInput.files[0]) {
    formData.append("file", fileInput.files[0]);
  }

  const resultLabel = document.getElementById("payrollUploadResult");
  try {
    const response = await apiFetch("/api/admin/payroll/statements", { method: "POST", body: formData });
    resultLabel.textContent = `給与明細 ${response.data.id} を登録しました。`;
    await refreshPayroll();
    await refreshDashboard();
  } catch (error) {
    resultLabel.textContent = error.message;
  }
});

async function apiFetch(path, options = {}) {
  const response = await fetch(`${state.apiBaseUrl}${path}`, options);
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(body.error?.message || "API呼び出しに失敗しました。");
  }
  return body;
}

function renderRows(targetId, rows, fallback = "データがありません") {
  const target = document.getElementById(targetId);
  if (!rows.length) {
    target.innerHTML = `<tr><td colspan="8">${fallback}</td></tr>`;
    return;
  }
  target.innerHTML = rows.join("");
}

async function refreshDashboard() {
  try {
    const [health, leave, attendance, payroll, audit] = await Promise.all([
      apiFetch("/health"),
      apiFetch("/api/admin/leave/requests"),
      apiFetch("/api/admin/attendance/events"),
      apiFetch("/api/admin/payroll/statements"),
      apiFetch("/api/admin/audit-logs"),
    ]);

    document.getElementById("healthStatus").textContent = health.data.status;
    document.getElementById("metricLeave").textContent = leave.data.length;
    document.getElementById("metricAttendance").textContent = attendance.data.length;
    document.getElementById("metricPayroll").textContent = payroll.data.length;
    document.getElementById("metricAudit").textContent = audit.data.length;

    const latestAttendance = attendance.data[0];
    const latestLeave = leave.data[0];
    const latestPayroll = payroll.data[0];

    document.getElementById("spotlightTitle").textContent = leave.data.length
      ? `${leave.data.length}件の休暇申請を確認できます`
      : "現在の保留案件はありません";
    document.getElementById("spotlightBody").textContent = attendance.data.length
      ? `${attendance.data.length}件の打刻履歴と ${payroll.data.length}件の給与明細データを読み込みました。`
      : "APIのモックデータを準備中です。";
    document.getElementById("latestAttendance").textContent = latestAttendance
      ? `${latestAttendance.employeeName} / ${latestAttendance.eventType}`
      : "-";
    document.getElementById("latestLeave").textContent = latestLeave
      ? `${latestLeave.employeeName} / ${latestLeave.status}`
      : "-";
    document.getElementById("latestPayroll").textContent = latestPayroll
      ? `${latestPayroll.targetYearMonth} / ${latestPayroll.originalFileName}`
      : "-";
  } catch (error) {
    document.getElementById("healthStatus").textContent = "接続失敗";
    document.getElementById("spotlightTitle").textContent = "APIへ接続できません";
    document.getElementById("spotlightBody").textContent = error.message;
  }
}

async function refreshEmployees() {
  const response = await apiFetch("/api/admin/employees");
  renderRows("employeesTable", response.data.map((row) => `
    <tr>
      <td>${row.employeeCode}</td>
      <td>${row.name}</td>
      <td>${row.departmentName ?? "-"}</td>
      <td>${row.employmentType}</td>
      <td>${row.status}</td>
    </tr>
  `));
}

async function refreshCards() {
  const response = await apiFetch("/api/admin/cards");
  renderRows("cardsTable", response.data.map((row) => `
    <tr>
      <td>${row.cardUid}</td>
      <td>${row.employeeCode}</td>
      <td>${row.employeeName}</td>
      <td>${row.isActive ? "有効" : "無効"}</td>
      <td>${formatDateTime(row.assignedAt)}</td>
    </tr>
  `));
}

async function refreshAttendance() {
  const response = await apiFetch("/api/admin/attendance/events");
  renderRows("attendanceTable", response.data.map((row) => `
    <tr>
      <td>${formatDateTime(row.occurredAt)}</td>
      <td>${row.employeeName ?? "-"}</td>
      <td>${row.eventType ?? "-"}</td>
      <td>${row.receiveStatus}</td>
      <td>${row.deviceName}</td>
    </tr>
  `));
}

async function refreshLeave() {
  const response = await apiFetch("/api/admin/leave/requests");
  renderRows("leaveTable", response.data.map((row) => `
    <tr>
      <td>${row.id}</td>
      <td>${row.employeeName}</td>
      <td>${row.leaveTypeName}</td>
      <td>${row.startDate} - ${row.endDate}</td>
      <td>${row.status}</td>
    </tr>
  `));
}

async function refreshPayroll() {
  const response = await apiFetch("/api/admin/payroll/statements");
  renderRows("payrollTable", response.data.map((row) => `
    <tr>
      <td>${row.id}</td>
      <td>${row.employeeId}</td>
      <td>${row.targetYearMonth}</td>
      <td>${row.originalFileName}</td>
      <td>${row.publishedAt ? formatDateTime(row.publishedAt) : "未公開"}</td>
    </tr>
  `));
}

async function refreshAudit() {
  const response = await apiFetch("/api/admin/audit-logs");
  renderRows("auditTable", response.data.map((row) => `
    <tr>
      <td>${formatDateTime(row.occurredAt)}</td>
      <td>${row.action}</td>
      <td>${row.targetType} / ${row.targetId ?? "-"}</td>
      <td>${row.detail}</td>
    </tr>
  `));
}

async function refreshAll() {
  await Promise.all([
    refreshDashboard(),
    refreshEmployees(),
    refreshCards(),
    refreshAttendance(),
    refreshLeave(),
    refreshPayroll(),
    refreshAudit(),
  ]).catch((error) => {
    document.getElementById("spotlightTitle").textContent = "一部データの読込に失敗しました";
    document.getElementById("spotlightBody").textContent = error.message;
  });
}

function formatDateTime(value) {
  if (!value) {
    return "-";
  }
  return new Date(value).toLocaleString("ja-JP");
}

refreshAll();

