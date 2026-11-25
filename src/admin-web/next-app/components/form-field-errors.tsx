"use client";

import { useEffect, useRef } from "react";

export type FieldErrors = Record<string, string[]>;

export function FieldError({ errors, field, idPrefix }: { errors?: FieldErrors; field: string; idPrefix?: string }) {
  const messages = errors?.[field];
  return messages?.length ? <span id={idPrefix ? `${idPrefix}-error-${field}` : `error-${field}`} className="field-error">{messages.join(" ")}</span> : null;
}

export function ValidationSummary({ errors }: { errors?: FieldErrors }) {
  const summaryRef = useRef<HTMLDivElement>(null);
  const hasErrors = Object.keys(errors ?? {}).length > 0;
  useEffect(() => {
    if (!hasErrors) return;
    const summary = summaryRef.current;
    const form = summary?.closest("form") ?? summary?.parentElement;
    const firstField = Object.keys(errors ?? {})[0];
    const input = findField(form, firstField);
    (input ?? summary)?.focus();
  }, [errors, hasErrors]);
  if (!hasErrors) return null;
  return <div ref={summaryRef} className="banner" role="alert" tabIndex={-1}>
    <strong>入力項目を確認してください。</strong>
    <ul>{Object.entries(errors ?? {}).flatMap(([field, messages]) => messages.map((message, index) =>
      <li key={`${field}-${index}`}><button type="button" className="field-error-link" onClick={() => {
        const scope = summaryRef.current?.closest("form") ?? summaryRef.current?.parentElement;
        findField(scope, field)?.focus();
      }}>{message}</button></li>))}</ul>
  </div>;
}

function findField(scope: Element | null | undefined, name: string) { return Array.from(scope?.querySelectorAll<HTMLElement>("[name]") ?? []).find((element) => element.getAttribute("name") === name); }
