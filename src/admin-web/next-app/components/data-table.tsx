import { Fragment } from "react";

type Column<T> = {
  key: string;
  header: React.ReactNode;
  render: (row: T) => React.ReactNode;
};

type DataTableProps<T> = {
  id?: string;
  title: string;
  rows: T[];
  columns: Column<T>[];
  emptyMessage?: string;
  rowClassName?: (row: T) => string | undefined;
  renderExpandedRow?: (row: T) => React.ReactNode;
  metaLabel?: string;
  footer?: React.ReactNode;
};

export function DataTable<T>({ id, title, rows, columns, emptyMessage = "データがありません", rowClassName, renderExpandedRow, metaLabel, footer }: DataTableProps<T>) {
  return (
    <section id={id} className="panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>{title}</h3>
        </div>
        <div className="panel-meta-stack">
          <span className="panel-meta">{metaLabel ?? `${rows.length} 件`}</span>
          <span className="table-scroll-hint">横にスクロールできます</span>
        </div>
      </div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.key}>{column.header}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="table-empty-cell">
                  {emptyMessage}
                </td>
              </tr>
            ) : (
              rows.map((row, index) => {
                const detail = renderExpandedRow?.(row);
                return (
                  <Fragment key={index}>
                    <tr className={rowClassName?.(row)}>
                      {columns.map((column) => (
                        <td key={column.key}>{column.render(row)}</td>
                      ))}
                    </tr>
                    {detail && <tr><td colSpan={columns.length}>{detail}</td></tr>}
                  </Fragment>
                );
              })
            )}
          </tbody>
        </table>
      </div>
      {footer}
    </section>
  );
}
