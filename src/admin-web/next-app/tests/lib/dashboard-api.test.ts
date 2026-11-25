import { afterEach, describe, expect, it, vi } from "vitest";
import { loadAdminSectionData, loadEmployeePortalData } from "@/lib/api/dashboard";

describe("loadAdminSectionData attendance", () => {
  it("職員画面の再取得は指定された過去月を使い応答にも対象月を残す", async () => {
    const fetchMock = vi.fn().mockImplementation(async (input: string) => new Response(JSON.stringify({
      data: input.includes("/leave/ledger") ? { items: [] } : [],
    }), { status: 200, headers: { "Content-Type": "application/json" } }));
    vi.stubGlobal("fetch", fetchMock);
    const result = await loadEmployeePortalData("2026-09");
    expect(result.attendanceTargetMonth).toBe("2026-09");
    const dailyUrl = fetchMock.mock.calls.map(([url]) => String(url)).find(url => url.includes("/api/attendance/daily?"));
    expect(new URL(dailyUrl!).searchParams.get("targetMonth")).toBe("2026-09");
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("大量の承認履歴があってもレポートは直近100件だけ取得する", async () => {
    const fetchMock = vi.fn().mockImplementation(async (input: string) => {
      const isHistory = String(input).includes("attendance-approvals");
      return new Response(JSON.stringify({ data: [], meta: { total: isHistory ? 60000 : 0 } }), {
        status: 200, headers: { "Content-Type": "application/json" },
      });
    });
    vi.stubGlobal("fetch", fetchMock);
    await loadAdminSectionData("reports");
    const historyCalls = fetchMock.mock.calls.map(([url]) => String(url)).filter(url => url.includes("attendance-approvals"));
    expect(historyCalls).toHaveLength(1);
    expect(new URL(historyCalls[0]).searchParams.get("page")).toBe("1");
    expect(new URL(historyCalls[0]).searchParams.get("perPage")).toBe("100");
  });

  it("対象月と絞り込み条件を通常のクエリとして送る", async () => {
    const fetchMock = vi.fn().mockImplementation(async () =>
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    );
    vi.stubGlobal("fetch", fetchMock);

    await loadAdminSectionData("attendance", {
      targetMonth: "2026-06",
      employeeCode: "E0001",
      departmentName: "保育部",
      approvalStatus: "PENDING",
      eventFrom: "2026-06-01",
      eventTo: "2026-06-30",
      errorCode: "MISSING_PUNCH",
      errorHandlingStatus: "OPEN",
      monthCloseApprovalStatus: "PENDING",
      monthCloseStatus: "OPEN",
    });

    const urls = fetchMock.mock.calls.map(([input]) => String(input));
    expect(urls.every((url) => !url.includes("??"))).toBe(true);

    const dailyUrl = urls.find((url) => new URL(url).pathname.endsWith("/api/admin/attendance/daily"));
    expect(dailyUrl).toBeDefined();
    const daily = new URL(dailyUrl!);
    expect(daily.searchParams.get("targetMonth")).toBe("2026-06");
    expect(daily.searchParams.get("employeeCode")).toBe("E0001");
    expect(daily.searchParams.get("departmentName")).toBe("保育部");

    const closeStatus = new URL(urls.find((url) => new URL(url).pathname.endsWith("/api/admin/attendance/month-close-status"))!);
    expect(closeStatus.searchParams.get("perPage")).toBe("10000");
    expect(closeStatus.searchParams.get("page")).toBe("1");

    const errorsUrl = urls.find((url) => new URL(url).pathname.endsWith("/api/admin/attendance/errors"));
    expect(errorsUrl).toBeDefined();
    const errors = new URL(errorsUrl!);
    expect(errors.searchParams.get("fromMonth")).toBe("2026-06");
    expect(errors.searchParams.get("toMonth")).toBe("2026-06");
    expect(errors.searchParams.get("approvalStatus")).toBe("PENDING");
    fetchMock.mockClear();
    await loadAdminSectionData("attendance", {targetMonth: "2026-06", approvalStatus: ""});
    const approvalUrl = fetchMock.mock.calls.map(([url]) => new URL(String(url))).find(url => url.pathname.endsWith("/attendance/approvals"));
    expect(approvalUrl?.searchParams.get("status")).toBe("ALL");
  });
});
