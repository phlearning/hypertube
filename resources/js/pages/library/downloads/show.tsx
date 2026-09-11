import Heading from '@/components/heading';
import { index } from '@/routes/library';
import { show } from '@/routes/library/downloads';
import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';

type Download = {
    id: number;
    title: string | null;
    status: string;
    downloaded_bytes: number;
    total_bytes: number | null;
    is_complete: boolean;
    message: string | null;
};

type DownloadShowProps = {
    download: Download;
};

const POLL_INTERVAL_MS = 2000;

const STATUS_LABELS: Record<string, string> = {
    pending: 'En attente',
    downloading: 'Téléchargement en cours',
    completed: 'Terminé',
    failed: 'Échec',
};

function formatBytes(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} Ko`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
}

export default function DownloadShow({ download }: DownloadShowProps) {
    const isSettled = download.status === 'completed' || download.status === 'failed';

    useEffect(() => {
        if (isSettled) {
            return;
        }

        const interval = setInterval(() => {
            router.reload({ only: ['download'] });
        }, POLL_INTERVAL_MS);

        return () => clearInterval(interval);
    }, [isSettled]);

    const percent =
        download.total_bytes && download.total_bytes > 0
            ? Math.min(100, Math.round((download.downloaded_bytes / download.total_bytes) * 100))
            : null;

    const heading = download.title ?? 'Téléchargement';

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    variant="small"
                    title={heading}
                    description={STATUS_LABELS[download.status] ?? download.status}
                />

                <div className="max-w-md space-y-3 rounded-xl border bg-card p-4">
                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary transition-all"
                            style={{ width: `${percent ?? (isSettled ? 100 : 0)}%` }}
                        />
                    </div>

                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>{formatBytes(download.downloaded_bytes)}</span>
                        <span>
                            {percent !== null
                                ? `${percent}%`
                                : download.total_bytes
                                  ? formatBytes(download.total_bytes)
                                  : '—'}
                        </span>
                    </div>

                    {download.message && (
                        <p className="text-sm text-muted-foreground">{download.message}</p>
                    )}
                </div>
            </div>
        </>
    );
}

DownloadShow.layout = ({ download }: DownloadShowProps) => ({
    breadcrumbs: [
        { title: 'Library', href: index() },
        { title: 'Téléchargement', href: show(download.id) },
    ],
});
