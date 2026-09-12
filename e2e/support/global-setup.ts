import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium  } from '@playwright/test';
import type {FullConfig} from '@playwright/test';
import { resetTestData } from './artisan';

const AUTH_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '.auth');

async function saveLoggedInState(baseURL: string, username: string, outFile: string): Promise<void> {
    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL });

    await page.goto('/login');
    await page.getByPlaceholder('username').fill(username);
    await page.getByPlaceholder('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));

    await page.context().storageState({ path: outFile });
    await browser.close();
}

/**
 * Logs in once per account here rather than once per test: Fortify throttles
 * the login endpoint to 5 attempts/minute per username+IP
 * (FortifyServiceProvider::configureRateLimiting), which a suite this size
 * would blow through in seconds if every test logged in fresh via the UI.
 * Each spec then loads the saved storage state instead of visiting /login.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
    resetTestData();

    const baseURL = config.projects[0]?.use.baseURL ?? 'http://localhost:8000';

    await saveLoggedInState(baseURL, 'e2e-user', path.join(AUTH_DIR, 'user.json'));
    await saveLoggedInState(baseURL, 'e2e-admin', path.join(AUTH_DIR, 'admin.json'));
}
