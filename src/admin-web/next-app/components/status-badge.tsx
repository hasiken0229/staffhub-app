type StatusBadgeTone = "neutral" | "info" | "success" | "warning" | "danger";

type StatusBadgeProps = {
  value?: string | null;
  label: string;
  tone?: StatusBadgeTone;
};

const APPROVAL_TONE: Record<string, StatusBadgeTone> = {
  PENDING: "warning",
  APPROVED: "success",
  RETURNED: "info",
  REJECTED: "danger",
  CANCELLED: "neutral",
  APPLIED: "info",
};

const RECEIVE_TONE: Record<string, StatusBadgeTone> = {
  ACCEPTED: "success",
  REJECTED: "danger",
  PENDING: "warning",
  OFFLINE_STORED: "info",
};

export function StatusBadge({ value, label, tone = "neutral" }: StatusBadgeProps) {
  return <span className={`status-badge status-badge-${tone}`}>{label || value || "-"}</span>;
}

export function ApprovalStatusBadge({
  value,
  format,
}: {
  value?: string | null;
  format: (value?: string | null) => string;
}) {
  const normalized = (value ?? "").toUpperCase();
  return <StatusBadge value={value} label={format(value)} tone={APPROVAL_TONE[normalized] ?? "neutral"} />;
}

export function ReceiveStatusBadge({
  value,
  format,
}: {
  value?: string | null;
  format: (value?: string | null) => string;
}) {
  const normalized = (value ?? "").toUpperCase();
  return <StatusBadge value={value} label={format(value)} tone={RECEIVE_TONE[normalized] ?? "neutral"} />;
}

export function ReadStatusBadge({ isRead }: { isRead: boolean }) {
  return <StatusBadge value={isRead ? "READ" : "UNREAD"} label={isRead ? "既読" : "未読"} tone={isRead ? "success" : "warning"} />;
}
