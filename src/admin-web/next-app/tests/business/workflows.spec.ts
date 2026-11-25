import { expect, test, type Page, type APIRequestContext } from '@playwright/test';
import { fillLoginCredentials } from "../helpers/login";
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const root=process.env.STAFFHUB_VERIFICATION_ROOT!;
const api=process.env.STAFFHUB_E2E_API_URL!;
const password='VerifyOnly-2026!';
test('休憩不足の始業・実休憩を確認して計上し日次一覧と月次に反映する',async({page,request})=>{
  const token=await login(page,'admin');
  const {breakReviewId:id,employeeId}=JSON.parse(await fs.readFile(path.join(root,'fixtures.json'),'utf8'));
  const detailPath='/admin/attendance/daily/'+id;
  const before=(await call(request,token,'GET',detailPath)).data;
  const badge=page.getByRole('navigation',{name:'管理メニュー'}).getByRole('button',{name:/日次勤怠.*打刻漏れ/});
  await badge.click();
  const row=page.locator('#attendance-attention').getByRole('row').filter({hasText:'2026-10-16'}).filter({hasText:'V132'});
  await row.getByRole('button',{name:'詳細・修正'}).click();
  const panel=page.getByRole('region',{name:'始業・休憩と時間外勤務の確認'});
  const calculate=panel.getByRole('button',{name:'確認内容で試算'});
  await expect(panel.getByLabel('採用する始業')).toHaveValue('RULE');
  await expect(panel.getByLabel('休憩の計上方法')).toHaveValue('REGULAR_PUNCH');
  await expect(panel).toContainText('予定・原打刻ルールでの試算');
  await expect(panel).toContainText('休憩 60分');
  await expect(calculate).toBeEnabled();
  await expect(panel.getByRole('button',{name:'この内容で勤怠に計上'})).toHaveCount(0);
  await panel.getByLabel('採用する始業').selectOption('ACTUAL');
  await panel.getByLabel('休憩の計上方法').selectOption('ACTUAL');
  await expect(calculate).toBeDisabled();
  await panel.getByLabel('実際の始業',{exact:true}).fill('07:56');
  await panel.getByRole('button',{name:/45分＋15分を取得/}).click();
  await calculate.click();
  await expect(panel).toContainText('実働 8:02');
  await expect(panel).toContainText('時間外 2分');
  const apply=panel.getByRole('button',{name:'この内容で勤怠に計上'});
  await expect(apply).toBeDisabled();
  const previewed=(await call(request,token,'GET',detailPath)).data;
  expect([previewed.clockInAt,previewed.breakMinutes,previewed.workMinutes,previewed.approvalStatus]).toEqual([before.clockInAt,45,497,before.approvalStatus]);
  for (const width of [390, 1440]) {
    await page.setViewportSize({width, height:900});
    const bounds = await panel.evaluate(element => {
      const rect = element.getBoundingClientRect();
      return {left:rect.left, right:rect.right, width:document.documentElement.clientWidth};
    });
    expect(bounds.left).toBeGreaterThanOrEqual(0);
    expect(bounds.right).toBeLessThanOrEqual(bounds.width + 1);
    await panel.screenshot({path:path.join(root,`break-overtime-preview-${width}.png`)});
  }
  const conflict='**/break-overtime/apply';
  await page.route(conflict,route=>route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({success:false,error:{code:'STALE_PREVIEW',message:'勤務記録が更新されました。再度試算してください。'}})}));
  await panel.getByRole('checkbox',{name:/一致することを確認/}).check();
  await apply.click();
  await expect(panel.getByRole('alert')).toBeVisible();
  await page.unroute(conflict);
  await calculate.click();
  await panel.getByRole('checkbox',{name:/一致することを確認/}).check();
  await apply.click();
  await expect(page.locator('.daily-edit-panel')).toContainText('確認済みの勤怠');
  await expect(row).toHaveCount(0);
  const saved=(await call(request,token,'GET',detailPath)).data;
  expect(saved).toMatchObject({rawClockInAt:before.rawClockInAt,rawClockOutAt:before.rawClockOutAt,breakMinutes:60,workMinutes:482,breakOvertimeReview:{overtimeMinutes:2}});
  await nav(page,'レポート');
  await page.getByLabel('対象月',{exact:true}).fill('2026-10');
  await page.getByLabel('職員別PDF 対象職員').selectOption(String(employeeId));
  await page.getByRole('button',{name:/個人別月次表示/}).click();
  const reportRow=page.getByRole('row').filter({has:page.getByRole('button',{name:/16 .*を修正/})});
  await expect(reportRow).toContainText('8:02');
  await expect(reportRow).toContainText('0:02');
});
async function login(page:Page,role:'admin'|'employee') {
  await page.goto('/dakoku/admin/');
  await fillLoginCredentials(page, `verify-${role}@example.test`, password);
  const response=page.waitForResponse(r=>r.url()===`${api}/api/auth/login`&&r.request().method()==='POST');
  await page.getByRole('button',{name:'ログインする',exact:true}).click();
  const result=await response; expect(result.status()).toBe(200);
  const json=await result.json();
  await expect(page.getByRole('button',{name:'ログアウト',exact:true}).first()).toBeVisible();
  return json.data.accessToken as string;
}
async function call(request:APIRequestContext,token:string,method:string,url:string,data?:unknown) {
  const response=await request.fetch(`${api}/api${url}`,{method,headers:{Authorization:`Bearer ${token}`,Accept:'application/json'},data});
  const json=await response.json();
  expect(response.ok(),JSON.stringify(json)).toBeTruthy();
  return json;
}
async function nav(page:Page,name:string) {
  const toggle=page.getByRole('button',{name:'管理メニューを開く',exact:true});
  if(await toggle.isVisible()) await toggle.click();
  const label = name === '日次勤怠' ? /^日次勤怠(?:\s+打刻漏れなど要確認の日次があります)?$/
    : name === '届出・有給管理' ? /^届出・有給管理(?:\s+未処理\s*\d+件)?$/ : name;
  await page.getByRole('navigation',{name:'管理メニュー'}).getByRole('button',{name:label,exact:true}).click();
}

