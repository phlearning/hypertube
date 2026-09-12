import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { APP_ROOT, makeTorrentJob, resetTestData } from './support/artisan';
import { ADMIN_AUTH } from './support/auth';

test.use({ storageState: ADMIN_AUTH });

test.beforeEach(() => {
    // This scenario asserts on exact counts and file survival, so it needs
    // a known starting point rather than whatever earlier scenarios left
    // behind in the shared dev database.
    resetTestData();
});

test('clearing the "stuck" scope removes only failed/stale jobs, sparing completed ones', async ({ page }) => {
    makeTorrentJob({ title: 'Failed', status: 'failed' });
    makeTorrentJob({ title: 'Completed', status: 'completed', file_path: '/shared/e2e/completed.mp4' });

    await page.goto('/admin/torrent-jobs');
    await expect(page.getByText('2', { exact: true }).first()).toBeVisible();

    // "Bloqués ou en échec uniquement" (stuck) is already the default scope.
    await page.getByRole('button', { name: 'Nettoyer' }).click();
    await page.getByRole('button', { name: 'Confirmer la suppression' }).click();

    await expect(page.getByText('1 téléchargement supprimé')).toBeVisible();
    await expect(page.getByText('Completed')).toHaveCount(0);
});

test('clearing "all" with delete-files also removes the file from disk', async ({ page }) => {
    const tmpDir = path.join(process.cwd(), 'storage', 'app', 'e2e-tmp');
    fs.mkdirSync(tmpDir, { recursive: true });
    const filePath = path.join(tmpDir, `movie-${Date.now()}.mp4`);
    fs.writeFileSync(filePath, 'fake video bytes');

    makeTorrentJob({
        title: 'To Purge',
        status: 'completed',
        file_path: path.posix.join(APP_ROOT, 'storage', 'app', 'e2e-tmp', path.basename(filePath)),
    });

    await page.goto('/admin/torrent-jobs');

    await page.getByLabel('Portée').click();
    await page.getByRole('option', { name: 'Tous les téléchargements' }).click();
    await page.getByLabel('Supprimer aussi les fichiers sur disque').click();

    await page.getByRole('button', { name: 'Nettoyer' }).click();
    await expect(page.getByText('y compris les fichiers sur disque')).toBeVisible();
    await page.getByRole('button', { name: 'Confirmer la suppression' }).click();

    await expect(page.getByText('1 téléchargement supprimé')).toBeVisible();
    expect(fs.existsSync(filePath)).toBe(false);
});
