import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import Heading from '@/components/heading';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/library';
import { show, stream } from '@/routes/library/downloads';

type Download = {
    id: number;
    title: string | null;
    status: string;
    downloaded_bytes: number;
    total_bytes: number | null;
    is_complete: boolean;
    message: string | null;
    source: string | null;
    format: string | null;
    transcode_status: string | null;
    can_play: boolean;
};

type DownloadShowProps = {
    download: Download;
};

const POLL_INTERVAL_MS = 2000;

const STATUS_LABELS: Record<string, string> = {
    queued: "En file d'attente",
    pending: 'En attente',
    downloading: 'Téléchargement en cours',
    completed: 'Terminé',
    failed: 'Échec',
};

const SOURCE_LABELS: Record<string, string> = {
    archive_org: 'Archive.org',
    public_domain_torrents: 'PublicDomainTorrents.info',
};

const TRANSCODE_STATUS_LABELS: Record<string, string> = {
    processing: 'Optimisation de la vidéo en cours…',
    completed: 'Vidéo optimisée pour la lecture',
    failed: "Échec de l'optimisation vidéo",
    skipped: 'Format déjà compatible, aucune conversion nécessaire',
};

const TRANSCODE_TERMINAL_STATUSES = ['completed', 'skipped', 'failed'];

function formatBytes(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} Ko`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`;
}

export default function DownloadShow({ download }: DownloadShowProps) {
    // A completed download still needs polling if transcoding (dispatched
    // right as the download settles) hasn't reached its own terminal state
    // yet — otherwise the page would never notice the video becoming
    // playable once optimisation finishes.
    const isSettled =
        download.status === 'failed' ||
        (download.status === 'completed' &&
            download.transcode_status !== null &&
            TRANSCODE_TERMINAL_STATUSES.includes(download.transcode_status));

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

    const waitingReason =
        download.downloaded_bytes === 0
            ? 'En attente de données avant de pouvoir lire la vidéo…'
            : download.transcode_status === 'failed'
              ? "Échec de l'optimisation vidéo : la lecture n'est pas possible."
              : 'Conversion de la vidéo pour la lecture…';

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    variant="small"
                    title={heading}
                    description={STATUS_LABELS[download.status] ?? download.status}
                />

                {download.can_play ? (
                    <video
                        controls
                        preload="metadata"
                        className="aspect-video w-full max-w-3xl rounded-xl border bg-black"
                        src={stream.url(download.id)}
                    >
                        Votre navigateur ne prend pas en charge la lecture vidéo intégrée.
                    </video>
                ) : (
                    <div className="flex aspect-video w-full max-w-3xl flex-col items-center justify-center gap-2 rounded-xl border bg-muted text-sm text-muted-foreground">
                        {download.transcode_status !== 'failed' && <Spinner className="size-6" />}
                        {waitingReason}
                    </div>
                )}

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

                {(download.source || download.format) && (
                    <div className="max-w-md space-y-2 rounded-xl border bg-card p-4 text-sm">
                        {download.source && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Source</span>
                                <span>{SOURCE_LABELS[download.source] ?? download.source}</span>
                            </div>
                        )}

                        {download.format && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Format d'origine</span>
                                <span>{download.format}</span>
                            </div>
                        )}

                        {download.total_bytes !== null && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">Taille</span>
                                <span>{formatBytes(download.total_bytes)}</span>
                            </div>
                        )}

                        {download.transcode_status && (
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-muted-foreground">Optimisation</span>
                                <span className="text-right">
                                    {TRANSCODE_STATUS_LABELS[download.transcode_status] ?? download.transcode_status}
                                </span>
                            </div>
                        )}
                    </div>
                )}
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
