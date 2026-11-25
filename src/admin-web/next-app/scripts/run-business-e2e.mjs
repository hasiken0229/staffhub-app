import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, writeFile, readFile, open } from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const frontend = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const backend = path.resolve(frontend, '../../backend-laravel-app');
const options = process.argv.slice(2);
if (options.some(x => !['--mysql-credentials'].includes(x) && !options.includes('--mysql-credentials'))) throw new Error('Unsupported argument');
if (options.length && (options.length !== 2 || options[0] !== '--mysql-credentials')) throw new Error('Use --mysql-credentials <dedicated local credentials.json>');
for (const name of ['STAFFHUB_E2E_BASE_URL', 'STAFFHUB_E2E_API_URL']) {
  if (process.env[name] && !['localhost','127.0.0.1','[::1]'].includes(new URL(process.env[name]).hostname)) throw new Error('Production URLs are prohibited');
}
const evidenceBase = path.join(process.env.LOCALAPPDATA ?? os.tmpdir(), 'StaffHub', 'Verification');
await mkdir(evidenceBase, { recursive: true });
const root = await mkdtemp(path.join(evidenceBase, 'e2e-'));
for (const folder of ['storage/app/private','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','results','downloads']) await mkdir(path.join(root, folder),{recursive:true});
await writeFile(path.join(root,'.staffhub-verification'),'isolated fixture environment');
await writeFile(path.join(root,'database.sqlite'),'');
const env = { ...process.env, STAFFHUB_E2E:'true', STAFFHUB_VERIFICATION_ROOT:root,
  APP_ENV:'testing', APP_DEBUG:'false', APP_URL:'http://127.0.0.1:8130', APP_KEY:'base64:'+Buffer.alloc(32, 7).toString('base64'),
  DB_CONNECTION:'sqlite', DB_DATABASE:path.join(root,'database.sqlite'), DB_URL:'', DATABASE_URL:'',
  LARAVEL_STORAGE_PATH:path.join(root,'storage'), APP_CONFIG_CACHE:path.join(root,'config.php'), APP_ROUTES_CACHE:path.join(root,'routes.php'),
  MAIL_MAILER:'array', CACHE_STORE:'array', SESSION_DRIVER:'array', QUEUE_CONNECTION:'database',
  STAFFHUB_GOOGLE_CHAT_ENABLED:'false', STAFFHUB_GOOGLE_CHAT_CREDENTIALS_PATH:'',
  CORS_ALLOWED_ORIGINS:'http://127.0.0.1:3130',
  NEXT_PUBLIC_API_BASE_URL:'http://127.0.0.1:8130', NEXT_PUBLIC_BASE_PATH:'/dakoku/admin',
  STAFFHUB_NEXT_DIST_DIR:'.next-business', STAFFHUB_E2E_BASE_URL:'http://127.0.0.1:3130', STAFFHUB_E2E_API_URL:'http://127.0.0.1:8130',
};
if (options.length) {
  const creds = JSON.parse(await readFile(options[1],'utf8'));
  if (!['127.0.0.1','localhost'].includes(creds.host) || !creds.user.startsWith('staffhub_verify')) throw new Error('Only dedicated loopback MySQL accounts accepted');
  Object.assign(env,{DB_CONNECTION:'mysql',DB_HOST:creds.host,DB_PORT:String(creds.port),DB_USERNAME:creds.user,DB_PASSWORD:creds.password,
    DB_DATABASE:'staffhub_verification_'+path.basename(root).replace(/[^a-z0-9]/gi,'').toLowerCase()});
}
// Save only the dedicated test connection for repeatable CLI concurrency checks. Never log passwords.
// The saved environment excludes unrelated inherited secrets.
const saved = Object.fromEntries(Object.entries(env).filter(([key])=>/^(STAFFHUB_E2E|STAFFHUB_VERIFICATION|APP_|DB_|DATABASE_URL|LARAVEL_|MAIL_MAILER|CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION|STAFFHUB_GOOGLE_CHAT_|CORS_|NEXT_PUBLIC_|STAFFHUB_NEXT_)/.test(key)));
await writeFile(path.join(root,'test-environment.json'),JSON.stringify(saved,null,2),{mode:0o600});
const children=[];
const logFiles=[];
const php=process.env.STAFFHUB_PHP ?? (process.platform==='win32'?'C:/php/php.exe':'php');
const phpArgs=process.platform==='win32'?['-d','extension=fileinfo','-d','extension=pdo_mysql','-d','extension=curl','-d','extension=gd']:[];
async function run(command,args,cwd,logName,background=false) {
  const file=await open(path.join(root,logName),'a');
  logFiles.push(file);
  const child=spawn(command,args,{cwd,env,windowsHide:true,stdio:['ignore',file.fd,file.fd]});
  children.push(child);
  if(background)return child;
  const code=await new Promise((resolve,reject)=>{child.once('exit',resolve);child.once('error',reject);});
  await file.close();
  if(code!==0) throw new Error(`${logName} failed (${code}); evidence: ${root}`);
}
async function assertFree(port) {
  const server=createServer(); await new Promise((resolve,reject)=>server.once('error',reject).listen(port,'127.0.0.1',resolve));
  await new Promise(resolve=>server.close(resolve));
}
async function ready(url,child) {
  const deadline=Date.now()+120000;
  while(Date.now()<deadline){if(child.exitCode!==null)throw new Error('Server stopped; see logs');try{if((await fetch(url)).ok)return;}catch{}await new Promise(r=>setTimeout(r,500));}
  throw new Error('Server startup timed out');
}
try {
  await assertFree(8130); await assertFree(3130);
  await run(php,[...phpArgs,'scripts/verification/seed-e2e.php'],backend,'seed.log');
  const seeded = JSON.parse(await readFile(path.join(root, 'fixtures.json'), 'utf8'));
  if (!Number.isInteger(seeded.employeeId) || seeded.employeeId < 1) throw new Error('Fixture initialization did not complete; see seed.log');
  const api=await run(php,[...phpArgs,'-d','variables_order=EGPCS','-S','127.0.0.1:8130','-t','public','scripts/verification/router.php'],backend,'api.log',true);
  const web=await run(process.execPath,['node_modules/next/dist/bin/next','dev','--hostname','127.0.0.1','--port','3130'],frontend,'frontend.log',true);
  await Promise.all([ready(env.APP_URL+'/up',api),ready(env.STAFFHUB_E2E_BASE_URL+'/dakoku/admin/',web)]);
  console.log('Isolated business verification: '+root);
  await run(process.execPath,['node_modules/@playwright/test/cli.js','test','--config=playwright.business.config.ts'],frontend,'playwright.log');
  console.log(await readFile(path.join(root,'playwright.log'),'utf8'));
  if (env.DB_CONNECTION==='mysql') {
    await run(process.execPath,['scripts/verify-month-races.mjs',root],frontend,'month-races.log');
    console.log(await readFile(path.join(root,'month-races.log'),'utf8'));
  }
} finally { for(const child of children) if(child.exitCode===null) child.kill(); for(const file of logFiles) await file.close().catch(()=>{}); console.log('Evidence: '+root); }
