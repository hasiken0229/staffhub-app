import { defineConfig } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
const root=process.env.STAFFHUB_VERIFICATION_ROOT;
if(process.env.STAFFHUB_E2E!=='true' || !root || !fs.existsSync(path.join(root,'.staffhub-verification'))) throw new Error('Run through scripts/run-business-e2e.mjs');
for(const url of [process.env.STAFFHUB_E2E_BASE_URL,process.env.STAFFHUB_E2E_API_URL]) if(!url || new URL(url).hostname!=='127.0.0.1') throw new Error('Non-local test URL prohibited');
export default defineConfig({testDir:'./tests/business',workers:1,fullyParallel:false,timeout:90_000,expect:{timeout:20_000},
  outputDir:path.join(root,'results'),reporter:[['list'],['json',{outputFile:path.join(root,'results.json')}]],
  use:{baseURL:process.env.STAFFHUB_E2E_BASE_URL,trace:'retain-on-failure',screenshot:'only-on-failure',viewport:{width:1440,height:1000}},
});
