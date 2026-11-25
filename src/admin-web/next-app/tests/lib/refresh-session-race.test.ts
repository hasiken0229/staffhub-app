import { afterEach, expect, it, vi } from "vitest";
import { clearAdminToken, fetchJson, loadAdminToken, loadRefreshToken, saveSession } from "@/lib/api/core";

function deferred<T>() { let resolve!: (value:T)=>void;const promise=new Promise<T>(done=>{resolve=done;});return {promise,resolve}; }
const json=(data:unknown,status=200)=>new Response(JSON.stringify({data}),{status,headers:{"Content-Type":"application/json"}});
const refreshed=()=>json({accessToken:"rotated-access",refreshToken:"rotated-refresh",user:{role:"ADMIN"}});
afterEach(()=>{clearAdminToken();vi.unstubAllGlobals();vi.restoreAllMocks();});

it("does not restore a cleared session from a late token refresh or replay its request",async()=>{
  saveSession("expired-access","ADMIN","old-refresh");
  const pending=deferred<Response>();
  const fetchMock=vi.fn().mockResolvedValueOnce(json(null,401)).mockReturnValueOnce(pending.promise);
  vi.stubGlobal("fetch",fetchMock);
  const unauthorized=vi.fn();window.addEventListener("staffhub:unauthorized",unauthorized);
  const request=fetchJson("/api/admin/attendance/daily");
  const outcome=expect(request).rejects.toMatchObject({code:"SESSION_CHANGED"});
  await vi.waitFor(()=>expect(fetchMock).toHaveBeenCalledTimes(2));
  clearAdminToken();pending.resolve(refreshed());
  await outcome;
  expect(loadAdminToken()).toBe("");expect(loadRefreshToken()).toBe("");expect(fetchMock).toHaveBeenCalledTimes(2);expect(unauthorized).not.toHaveBeenCalled();
  window.removeEventListener("staffhub:unauthorized",unauthorized);
});

it("does not overwrite a newer login when the older refresh finishes",async()=>{
  saveSession("expired-access","ADMIN","old-refresh");
  const pending=deferred<Response>();
  const fetchMock=vi.fn().mockResolvedValueOnce(json(null,401)).mockReturnValueOnce(pending.promise);
  vi.stubGlobal("fetch",fetchMock);
  const request=fetchJson("/api/admin/attendance/daily");const outcome=expect(request).rejects.toMatchObject({code:"SESSION_CHANGED"});
  await vi.waitFor(()=>expect(fetchMock).toHaveBeenCalledTimes(2));
  saveSession("new-employee-access","EMPLOYEE","new-employee-refresh");pending.resolve(refreshed());await outcome;
  expect(loadAdminToken()).toBe("new-employee-access");expect(loadRefreshToken()).toBe("new-employee-refresh");expect(fetchMock).toHaveBeenCalledTimes(2);
});

it("rotates an active session once and retries with the new access token",async()=>{
  saveSession("expired-access","ADMIN","old-refresh");
  const fetchMock=vi.fn().mockResolvedValueOnce(json(null,401)).mockResolvedValueOnce(refreshed()).mockResolvedValueOnce(json({ready:true}));
  vi.stubGlobal("fetch",fetchMock);
  await expect(fetchJson("/api/admin/attendance/daily")).resolves.toEqual({ready:true});
  expect(loadAdminToken()).toBe("rotated-access");expect(loadRefreshToken()).toBe("rotated-refresh");
  expect(new Headers(fetchMock.mock.calls[2][1].headers).get("Authorization")).toBe("Bearer rotated-access");
});

it("discards an earlier account's response when logout happens while its JSON body is loading",async()=>{
  saveSession("old-access","ADMIN","old-refresh");
  const body=deferred<{data:unknown}>();const response=json(null);
  const readBody=vi.spyOn(response,"json").mockReturnValue(body.promise);
  vi.stubGlobal("fetch",vi.fn().mockResolvedValue(response));
  const request=fetchJson("/api/admin/attendance/daily");const outcome=expect(request).rejects.toMatchObject({code:"SESSION_CHANGED"});
  await vi.waitFor(()=>expect(readBody).toHaveBeenCalledOnce());
  clearAdminToken();saveSession("new-access","ADMIN","new-refresh");body.resolve({data:{oldEmployee:"private"}});await outcome;
  expect(loadAdminToken()).toBe("new-access");
});
