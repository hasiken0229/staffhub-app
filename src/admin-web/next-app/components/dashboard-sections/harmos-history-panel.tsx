import { useEffect, useState, type FormEvent } from "react";
import { loadHarmosArchivePage } from "@/lib/api/harmos-migration";
import type { ImportHistory } from "@/types";

const pageSize = 20;
const scanSize = 100;
const normalizeName = (value: string) => value.normalize("NFKC").toLowerCase();

type HistoryActions = {
  formatType: (type: string) => string;
  onDownload: (id: number, fileName?: string) => Promise<unknown>;
};

function HistoryTable({ rows, formatType, onDownload }: HistoryActions & { rows: ImportHistory[] }) {
  const [downloadError, setDownloadError] = useState("");
  const [downloading, setDownloading] = useState<number | null>(null);
  async function download(id: number, name: string) {
    setDownloadError("");
    setDownloading(id);
    try {
      await onDownload(id, name);
    } catch (cause) {
      setDownloadError(cause instanceof Error ? cause.message : "原本の再取得に失敗しました。");
    } finally {
      setDownloading(null);
    }
  }

  return (
    <div className="table-wrap">
      {downloadError && <p role="alert">{downloadError}</p>}
      <table>
        <thead><tr><th>日時</th><th>種別</th><th>対象期間</th><th>ファイル</th><th>成功</th><th>エラー</th><th>原本</th></tr></thead>
        <tbody>
          {rows.map(item => {
            const name = item.downloadFileName || item.sourceFileName;
            return (
              <tr key={item.id}>
                <td>{item.createdAt?.slice(0, 16).replace("T", " ")}</td>
                <td>{formatType(item.importType)}</td><td>{item.targetPeriod || "—"}</td><td>{name}</td>
                <td>{item.successCount}</td><td>{item.errorCount}</td>
                <td>{item.downloadAvailable ? (
                  <button type="button" className="table-action" aria-label={name + " を再取得"} disabled={downloading !== null} onClick={() => void download(item.id, name)}>{downloading === item.id ? "取得中" : "再取得"}</button>
                ) : "再取得不可"}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function ArchiveSearch(actions: HistoryActions) {
  const [fileName, setFileName] = useState("");
  const [period, setPeriod] = useState("");
  const [filters, setFilters] = useState({ name: "", period: "" });
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<{ items: ImportHistory[]; total: number } | null>(null);
  const [error, setError] = useState("");
  const serverPage = filters.name ? 1 : page;

  useEffect(() => {
    const controller = new AbortController();
    setResult(null);
    setError("");
    async function load() {
      try {
        const size = filters.name ? scanSize : pageSize;
        const first = await loadHarmosArchivePage(serverPage, size, filters.period, controller.signal);
        if (controller.signal.aborted) return;
        if (!filters.name) {
          setResult({ items: first.items, total: first.meta.total });
          return;
        }
        const matches = new Map<number, ImportHistory>();
        const needle = normalizeName(filters.name);
        function collect(rows: ImportHistory[]) {
          for (const row of rows) {
            if ([row.sourceFileName, row.downloadFileName || ""].some(name => normalizeName(name).includes(needle))) matches.set(row.id, row);
          }
        }
        collect(first.items);
        const pages = Math.ceil(first.meta.total / size);
        for (let current = 2; current <= pages; current++) {
          const next = await loadHarmosArchivePage(current, size, filters.period, controller.signal);
          if (controller.signal.aborted) return;
          collect(next.items);
        }
        setResult({ items: [...matches.values()], total: matches.size });
      } catch (cause) {
        if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : "原本の履歴を取得できませんでした。");
      }
    }
    void load();
    return () => controller.abort();
  }, [filters, serverPage]);

  function search(event: FormEvent) {
    event.preventDefault();
    setResult(null);
    setError("");
    setPage(1);
    setFilters({ name: fileName.trim(), period: period.trim() });
  }

  function changePage(next: number) {
    if (!filters.name) setResult(null);
    setPage(next);
  }

  const pages = result ? Math.max(1, Math.ceil(result.total / pageSize)) : 1;
  const rows = filters.name ? result?.items.slice((page - 1) * pageSize, page * pageSize) : result?.items;
  return <>
    <form onSubmit={search} className="stack-form">
      <div className="form-grid">
        <label>原本のファイル名（部分一致）<input value={fileName} onChange={event => setFileName(event.target.value)} /></label>
        <label>対象期間（完全一致）<input value={period} placeholder="例：2026-04" onChange={event => setPeriod(event.target.value)} /></label>
      </div>
      <div><button type="submit" className="secondary-button">原本を検索</button></div>
    </form>
    {error ? <p role="alert">{error}</p> : !result ? <p role="status">移行原本を検索しています。</p> : <>
      <p role="status">{result.total ? `${result.total} 件中 ${(page - 1) * pageSize + 1}～${Math.min(page * pageSize, result.total)} 件` : "該当する移行原本はありません。"}</p>
      {rows && rows.length > 0 && <HistoryTable {...actions} rows={rows} />}
      {pages > 1 && <nav aria-label="移行原本のページ" className="button-row">
        <button className="secondary-button" type="button" disabled={page === 1} onClick={() => changePage(page - 1)}>前へ</button>
        <span>{page} / {pages}</span>
        <button className="secondary-button" type="button" disabled={page >= pages} onClick={() => changePage(page + 1)}>次へ</button>
      </nav>}
    </>}
  </>;
}

export function HarmosHistoryPanel({ recent, ...actions }: HistoryActions & { recent: ImportHistory[] }) {
  const [archives, setArchives] = useState(false);
  return (
    <section id="harmos-history" className="panel anchor-panel">
      <div className="panel-header"><h3>HARMOS取込履歴</h3>
        <button type="button" className="secondary-button" aria-expanded={archives} onClick={() => setArchives(!archives)}>
          {archives ? "最近の取込履歴に戻る" : "HRMOS移行原本を探す"}
        </button>
      </div>
      {archives ? <ArchiveSearch {...actions} /> : recent.length ? <HistoryTable {...actions} rows={recent} /> : <p>HARMOS取込履歴はまだありません</p>}
    </section>
  );
}

