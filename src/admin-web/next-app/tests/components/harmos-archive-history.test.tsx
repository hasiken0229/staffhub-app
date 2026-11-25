import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { HarmosHistoryPanel } from "@/components/dashboard-sections/harmos-history-panel";
import { loadHarmosArchivePage } from "@/lib/api/harmos-migration";
import type { ImportHistory } from "@/types";

vi.mock("@/lib/api/harmos-migration", () => ({ loadHarmosArchivePage: vi.fn() }));

const createRow = (id: number, name: string, available = true): ImportHistory => ({
  id, importType: "HARMOS_SOURCE_ARCHIVE", sourceFileName: name, targetPeriod: "2025-03",
  processedCount: 1, successCount: 1, errorCount: 0, createdAt: "2026-11-24T08:00:00", downloadAvailable: available,
});
const pageResult = (items: ImportHistory[], page: number, perPage: number, total: number) => ({
  items, meta: { page, perPage, total },
});
afterEach(() => vi.resetAllMocks());

describe("管理者のHRMOS移行原本", () => {
  it("最近の履歴から取得できる原本だけを認証済み操作へ渡す", async () => {
    const user = userEvent.setup();
    const download = vi.fn().mockResolvedValue(undefined);
    render(<HarmosHistoryPanel recent={[createRow(20, "日次.zip"), createRow(21, "過去.zip", false)]} formatType={() => "HRMOS移行原本"} onDownload={download} />);
    await user.click(screen.getByRole("button", { name: "日次.zip を再取得" }));
    expect(download).toHaveBeenCalledWith(20, "日次.zip");
    expect(screen.getByText("再取得不可")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "過去.zip を再取得" })).toBeNull();
    expect(loadHarmosArchivePage).not.toHaveBeenCalled();
  });

  it("認証切れなどの再取得失敗を履歴画面に表示する", async () => {
    const user = userEvent.setup();
    const download = vi.fn().mockRejectedValue(new Error("ログインの有効期限が切れました。"));
    render(<HarmosHistoryPanel recent={[createRow(4, "原本.zip")]} formatType={() => "HRMOS移行原本"} onDownload={download} />);
    await user.click(screen.getByRole("button", { name: "原本.zip を再取得" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("ログインの有効期限が切れました。");
  });

  it("総件数でページ送りし、名前検索では対象期間の101件目以降も照合する", async () => {
    const user = userEvent.setup();
    vi.mocked(loadHarmosArchivePage).mockImplementation(async (page, size, period) => {
      if (period === "2025-03") {
        return pageResult(page === 1 ? Array.from({ length: 100 }, (_, i) => createRow(i + 1, "架空" + i + ".zip")) :
          [{ ...createRow(135, "管理者向け原本.zip"), downloadFileName: "園別Ａ資料.zip" }], page, size, 101);
      }
      return pageResult([createRow(page === 1 ? 1 : 21, "通常" + page + ".zip")], page, size, 125);
    });
    const download = vi.fn().mockResolvedValue(undefined);
    render(<HarmosHistoryPanel recent={[]} formatType={() => "HRMOS移行原本"} onDownload={download} />);
    await user.click(screen.getByRole("button", { name: "HRMOS移行原本を探す" }));
    await screen.findByText("通常1.zip");
    await user.click(screen.getByRole("button", { name: "次へ" }));
    await screen.findByText("通常2.zip");
    await user.type(screen.getByLabelText("原本のファイル名（部分一致）"), "A資料");
    await user.type(screen.getByLabelText("対象期間（完全一致）"), "2025-03");
    await user.click(screen.getByRole("button", { name: "原本を検索" }));
    await user.click(await screen.findByRole("button", { name: "園別Ａ資料.zip を再取得" }));
    expect(download).toHaveBeenCalledWith(135, "園別Ａ資料.zip");
    expect(vi.mocked(loadHarmosArchivePage).mock.calls.map(args => args.slice(0, 3))).toEqual([
      [1,20,""], [2,20,""], [1,100,"2025-03"], [2,100,"2025-03"],
    ]);
  });

  it("取得に失敗した検索はエラーを示し、再検索できる", async () => {
    const user = userEvent.setup();
    vi.mocked(loadHarmosArchivePage).mockRejectedValueOnce(new Error("取得に失敗しました")).mockResolvedValueOnce(pageResult([],1,20,0));
    render(<HarmosHistoryPanel recent={[]} formatType={() => ""} onDownload={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: "HRMOS移行原本を探す" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("取得に失敗しました");
    await user.click(screen.getByRole("button", { name: "原本を検索" }));
    await waitFor(() => expect(screen.getByText("該当する移行原本はありません。")).toBeInTheDocument());
  });
});

