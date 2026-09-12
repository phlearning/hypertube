import { test, expect } from '@playwright/test';
import { seedSearchResults } from './support/artisan';
import { USER_AUTH } from './support/auth';

test.use({ storageState: USER_AUTH });

const QUERY = 'e2e-scroll-test';

function movie(title: string, sourceId: string, year: number | null = null) {
    return {
        title,
        year,
        rating: null,
        poster: null,
        genre: null,
        popularity: 1,
        candidates: [{ source: 'archive_org', source_id: sourceId, torrent_url: `http://e2e.test/${sourceId}.torrent` }],
    };
}

test('clicking "Regarder" on a duplicate-titled card loaded via scroll submits that card\'s own candidate', async ({ page }) => {
    // Two movies sharing an identical title and no year — a shape
    // MovieSearchService::group() treats as distinct movies (different
    // underlying candidates). One sits on page 1, the other only reachable
    // via infinite scroll, matching the reported symptom (clicks on cards
    // loaded past the first page). The frontend's React key used to be
    // `title:year`, which is not guaranteed unique here; it's now the
    // candidate's own source:source_id. This test verifies the actual
    // acceptance criterion — the right candidate reaches the request no
    // matter how deep the card is — independent of whether the exact
    // title:year collision was ever the confirmed root cause of the
    // original report (the ticket itself notes it wasn't pinned down).
    const filler = Array.from({ length: 24 }, (_, i) => movie(`Filler ${i}`, `filler-${i}`));
    const movies = [
        movie('Duplicate Movie', 'dup-a'),
        ...filler,
        movie('Duplicate Movie', 'dup-b'),
    ];
    seedSearchResults(QUERY, movies);

    await page.goto(`/library?q=${QUERY}`);

    // Load the next page via scroll.
    await page.mouse.wheel(0, 20000);
    await expect(page.getByText('Filler 23')).toBeVisible();
    await page.mouse.wheel(0, 20000);

    const cards = page.locator('div.rounded-xl.border.bg-card').filter({ hasText: 'Duplicate Movie' });
    await expect(cards).toHaveCount(2);
    const deepCard = cards.last();
    await deepCard.scrollIntoViewIfNeeded();

    const [request] = await Promise.all([
        page.waitForRequest((req) => req.url().includes('/library/downloads') && req.method() === 'POST'),
        deepCard.getByRole('button', { name: 'Regarder' }).click(),
    ]);

    const body = request.postData() ?? '';
    expect(body).toContain('dup-b');
    expect(body).not.toContain('dup-a');
});
