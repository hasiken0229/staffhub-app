type Column<T> = {
  key: string;
  header: string;
  render: (row: T) => React.ReactNode;
};

type DataTableProps<T> = {
  id?: string;
  title: string;
  rows: T[];
  columns: Column<T>[];
  emptyMessage?: string;
};

export function DataTable<T>({ id, title, rows, columns, emptyMessage = "データがありません" }: DataTableProps<T>) {
  return (
    <section id={id} className="panel anchor-panel">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">一覧</p>
          <h3>{title}</h3>
        </div>
        <span className="panel-meta">{rows.length} 件</span>
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
                <td colSpan={columns.length}>{emptyMessage}</td>
              </tr>
            ) : (
              rows.map((row, index) => (
                <tr key={index}>
                  {columns.map((column) => (
                    <td key={column.key}>{column.render(row)}</td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}
