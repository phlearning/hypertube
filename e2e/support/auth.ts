import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DIRNAME = path.dirname(fileURLToPath(import.meta.url));

/**
 * Storage state files written once by global-setup.ts. Use with
 * `test.use({ storageState: USER_AUTH })` at the top of a spec file instead
 * of logging in through the UI in every test (see global-setup.ts for why).
 */
export const USER_AUTH = path.join(DIRNAME, '..', '.auth', 'user.json');
export const ADMIN_AUTH = path.join(DIRNAME, '..', '.auth', 'admin.json');
