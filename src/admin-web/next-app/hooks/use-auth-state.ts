import { useState } from "react";
import type { AuthAudience } from "@/types";

export function useAuthState() {
  const [loginId, setLoginId] = useState("");
  const [password, setPassword] = useState("");
  const [authMessage, setAuthMessage] = useState("");
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [currentAudience, setCurrentAudience] = useState<AuthAudience | "">("");

  return {
    loginId,
    setLoginId,
    password,
    setPassword,
    authMessage,
    setAuthMessage,
    isAuthenticated,
    setIsAuthenticated,
    currentAudience,
    setCurrentAudience,
  };
}