test('日次勤怠は通常打刻の承認を不要とし、サイドバーの要確認から該当日を開く', async ({page, request}) => {
  const auth = await login(page, 'admin');
  const {employeeId} = JSON.parse(await fs.readFile(path.join(root, 'fixtures.json'), 'utf8'));
  const headers = {Authorization: `Bearer ${auth}`};
  const shift = await request.post(api + '/api/admin/attendance/shift-schedules', {headers, data: {employeeId, targetDate:'2026-08-12', scheduledClockInTime:'09:00', scheduledClockOutTime:'14:30'}});
  expect(shift.ok()).toBeTruthy();
  const daily = await request.get(api + '/api/admin/attendance/daily?employeeCode=V132&targetMonth=2026-08', {headers});
  expect((await daily.json()).data.find((r: {targetDate: string}) => r.targetDate === '2026-08-10')?.approvalStatus).toBe('NOT_REQUIRED');
  const queue = await request.get(api + '/api/admin/attendance/approvals?status=PENDING&perPage=100&page=1', {headers});
  const queueRows = (await queue.json()).data;
  expect(queueRows.some((r: {targetDate: string}) => r.targetDate === '2026-08-10')).toBe(false);
  expect(queueRows.find((r: {targetDate: string; employeeId:number}) => r.targetDate === '2026-08-12' && r.employeeId === employeeId)).toMatchObject({id: null});
  await page.reload();
  const badge = page.getByRole('navigation', {name:'管理メニュー'}).getByRole('button', {name:/日次勤怠.*打刻漏れ/});
  await expect(badge).toHaveText('日次勤怠！');
  await badge.click();
  const row = page.locator('#attendance-attention').getByRole('row').filter({hasText: '2026-08-12'}).filter({hasText: 'V132'});
  await expect(row).toContainText('勤務予定がありますが出退勤の記録がありません');
  await expect(row.getByRole('button', {name:'承認', exact:true})).toHaveCount(0);
  await row.getByRole('button', {name:'未登録の日次を作成'}).click();
  await page.getByRole('button', {name:'日次を作成して開く'}).click();
  const editor = page.locator('.daily-edit-panel');
  await expect(editor).toContainText('検証 職員');
  await expect(editor).toContainText('2026/8/12');
  await expect(editor.getByRole('button', {name:'編集',exact:true})).toBeEnabled();
});

test('職員ログインから有給申請・承認・台帳・勤怠・取消まで実APIで一致する',async({page,browser,request})=>{
  const employeeToken=await login(page,'employee');
  const adminContext=await browser.newContext(); const admin=await adminContext.newPage();
  const adminToken=await login(admin,'admin');
  const before=await call(request,employeeToken,'GET','/leave/ledger');
  expect(before.data.currentBalance).toBe(5);
  const created=await call(request,employeeToken,'POST','/leave/requests',{requestCategory:'LEAVE',leaveTypeCode:'PAID',startDate:'2026-07-10',endDate:'2026-07-10',dayUnit:'FULL',reason:'専用環境の検証'});
  expect(created.data.status).toBe('PENDING');
  const id=created.data.id;
  const approved=await call(request,adminToken,'POST',`/admin/work-procedures/${id}/approve`,{comment:'結合テスト'});
  expect(approved.data.status).toBe('APPROVED');
  const ledger=await call(request,employeeToken,'GET','/leave/ledger');
  expect(ledger.data.currentBalance).toBe(4);
  expect(ledger.data.items.some((row:{entryType:string})=>row.entryType==='USE')).toBeTruthy();
  const list=await call(request,employeeToken,'GET','/leave/requests');
  expect(list.data.find((row:{id:number})=>row.id===id).status).toBe('APPROVED');
  const attendance=await call(request,employeeToken,'GET','/attendance/daily?targetMonth=2026-07');
  expect(JSON.stringify(attendance.data)).toContain('2026-07-10');
  await page.reload(); await expect(page.getByRole('button',{name:'ログアウト',exact:true})).toBeVisible();
  await call(request,employeeToken,'POST',`/leave/requests/${id}/cancel`,{comment:'検証完了'});
  expect((await call(request,employeeToken,'GET','/leave/ledger')).data.currentBalance).toBe(5);
  await adminContext.close();
});

