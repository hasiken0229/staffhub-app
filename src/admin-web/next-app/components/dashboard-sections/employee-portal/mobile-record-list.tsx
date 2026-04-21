import type { ReactNode } from "react";

type MobileRecordListProps<T> = {
  id?: string;
  title: string;
  rows: T[];
  emptyMessage: string;
  renderTitle: (row: T) => ReactNode;
  renderMeta?: (row: T) => ReactNode;
  renderBody?: (row: T) => ReactNode;
  renderFields: (row: T) => Array<{
    label: string;
    value: ReactNode;
  }>;
  renderActions?: (row: T) => ReactNode;
};

export function MobileRecordList<T>(props: MobileRecordListProps<T>) {
  return (
    <section id={props.id} className="panel mobile-record-list">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">モバイル表示</p>
          <h3>{props.title}</h3>
        </div>
        <span className="panel-meta">{props.rows.length} 件</span>
      </div>

      {props.rows.length === 0 ? (
        <p className="compact-empty">{props.emptyMessage}</p>
      ) : (
        <div className="mobile-records">
          {props.rows.map((row, index) => (
            <article key={index} className="mobile-record-card">
              <div className="mobile-record-header">
                <strong className="mobile-record-title">{props.renderTitle(row)}</strong>
                {props.renderMeta ? <span className="mobile-record-meta">{props.renderMeta(row)}</span> : null}
              </div>

              {props.renderBody ? <div className="mobile-record-body">{props.renderBody(row)}</div> : null}

              <div className="mobile-record-grid">
                {props.renderFields(row).map((field, fieldIndex) => (
                  <div key={fieldIndex} className="mobile-record-item">
                    <span className="mobile-record-label">{field.label}</span>
                    <div className="mobile-record-value">{field.value}</div>
                  </div>
                ))}
              </div>

              {props.renderActions ? <div className="button-row mobile-record-actions">{props.renderActions(row)}</div> : null}
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
