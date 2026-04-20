import { useState } from "react";

export function useCardAssignmentState() {
  const [assignEmployeeId, setAssignEmployeeId] = useState("1");
  const [assignCardUid, setAssignCardUid] = useState("");
  const [assignResult, setAssignResult] = useState("");

  return {
    assignEmployeeId,
    setAssignEmployeeId,
    assignCardUid,
    setAssignCardUid,
    assignResult,
    setAssignResult,
  };
}
