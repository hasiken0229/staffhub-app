"use client";

import { useId, useLayoutEffect, useRef } from "react";

type ConfirmDialogProps = {
  open: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  busy?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
};

export function ConfirmDialog({ open, title, message, confirmLabel, busy = false, onCancel, onConfirm }: ConfirmDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const contentRef = useRef<HTMLDivElement>(null);
  const cancelButtonRef = useRef<HTMLButtonElement>(null);
  const confirmButtonRef = useRef<HTMLButtonElement>(null);
  const id = useId();

  useLayoutEffect(() => {
    const dialog = dialogRef.current;
    if (!open || !dialog) return;
    const trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const root = document.documentElement;
    const previousOverflow = root.style.overflow;
    root.style.overflow = "hidden";
    const viewport = window.visualViewport;
    function fitVisibleViewport() {
      if (!viewport || !dialog) return;
      dialog.style.width = `${viewport.width}px`;
      dialog.style.height = `${viewport.height}px`;
      dialog.style.top = `${viewport.offsetTop}px`;
      dialog.style.left = `${viewport.offsetLeft}px`;
    }
    fitVisibleViewport();
    viewport?.addEventListener("resize", fitVisibleViewport);
    viewport?.addEventListener("scroll", fitVisibleViewport);
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
    cancelButtonRef.current?.focus({ preventScroll: true });
    return () => {
      viewport?.removeEventListener("resize", fitVisibleViewport);
      viewport?.removeEventListener("scroll", fitVisibleViewport);
      if (typeof dialog.close === "function") dialog.close();
      root.style.overflow = previousOverflow;
      if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    };
  }, [open]);

  if (!open) return null;

  function handleKeyDown(event: React.KeyboardEvent<HTMLElement>) {
    if (event.key === "Escape") {
      event.preventDefault();
      if (busy) return;
      onCancel();
      return;
    }

    if (event.key !== "Tab") {
      return;
    }

    if (busy) {
      event.preventDefault();
      contentRef.current?.focus();
    } else if (event.shiftKey && document.activeElement === contentRef.current) {
      event.preventDefault();
      confirmButtonRef.current?.focus();
    } else if (!event.shiftKey && document.activeElement === confirmButtonRef.current) {
      event.preventDefault();
      contentRef.current?.focus();
    }
  }

  return (
    <dialog ref={dialogRef} className="confirm-dialog-backdrop" aria-modal="true" aria-labelledby={`${id}-title`} aria-describedby={`${id}-message`} onKeyDown={handleKeyDown} onCancel={(event) => { event.preventDefault(); if (!busy) onCancel(); }}>
      <div className="confirm-dialog">
        <div className="confirm-dialog-content" ref={contentRef} tabIndex={0} role="region" aria-labelledby={`${id}-title`}>
          <h2 id={`${id}-title`}>{title}</h2>
          <p id={`${id}-message`}>{message}</p>
        </div>
        <div className="confirm-dialog-actions">
          <button ref={cancelButtonRef} type="button" className="secondary" disabled={busy} onClick={onCancel}>戻る</button>
          <button ref={confirmButtonRef} type="button" className="danger-action" disabled={busy} onClick={onConfirm}>{busy ? "処理中..." : confirmLabel}</button>
        </div>
      </div>
    </dialog>
  );
}
