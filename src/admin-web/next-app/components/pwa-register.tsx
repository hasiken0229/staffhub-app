"use client";

import { useEffect } from "react";
import { withBasePath } from "@/lib/base-path";

export function PwaRegister() {
  useEffect(() => {
    if (typeof window === "undefined" || !("serviceWorker" in navigator)) {
      return;
    }

    const serviceWorkerUrl = withBasePath("/sw.js");

    void navigator.serviceWorker.register(serviceWorkerUrl).catch(() => {
      // Keep the UI silent if registration fails in unsupported environments.
    });
  }, []);

  return null;
}