test('過去月の勤怠は一覧再読込・休暇申請・取消後に開き直しても同じ月を維持する', async ({ page }) => {
  await login(page, 'employee');
  await page.getByRole('button', { name: '勤怠確認', exact: true }).click();
  const month = page.getByLabel('対象月', { exact: true });
  const september = page.waitForResponse(r => r.url().includes('/api/attendance/daily?targetMonth=2026-09'));
  await month.fill('2026-09');
  expect((await september).ok()).toBeTruthy();
  await expect(page.locator('.attendance-edit-table tbody tr').first()).toContainText('2026-09-01');
  const reloaded = page.waitForResponse(r => r.url().includes('/api/attendance/daily?targetMonth=2026-09'));
  await page.getByRole('button', { name: '最新情報を再読込', exact: true }).click();
  expect((await reloaded).ok()).toBeTruthy();
  await expect(month).toHaveValue('2026-09');
  await expect(page.locator('.attendance-edit-table tbody tr')).toHaveCount(30);

  await page.getByRole('button', { name: '休暇申請', exact: true }).click();
  await page.getByLabel('開始日', { exact: true }).fill('2026-09-15');
  await page.getByLabel('終了日', { exact: true }).fill('2026-09-15');
  await expect(page.getByRole('button', { name: 'この内容で申請する', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'この内容で申請する', exact: true }).click();
  const created = page.waitForResponse(r => r.url().endsWith('/api/leave/requests') && r.request().method() === 'POST');
  await page.getByRole('button', { name: '休暇を申請', exact: true }).click();
  expect((await created).ok()).toBeTruthy();
  const afterCreate = page.waitForResponse(r => r.url().includes('/api/attendance/daily?targetMonth=2026-09'));
  await page.getByRole('button', { name: '勤怠確認', exact: true }).click();
  expect((await afterCreate).ok()).toBeTruthy();
  await expect(month).toHaveValue('2026-09');
  await expect(page.locator('.attendance-edit-table tbody tr')).toHaveCount(30);

  await page.getByRole('button', { name: '申請状況', exact: true }).click();
  await page.getByRole('row').filter({ hasText: '2026/9/15' }).getByRole('button', { name: '取消', exact: true }).click();
  const cancelled = page.waitForResponse(r => /\/api\/leave\/requests\/\d+\/cancel$/.test(r.url()) && r.request().method() === 'POST');
  await page.getByRole('button', { name: '申請を取り消す', exact: true }).click();
  expect((await cancelled).ok()).toBeTruthy();
  const afterCancel = page.waitForResponse(r => r.url().includes('/api/attendance/daily?targetMonth=2026-09'));
  await page.getByRole('button', { name: '勤怠確認', exact: true }).click();
  expect((await afterCancel).ok()).toBeTruthy();
  await expect(month).toHaveValue('2026-09');
  await expect(page.locator('.attendance-edit-table tbody tr')).toHaveCount(30);
});

test('給与・賞与CSVのプレビュー照合、登録、本人表示、実ファイル保存',async({page,browser,request})=>{
  const adminToken=await login(page,'admin');
  const employeeContext=await browser.newContext(); const employee=await employeeContext.newPage();
  const employeeToken=await login(employee,'employee');
  const evidence=[];
  for(const statementType of ['PAYROLL','BONUS']) {
    const file={name:statementType.toLowerCase()+'.csv',mimeType:'text/csv',buffer:await fs.readFile(path.join(root,statementType.toLowerCase()+'.csv'))};
    const payload={statementType,targetYearMonth:'2026-07',periodStartOn:'2026-07-01',periodEndOn:'2026-07-31',payDate:'2026-07-25',publishDate:'2026-07-25',file};
    const preview=await request.post(`${api}/api/admin/payroll/import-batches/preview`,{headers:{Authorization:`Bearer ${adminToken}`},multipart:payload});
    expect(preview.ok(),await preview.text()).toBeTruthy(); const checked=(await preview.json()).data;
    expect(checked.importedCount).toBe(1); expect(checked.errorCount).toBe(0);
    const changed=await request.post(`${api}/api/admin/payroll/import-batches`,{headers:{Authorization:`Bearer ${adminToken}`},multipart:{...payload,targetYearMonth:'2026-08',previewFingerprint:checked.previewFingerprint}});
    expect(changed.status()).toBe(409);
    const committed=await request.post(`${api}/api/admin/payroll/import-batches`,{headers:{Authorization:`Bearer ${adminToken}`},multipart:{...payload,previewFingerprint:checked.previewFingerprint}});
    expect(committed.ok(),await committed.text()).toBeTruthy();const result=(await committed.json()).data;
    expect(result.importedCount).toBe(1); expect(result.errorCount).toBe(0);
    const statements=await call(request,employeeToken,'GET','/payroll/statements');
    const statement=statements.data.find((row:{statementType:string})=>row.statementType===statementType); expect(statement).toBeTruthy();
    const detail=await call(request,employeeToken,'GET',`/payroll/statements/${statement.id}`);
    expect(detail.data.targetYearMonth).toBe('2026-07'); expect(detail.data.employeeCode).toBe('V132');
    // Fetch through the same authenticated download endpoint and save the actual response bytes.
    const pdf=await request.get(`${api}/api/payroll/statements/${statement.id}/download`,{headers:{Authorization:`Bearer ${employeeToken}`}});
    expect(pdf.ok()).toBeTruthy();const bytes=await pdf.body(); expect(bytes.subarray(0,5).toString()).toBe('%PDF-');
    const filePath=path.join(root,'downloads',statementType+'.pdf'); await fs.writeFile(filePath,bytes);
    evidence.push({statementType,statementId:statement.id,targetMonth:'2026-07',employeeCode:'V132',grossAmount:detail.data.grossAmount,netAmount:detail.data.netAmount,path:filePath,size:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex')});
  }
  const notices=await call(request,employeeToken,'GET','/notifications');
  expect(notices.data.filter((n:{title:string})=>/明細/.test(n.title))).toHaveLength(2);
  await fs.writeFile(path.join(root,'pdf-evidence.json'),JSON.stringify(evidence,null,2));
  await employee.reload();
  await employee.getByRole('button',{name:'給与明細',exact:true}).click();
  const view=employee.getByRole('button',{name:/明細を見る/}).first(); await expect(view).toBeVisible();await view.click();
  await expect(employee.locator('.payroll-detail-card')).toBeVisible();
  for(const width of [360,390,768,1024,1440]) {
    await employee.setViewportSize({width,height:1000});
    await expect.poll(()=>employee.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
    await expect.poll(()=>employee.locator('.payroll-detail-card .table-wrap').evaluateAll(nodes=>nodes.every(node=>node.scrollWidth<=node.clientWidth+1))).toBeTruthy();
    await employee.screenshot({path:path.join(root,`employee-payroll-${width}.png`),fullPage:true,animations:'disabled'});
    await employee.locator('.payroll-detail-card').screenshot({path:path.join(root,`employee-payroll-detail-${width}.png`),animations:'disabled'});
  }
  const downloadButton=employee.getByRole('button',{name:/PDF/}).first();
  if(await downloadButton.isVisible()) {
    const downloadEvent=employee.waitForEvent('download'); await downloadButton.click();const download=await downloadEvent;
    await download.saveAs(path.join(root,'downloads','browser-payroll.pdf'));expect(await download.failure()).toBeNull();
  } else { throw new Error('本人画面のPDF保存ボタンが見つかりません'); }
  await employeeContext.close();
});

test('管理画面の5画面幅と過去月検索、通信断時の月締め禁止',async({page})=>{
  await login(page,'admin'); await nav(page,'日次勤怠');
  await page.getByRole('button',{name:'検索条件',exact:true}).click();
  await page.getByRole('textbox',{name:'対象月',exact:true}).fill('2026-07');
  await page.getByRole('textbox',{name:'職員番号',exact:true}).fill('V132');
  const searched=page.waitForResponse(r=>r.url().includes('/api/admin/attendance/daily-grid?')&&r.url().includes('employeeCode=V132')&&r.url().includes('targetMonth=2026-07'));
  await page.getByRole('button',{name:'条件で再表示',exact:true}).click();
  expect((await searched).ok()).toBeTruthy();
  await expect(page.getByRole('region',{name:'読み込み中'})).not.toBeVisible();
  await expect(page.getByRole('button',{name:'条件で再表示',exact:true})).toBeEnabled();
  expect(await page.getByRole('textbox',{name:'打刻開始日',exact:true}).inputValue()).toBe('2026-07-01');
  for(const width of [360,390,768,1024,1440]) {
    await page.setViewportSize({width,height:1000});
    await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
    await expect(page.getByRole('button',{name:'ログアウト',exact:true}).first()).toBeVisible();
    await page.screenshot({path:path.join(root,`admin-attendance-${width}.png`),fullPage:true,animations:'disabled'});
  }
  await page.getByRole('button',{name:'出力',exact:true}).click();
  await page.getByRole('button',{name:'日次PDF',exact:true}).click();
  await expect(page.getByRole('dialog')).toContainText('職員番号: V132');
  const pdfResponse=page.waitForResponse(r=>r.url().includes('/api/admin/reports/daily-pdf?'));
  const savedPdf=page.waitForEvent('download');
  await page.getByRole('button',{name:'出力する',exact:true}).click();
  const response=await pdfResponse;expect(new URL(response.url()).searchParams.get('employeeCode')).toBe('V132');
  const downloaded=await savedPdf;await downloaded.saveAs(path.join(root,'downloads','browser-attendance.pdf'));
  expect(await downloaded.failure()).toBeNull();
  await page.getByRole('button',{name:'検索条件',exact:true}).click();
  await page.route('**/api/admin/attendance/daily-grid**',route=>route.abort('internetdisconnected'));
  await page.getByRole('button',{name:'条件で再表示',exact:true}).click();
  await expect(page.getByText('勤怠の取得に失敗しました。',{exact:true})).toBeVisible();
  await page.getByRole('button',{name:'月締め',exact:true}).click();
  await expect(page.getByRole('button',{name:'この月を締める',exact:true})).toBeDisabled();
});

test('初回10月の応答が9月検索後に届いても9月の一覧と締め対象を維持する',async({page,request})=>{
  await page.clock.setFixedTime(new Date('2026-10-10T12:00:00+09:00'));
  const token=await login(page,'admin');
  const fixture=JSON.parse(await fs.readFile(path.join(root,'fixtures.json'),'utf8'));
  for(const targetDate of ['2026-09-02','2026-10-02']) {
    const created=await call(request,token,'POST','/admin/attendance/daily',{employeeId:fixture.employeeId,targetDate});
    await call(request,token,'PATCH',`/admin/attendance/daily/${created.data.id}`,{clockInTime:'09:00',clockOutTime:'18:00',breaks:[{startTime:'12:00',endTime:'13:00'}]});
  }
  let release!:()=>void;
  let captured!:()=>void;
  const held=new Promise<void>(resolve=>{release=resolve;});
  const initialRequest=new Promise<void>(resolve=>{captured=resolve;});
  let firstOctober=true;
  await page.route('**/api/admin/attendance/daily-grid?**',async route=>{
    if(new URL(route.request().url()).searchParams.get('targetMonth')!=='2026-10'||!firstOctober) {await route.continue();return;}
    firstOctober=false;
    const response=await route.fetch();
    captured();
    await held;
    await route.fulfill({response});
  });
  try {
    await nav(page,'日次勤怠');
    await initialRequest;
    await page.getByRole('button',{name:'検索条件',exact:true}).click();
    const month=page.getByRole('textbox',{name:'対象月',exact:true});
    await expect(month).toHaveValue('2026-10');
    const september=page.waitForResponse(response=>response.url().includes('/api/admin/attendance/daily-grid?')&&new URL(response.url()).searchParams.get('targetMonth')==='2026-09');
    await month.fill('2026-09');
    expect((await september).ok()).toBeTruthy();
    await page.getByRole('button',{name:'日次一覧',exact:true}).click();
    const table=page.locator('#attendance-daily-list');
    await expect(table.getByRole('cell',{name:'2026/9/2',exact:true})).toBeVisible();
    const lateOctober=page.waitForResponse(response=>response.url().includes('/api/admin/attendance/daily-grid?')&&new URL(response.url()).searchParams.get('targetMonth')==='2026-10');
    release();expect((await lateOctober).ok()).toBeTruthy();
    await expect(page.getByRole('region',{name:'読み込み中'})).not.toBeVisible();
    await expect(table.getByRole('cell',{name:'2026/9/2',exact:true})).toBeVisible();
    await expect(table.getByRole('cell',{name:'2026/10/2',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'検索条件',exact:true}).click();
    await expect(month).toHaveValue('2026-09');
    await expect(page.getByRole('textbox',{name:'打刻開始日',exact:true})).toHaveValue('2026-09-01');
    await page.getByRole('button',{name:'月締め',exact:true}).click();
    await expect(page.locator('#attendance-close')).toContainText('対象月: 2026-09');
  } finally {release();}
});

test('レポートから選択月の月次締めへ移動し、再訪時も古い月や職員条件を引き継がない',async({page})=>{
  await login(page,'admin');
  await nav(page,'レポート');
  await page.getByLabel('対象月',{exact:true}).fill('2026-09');
  for (const width of [390,1440]) {
    await page.setViewportSize({width,height:1000});
    await expect.poll(() => page.locator('.report-output-conditions').evaluate((panel) => [...panel.querySelectorAll('input,select')].every((control) => {
      const rect=control.getBoundingClientRect();
      return rect.left>=0 && rect.right<=window.innerWidth;
    }))).toBeTruthy();
    await expect.poll(() => page.locator('.report-hub-grid').evaluate((grid) => [...grid.children].every((column) => column.getBoundingClientRect().right<=window.innerWidth))).toBeTruthy();
    await page.screenshot({path:path.join(root,`reports-month-close-entry-${width}.png`),fullPage:true,animations:'disabled'});
  }
  await page.getByRole('button',{name:/^月次締めへ/}).click();
  await expect(page.locator('#attendance-close')).toContainText('対象月: 2026-09');
  await expect(page.getByRole('region',{name:'読み込み中'})).not.toBeVisible();
  await page.getByRole('button',{name:'検索条件',exact:true}).click();
  await expect(page.getByLabel('対象月',{exact:true})).toHaveValue('2026-09');
  await page.getByLabel('職員番号',{exact:true}).fill('V132');
  await nav(page,'レポート');
  await page.getByLabel('対象月',{exact:true}).fill('2026-10');
  await page.getByRole('button',{name:/月次レポート\s*月ごとの/}).click();
  await page.getByRole('button',{name:'この月の月次締めへ',exact:true}).click();
  await expect(page.locator('#attendance-close')).toContainText('対象月: 2026-10');
  await expect(page.getByRole('region',{name:'読み込み中'})).not.toBeVisible();
  await page.screenshot({path:path.join(root,'reports-to-month-close.png'),fullPage:true,animations:'disabled'});
  await page.getByRole('button',{name:'検索条件',exact:true}).click();
  await expect(page.getByLabel('対象月',{exact:true})).toHaveValue('2026-10');
  await expect(page.getByLabel('職員番号',{exact:true})).toHaveValue('');
  await expect(page.getByLabel('打刻開始日',{exact:true})).toHaveValue('2026-10-01');
});

test('職員の戻る・進むで下書きを保持し、再読込で同じタブを開く',async({page})=>{
  await login(page,'employee');
  const menu=page.getByRole('navigation',{name:'職員画面メニュー'});
  await menu.getByRole('button',{name:'休暇申請',exact:true}).click();
  await page.getByLabel('申請理由').fill('架空の下書き');
  await menu.getByRole('button',{name:'勤怠確認',exact:true}).click();
  await page.goBack();
  await expect(page.getByLabel('申請理由')).toHaveValue('架空の下書き');
  await page.goForward();
  await expect(menu.getByRole('button',{name:'勤怠確認',exact:true})).toHaveAttribute('aria-current','page');
  await page.reload();
  await expect(menu.getByRole('button',{name:'勤怠確認',exact:true})).toHaveAttribute('aria-current','page');
  await page.getByRole('button',{name:'ログアウト',exact:true}).click();
  await expect(page.getByRole('heading',{name:'ログイン情報'})).toBeVisible();
  await login(page,'employee');
  await expect(menu.getByRole('button',{name:'ホーム',exact:true})).toHaveAttribute('aria-current','page');
});

test('職員のホーム取得失敗を表示して再認証なしで復帰する',async({page})=>{
  await login(page,'employee');
  const todayHome = /\/api\/mobile\/home\?view=today$/;
  await page.route(todayHome,route=>route.fulfill({
    status:503,contentType:'application/json',body:JSON.stringify({error:{message:'職員ホームの試験障害'}}),
  }));
  await page.reload();
  await expect(page.getByRole('heading',{name:'職員ホーム',exact:true})).toBeVisible();
  await expect(page.getByRole('alert').filter({hasText:'職員ホームの試験障害'})).toBeVisible();
  await expect(page.getByLabel('パスワード',{exact:true})).toHaveCount(0);
  await page.unroute(todayHome);
  await page.getByRole('button',{name:'再試行',exact:true}).click();
  await expect(page.getByRole('navigation',{name:'職員画面メニュー'})).toBeVisible();
});

test('年次有給休暇管理簿を両入口から表示し、退職者・実API・印刷・CSVの対象が一致する', async ({ page, request }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', error => pageErrors.push(error.message));
  const auth = await login(page, 'admin');
  const {employeeId} = JSON.parse(await fs.readFile(path.join(root, 'fixtures.json'), 'utf8'));
  await fs.access(path.join(root, '.staffhub-verification'));
  const privateRegister = path.join(root, 'storage/app/private/paid-leave-register');
  await fs.mkdir(privateRegister, {recursive: true});
  const openingBalance = {date: '2026-04-01', days: 35, totalMinutes: 4800, annualMinutes: 2400, sourceId: 's', sourceRow: 2};
  const hourlyUse = {date: '2026-07-14', kind: 'USE', days: 0, minutes: 120, dayChange: -0.5, minutesChange: -120,
    afterDays: 34.5, afterTotalMinutes: 4680, afterAnnualMinutes: 2280, sourceId: 's', sourceRow: 3};
  const headers = { Authorization: 'Bearer ' + auth };
  const file = {name: 'register-retired.csv', mimeType: 'text/csv', buffer: Buffer.from('社員番号,姓,名,状態,入職日,退職日\nVREG9,架空,退職者,退職,2026-05-01,2026-09-30\n')};
  const preview = await request.post(api + '/api/admin/employees/import-csv/preview', {headers, multipart: {file}});
  expect(preview.ok()).toBeTruthy();
  const imported = await request.post(api + '/api/admin/employees/import-csv', {headers, multipart: {file, previewFingerprint: (await preview.json()).data.previewFingerprint}});
  expect(imported.ok()).toBeTruthy();
  await nav(page, 'レポート');
  await page.getByRole('button', {name: '年次有給休暇管理簿', exact: true}).click();
  const target = page.getByLabel('対象職員（退職者を含む）');
  const retiredOption = target.getByRole('option', {name: /VREG9.+退職/});
  await expect(retiredOption).toHaveCount(1);
  const retiredId = Number(await retiredOption.getAttribute('value'));
  expect(retiredId).toBeGreaterThan(0);
  await fs.writeFile(path.join(privateRegister, 'official-history.json'), JSON.stringify({
    schemaVersion: 1, asOf: '2026-11-24', sources: {s: {file: 'synthetic.csv', sha256: 'a'.repeat(64), asOf: '2026-11-24'}},
    employees: {
      V132: {employeeId, years: {'2026': {sourceId: 's', opening: openingBalance, coveredThrough: '2026-11-24', entries: [hourlyUse]}}},
      VREG9: {employeeId: retiredId, years: {'2026': {sourceId: 's', openingReason: 'NOT_EMPLOYED', coveredThrough: '2026-09-30', entries: []}}},
    },
  }));
  await page.getByLabel('開始日', {exact: true}).fill('2026-07-01');
  await page.getByLabel('終了日', {exact: true}).fill('2026-07-31');
  await expect(page.getByRole('button', {name: '管理簿を表示'})).toHaveCount(0);
  const responsePromise = page.waitForResponse(r => r.url().includes('/reports/paid-leave-register?') && r.url().includes('to=2026-07-31'));
  await target.selectOption({label: 'V132 ／ 検証 職員'});
  const response = await responsePromise;
  expect(response.ok()).toBeTruthy();
  const report = (await response.json()).data;
  const article = page.getByRole('article', {name: '検証 職員の年次有給休暇管理簿'});
  await expect(article).toBeVisible();
  const year = report.fiscalLedger.years[0];
  expect(year.openingDate).toBe('2026-04-01');
  expect(year.opening).toMatchObject({days: 35, totalMinutes: 4800, annualMinutes: 2400});
  const details = article.getByRole('table', {name: '2026年度　取得・残数の明細'});
  await expect(details).toHaveCount(1);
  await expect(article.locator('dl')).toHaveCount(0);
  await expect(article).not.toContainText(/期間内に記帳された取得|年度開始の残は4月1日時点|4月1日の調整後残は未確認/);
  await expect(article.getByText('4月1日・調整後の残', {exact: true})).toHaveCount(1);
  await expect(details.locator('tbody tr')).toHaveCount(3);
  const opening = article.getByRole('row', {name: /4月1日・調整後の残/});
  await expect(opening).toContainText(year.openingDate);
  await expect(opening.getByRole('cell').nth(1)).toHaveText(year.opening.days === null ? '未確認' : `${year.opening.days}日`);
  await expect(details.getByRole('columnheader', {name: '区分', exact: true})).toHaveCount(0);
  await expect(opening.getByRole('rowheader')).toContainText('4月1日・調整後の残');
  await expect(article.getByRole('columnheader', {name: '日単位', exact: true})).toBeVisible();
  await expect(article.getByRole('columnheader', {name: '時間単位', exact: true})).toBeVisible();
  const use = article.getByRole('row', {name: /2026-07-14/});
  await expect(use).toContainText('取得 2時間');
  await expect(use).toContainText('増減 -0.5日');
  await expect(use).toContainText('34.5日');
  await expect(use).toContainText('78時間');
  expect(await use.locator('th,td').allTextContents()).toEqual(['2026-07-14','増減 -0.5日','34.5日','取得 2時間','38時間','78時間']);
  const closing = article.getByRole('row', {name: /集計日の残/});
  await expect(closing).toContainText('2026-07-31');
  await expect(closing).toContainText('34.5日');
  await expect(closing).toContainText('38時間');
  await expect(closing).toContainText('78時間');
  await expect(article).not.toContainText(/原簿との照合|基準日・付与の記録|根拠・記録元/);
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', {name: '管理簿CSVを保存'}).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toContain('V132_2026-07-01_2026-07-31.csv');
  const csvPath = path.join(root, download.suggestedFilename());
  await download.saveAs(csvPath);
  const csv = await fs.readFile(csvPath);
  expect(csv.subarray(0, 3)).toEqual(Buffer.from([0xef, 0xbb, 0xbf]));
  expect(csv.toString('utf8')).toContain(report.employee.name);
  const csvRows = csv.toString('utf8').split(/\r?\n/);
  expect(csvRows.filter(line => line.includes('4月1日・調整後の残'))).toEqual([
    '"2026-04-01","4月1日・調整後の残","","","35","","","2400","4800",""',
  ]);
  expect(csvRows.find(line => line.startsWith('"日付"'))).toBe('"日付","記帳区分","日単位の取得（日）","日数の増減（日）","日数残（日）","時間単位の取得（分）","時間の増減（分）","時間有給の年度枠残（分）","時間有給の総残（分）","年度開始残への反映"');
  expect(csvRows.filter(line => line.startsWith('"2026-07-14"'))).toEqual([
    '"2026-07-14","記帳済","0","-0.5","34.5","120","-120","2280","4680",""',
  ]);
  expect(csvRows.filter(line => line.includes('集計日の残'))).toHaveLength(1);
  expect(csv.toString('utf8')).toContain('"2026-07-31","集計日の残","","","34.5","","","2280","4680",""');
  expect(csv.toString('utf8')).not.toMatch(/照合原票|記録番号|基準日・付与の記録|期間内に記帳された取得|参考の取得（集計外・件）|年度開始の残は4月1日時点|記録の範囲/);
  await page.getByRole('status').filter({hasText: 'ダウンロードを開始しました'}).getByRole('button', {name: '閉じる'}).click();
  for (const width of [390, 768, 1366]) {
    await page.setViewportSize({width, height: 900});
    await expect(article).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
    if (width === 390 || width === 1366) {
      await article.screenshot({path: path.join(root, `annual-register-${width}.png`), animations: 'disabled'});
    }
    if (width === 390) {
      const table = article.getByRole('region', {name: '2026年度の明細表'});
      await table.focus();
      await table.press('ArrowRight');
      await expect.poll(() => table.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
    }
  }
  await page.evaluate(() => {
    const print = window.print.bind(window);
    window.print = () => { document.documentElement.dataset.registerPrint = 'requested'; print(); };
  });
  await page.getByRole('button', {name: '管理簿を印刷'}).click();
  await expect(page.locator('html')).toHaveAttribute('data-register-print', 'requested');
  await page.emulateMedia({media: 'print'});
  await expect(article).toBeVisible();
  const printTable = article.getByRole('region', {name: '2026年度の明細表'});
  await printTable.focus();
  await expect(printTable).toHaveCSS('outline-style', 'none');
  await expect(printTable.getByRole('columnheader', {name: '区分', exact: true})).toHaveCount(0);
  await expect(printTable.getByRole('rowheader', {name: /集計日の残/})).toContainText('2026-07-31');
  await expect(target).toBeHidden();
  await expect(page.getByRole('button', {name: 'ログアウト', exact: true}).first()).toBeHidden();
  const pdf = await page.pdf({format: 'A4', path: path.join(root, 'annual-paid-leave-register.pdf')});
  expect(pdf.subarray(0, 4).toString()).toBe('%PDF');
  await page.emulateMedia({media: 'screen'});
  const changedPeriod = page.waitForResponse(r => r.url().includes('/reports/paid-leave-register?') && r.url().includes('to=2026-07-20'));
  await page.getByLabel('終了日', {exact: true}).fill('2026-07-20');
  await changedPeriod;
  await expect(article).toContainText('2026-07-20');
  const noTransactions = page.waitForResponse(r => r.url().includes('/reports/paid-leave-register?') && r.url().includes('from=2026-07-15'));
  await page.getByLabel('開始日', {exact: true}).fill('2026-07-15');
  await noTransactions;
  await expect(details.locator('tbody tr')).toHaveCount(2);
  await expect(opening).toBeVisible();
  await expect(article).not.toContainText('選択期間内に表示できる取得・増減はありません');
  await expect(closing).toContainText('2026-07-20');
  await expect(closing).toContainText('34.5日');
  await expect(closing).toContainText('78時間');
  await page.getByLabel('開始日', {exact: true}).fill('2026-03-31');
  const previousYear = article.getByRole('table', {name: '2025年度　取得・残数の明細'});
  await expect(previousYear.getByRole('row', {name: /4月1日・調整後の残/})).toHaveCount(1);
  await expect(previousYear.getByRole('row', {name: /集計日の残/})).toContainText('2026-03-31');
  await expect(details.getByRole('row', {name: /4月1日・調整後の残/})).toHaveCount(1);
  await expect(details.getByRole('row', {name: /集計日の残/})).toContainText('2026-07-20');
  await target.selectOption({label: 'VREG9 ／ 架空 退職者（退職）'});
  await expect(article).toHaveCount(0);
  const retiredReport = page.getByRole('article', {name: '架空 退職者の年次有給休暇管理簿'});
  await expect(retiredReport).toBeVisible();
  await expect(retiredReport.getByRole('row', {name: /4月1日・調整後の残/}).getByRole('cell', {name: '入職前', exact: true})).toHaveCount(3);
  await nav(page, '届出・有給管理');
  await page.getByRole('button', {name: '年次有給休暇管理簿', exact: true}).click();
  await expect(target).toBeVisible();
  await expect(page.getByRole('article')).toHaveCount(0);
  expect(pageErrors).toEqual([]);
});

