import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import type { ComponentProps } from "react";
import { AttendanceTablesPanel } from "@/components/dashboard-sections/attendance/attendance-tables-panel";
import { emptyData } from "@/lib/dashboard-defaults";
import type { AttendanceDeviceStatus } from "@/types/attendance";

type Props = ComponentProps<typeof AttendanceTablesPanel>;
const device: AttendanceDeviceStatus={id:1,deviceCode:"TEST",name:"合成端末",lastSeenAt:"2026-10-28T12:00:00+09:00",pendingOfflineCount:0,clockDriftSeconds:0,isActive:true};
function display(row: AttendanceDeviceStatus) {
  return render(<AttendanceTablesPanel activePanel="attendance-devices"
    data={{dashboard:{...emptyData,attendanceDevices:[row]},attendanceLoadError:""} as Props["data"]}
    filters={{} as Props["filters"]} actions={{} as Props["actions"]}
    formatters={{formatDateTime:(value?:string|null)=>value??"-"} as Props["formatters"]} onOpenDailyEditor={vi.fn()}/>);
}

describe("device diagnostics availability",()=>{
  it("shows schema migration pending separately and never labels an unsupported zero as sent",()=>{
    display({...device,diagnosticsSchemaReady:false});
    expect(screen.getByRole("cell",{name:"詳細診断未対応（DB更新待ち）"})).toBeVisible();
    expect(screen.getByRole("cell",{name:"未対応"})).toBeVisible();
    expect(screen.queryByRole("cell",{name:"正常"})).not.toBeInTheDocument();
    expect(screen.queryByRole("cell",{name:"0件"})).not.toBeInTheDocument();
  });
  it("keeps an installed schema without a first diagnostic heartbeat unconfirmed",()=>{
    display({...device,diagnosticsSchemaReady:true,pendingOfflineCount:null,clientTimeAt:null,clockDriftSeconds:null});
    expect(screen.getByRole("cell",{name:"端末診断は未確認"})).toBeVisible();
    expect(screen.queryByRole("cell",{name:/DB更新待ち/})).not.toBeInTheDocument();
    expect(screen.queryByRole("cell",{name:"0件"})).not.toBeInTheDocument();
  });
  it.each([true,undefined])("preserves received diagnostics for schemaReady=%s",schema=>{
    display({...device,diagnosticsSchemaReady:schema,clientTimeAt:"2026-10-28T12:00:00+09:00"});
    expect(screen.getByRole("cell",{name:"正常"})).toBeVisible();
    expect(screen.getByRole("cell",{name:"0件"})).toBeVisible();
  });
  it("keeps queue and clock warnings after diagnostics become available",()=>{
    display({...device,diagnosticsSchemaReady:true,pendingOfflineCount:3,clockDriftSeconds:125});
    const row=screen.getByRole("cell",{name:"合成端末"}).closest("tr")!;
    expect(row).toHaveClass("table-row-alert");
    expect(within(row).getByRole("cell",{name:"未送信 3件 / 時計ずれ 要確認"})).toBeVisible();
  });
});
