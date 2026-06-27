import { useState } from "react";
import { currentDateValue } from "@/lib/date-defaults";

export function useNoticeFormState() {
  const [noticeType, setNoticeType] = useState("GENERAL");
  const [noticeTitle, setNoticeTitle] = useState("");
  const [noticeBody, setNoticeBody] = useState("");
  const [noticeStartAt, setNoticeStartAt] = useState(() => `${currentDateValue()}T09:00`);
  const [noticeEndAt, setNoticeEndAt] = useState("");
  const [noticeResult, setNoticeResult] = useState("");

  return {
    noticeType,
    setNoticeType,
    noticeTitle,
    setNoticeTitle,
    noticeBody,
    setNoticeBody,
    noticeStartAt,
    setNoticeStartAt,
    noticeEndAt,
    setNoticeEndAt,
    noticeResult,
    setNoticeResult,
  };
}
