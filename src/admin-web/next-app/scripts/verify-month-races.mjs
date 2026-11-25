import fs from 'node:fs/promises';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const root=process.argv[2]&&path.resolve(process.argv[2]);
if(!root)throw new Error('Provide the isolated E2E evidence directory');
const saved=JSON.parse(await fs.readFile(path.join(root,'test-environment.json'),'utf8'));
if(saved.STAFFHUB_E2E!=='true'||saved.DB_CONNECTION!=='mysql'||path.resolve(saved.STAFFHUB_VERIFICATION_ROOT)!==root||!saved.DB_DATABASE.startsWith('staffhub_verification_'))throw new Error('Only isolated MySQL evidence is accepted');
const backend=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../../backend-laravel-app');
const php=process.env.STAFFHUB_PHP??(process.platform==='win32'?'C:/php/php.exe':'php');
const phpArgs=process.platform==='win32'?['-d','extension=pdo_mysql']:[];
function start(args){return new Promise((resolve,reject)=>{const child=spawn(php,[...phpArgs,'scripts/verification/month-race.php',...args],{cwd:backend,env:{...process.env,...saved},windowsHide:true,stdio:['ignore','pipe','pipe']});let log='';child.stdout.on('data',b=>log+=b);child.stderr.on('data',b=>log+=b);child.on('error',reject);child.on('exit',code=>code===0?resolve():reject(new Error(log)));});}
async function waitFor(file){const deadline=Date.now()+15000;while(Date.now()<deadline){try{await fs.access(file);return;}catch{}await new Promise(r=>setTimeout(r,50));}throw new Error('Barrier not acquired');}
const cases=[['close-first','close','write'],['write-first','write','close'],['candidate-close-first','close','approve'],['candidate-approve-first','approve','close'],['candidate-edit-first','edit','approve'],['candidate-request-first','request','approve'],['shift-close-first','close','shift'],['shift-save-first','shift','close']];
// Only known barrier/result files inside the explicitly supplied evidence directory are removed.
for(const [name,first,second] of cases)for(const suffix of ['locked','release',`${first}.json`,`${second}.json`]){
 const file=path.resolve(root,`${name}.${suffix}`);if(path.dirname(file)!==root)throw new Error('Invalid case evidence path');
 await fs.unlink(file).catch(error=>{if(error.code!=='ENOENT')throw error;});
}
await start(['--setup']);
await fs.access(path.join(root,'race-fixtures.json'));
const results=[];
for(const [name,firstRole,secondRole] of cases){
 const first=start([`--case=${name}`,`--role=${firstRole}`]);
 await Promise.race([waitFor(path.join(root,name+'.locked')),first.then(()=>{throw new Error(`${name}: first writer exited before acquiring the barrier`);})]);
 const second=start([`--case=${name}`,`--role=${secondRole}`]);
 await new Promise(r=>setTimeout(r,600));
 await assert.rejects(fs.access(path.join(root,`${name}.${secondRole}.json`)));
 await fs.writeFile(path.join(root,name+'.release'),'1');
 await Promise.all([first,second]);
 const one=JSON.parse(await fs.readFile(path.join(root,`${name}.${firstRole}.json`),'utf8'));
 const two=JSON.parse(await fs.readFile(path.join(root,`${name}.${secondRole}.json`),'utf8'));
 assert.equal(one.status,'COMMITTED');
 if(name==='shift-close-first'){
   assert.equal(two.status,'REJECTED');assert.equal(two.code,'CLOSED_PERIOD');assert.equal(two.monthStatus,'CLOSED');
   assert.equal(two.shiftClockIn,'09:00:00');assert.equal(two.dailyApproval,'APPROVED');
   assert.equal(two.dailyClockIn,'2025-05-10 09:00:00');assert.equal(two.dailyRawClockIn,null);
 } else if(name==='shift-save-first'){
   assert.equal(one.shiftClockIn,'10:00:00');assert.equal(two.status,'COMMITTED');assert.equal(two.monthStatus,'CLOSED');
   assert.equal(two.shiftClockIn,'10:00:00');assert.equal(two.dailyApproval,'APPROVED');
   assert.equal(two.dailyClockIn,'2025-06-10 09:00:00');assert.equal(two.dailyRawClockIn,null);
 } else if(name==='close-first'||name==='write-first'){
   assert.equal(two.status,'REJECTED'); assert.equal(two.code,name==='close-first'?'CLOSED_PERIOD':'VALIDATION_ERROR');
   assert.equal(two.monthStatus,name==='close-first'?'CLOSED':'OPEN');
 } else if(name==='candidate-approve-first'){
   assert.equal(one.bulk.updatedCount,1);assert.equal(one.bulk.errorCount,0);
   assert.equal(two.status,'COMMITTED');assert.equal(two.monthStatus,'CLOSED');assert.equal(two.dailyApproval,'APPROVED');
 } else {
   assert.equal(two.status,'COMMITTED');assert.equal(two.bulk.updatedCount,0);assert.equal(two.bulk.skippedCount,1);
   assert.equal(two.bulk.errors[0].code,name==='candidate-close-first'?'CLOSED_PERIOD':'STALE_PREVIEW');
   assert.equal(two.dailyApproval,name==='candidate-close-first'?'APPROVED':'PENDING');
 }
 results.push({case:name,first:one,second:two});
}
await fs.writeFile(path.join(root,'month-race-results.json'),JSON.stringify(results,null,2));console.log('All eight MySQL month-close / approval / shift races passed.');
