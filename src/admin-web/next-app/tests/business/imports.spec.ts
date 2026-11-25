import {test,expect} from '@playwright/test';
const api=process.env.STAFFHUB_E2E_API_URL!;
test('職員CSV・HARMOSの実取込と再実行で重複や残数の増加が起きない',async({request})=>{
 const login=await request.post(api+'/api/auth/login',{data:{loginId:'verify-admin@example.test',password:'VerifyOnly-2026!',audience:'ADMIN'}});
 expect(login.ok()).toBeTruthy();const headers={Authorization:`Bearer ${(await login.json()).data.accessToken}`};
 const employeeFile={name:'employees.csv',mimeType:'text/csv',buffer:Buffer.from('社員番号,姓,名\nV900,検証,取込\n')};
 const preview=await request.post(api+'/api/admin/employees/import-csv/preview',{headers,multipart:{file:employeeFile}});
 expect(preview.ok(),await preview.text()).toBeTruthy();const plan=(await preview.json()).data;
 for(let i=0;i<2;i++){
  const imported=await request.post(api+'/api/admin/employees/import-csv',{headers,multipart:{file:employeeFile,previewFingerprint:plan.previewFingerprint}});
  expect(imported.ok(),await imported.text()).toBeTruthy();expect((await imported.json()).data.createdCount).toBe(i===0?1:0);
 }
 const employees=await request.get(api+'/api/admin/employees?employeeCode=V900',{headers});expect((await employees.json()).data).toHaveLength(1);
 const monthUrl=api+'/api/admin/attendance/daily?targetMonth=2026-08&employeeCode=V132';
 const originalRows=(await (await request.get(monthUrl,{headers})).json()).data as Array<{id:number;targetDate:string;rawClockInAt:string|null;rawClockOutAt:string|null}>;
 const cases=[
  {importType:'HARMOS_ATTENDANCE_DAILY_CSV',csv:'社員番号,日付,出勤時刻,退勤時刻,休憩時間,実働時間,有給\nV132,2026/08/03,09:00,18:00,60,480,0\n'},
  {importType:'HARMOS_PAID_LEAVE_BALANCE_CSV',csv:'社員番号,有給残数,有効期限\nV900,12.5,2027/03/31\n'},
 ];
 for(const item of cases){
  const file={name:'harmos.csv',mimeType:'text/csv',buffer:Buffer.from(item.csv)};
  const checked=await request.post(api+'/api/admin/harmos-migration/preview',{headers,multipart:{importType:item.importType,file}});
  expect(checked.ok(),await checked.text()).toBeTruthy();const planned=(await checked.json()).data;
  expect(planned.successCount).toBe(1);
  for(let i=0;i<2;i++){
   const result=await request.post(api+'/api/admin/harmos-migration/import',{headers,multipart:{importType:item.importType,file,previewFingerprint:planned.previewFingerprint}});
   expect(result.ok(),await result.text()).toBeTruthy();expect((await result.json()).data.errorCount).toBe(0);
  }
 }
 const attendance=await request.get(monthUrl,{headers});
 const monthRows=(await attendance.json()).data as typeof originalRows;
 expect(monthRows.filter(row=>row.targetDate==='2026-08-03')).toHaveLength(1);
 expect(monthRows).toHaveLength(originalRows.filter(row=>row.targetDate!=='2026-08-03').length+1);
 for(const row of originalRows.filter(item=>item.targetDate!=='2026-08-03')){
   expect(monthRows.find(item=>item.id===row.id)).toMatchObject({targetDate:row.targetDate,rawClockInAt:row.rawClockInAt,rawClockOutAt:row.rawClockOutAt});
 }
 const balance=await request.get(api+'/api/admin/reports/paid-leave',{headers});
 const rows=(await balance.json()).data;expect(JSON.stringify(rows)).toContain('12.5');
});
