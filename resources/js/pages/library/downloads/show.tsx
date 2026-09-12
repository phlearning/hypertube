import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Spinner } from '@/components/ui/spinner';
import echo from '@/echo';
import { index } from '@/routes/library';
import { show, stream } from '@/routes/library/downloads';

type MediaInfo = {
    width: number | null;
    height: number | null;
    duration_seconds: number | null;
    video_codec: string | null;
    audio_codec: string | null;
};

type AttemptedCandidate = {
    source: string | null;
    torrent_url: string;
    message: string | null;
};

type Download = {
    id: number;
    title: string | null;
    status: string;
    downloaded_bytes: number;
    total_bytes: number | null;
    is_complete: boolean;
    message: string | null;
    source: string | null;
    seeders: number | null;
    peers: number | null;
    format: string | null;
    transcode_status: string | null;
    can_play: boolean;
    attempted_candidates: AttemptedCandidate[];
    media_info: MediaInfo | null;
};

type DownloadShowProps = {
    download: Download;
};

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

function formatDuration(seconds: number): string {
    const totalSeconds = Math.round(seconds);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const remainingSeconds = totalSeconds % 60;

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`
        : `${minutes}:${String(remainingSeconds).padStart(2, '0')}`;
}

export default function DownloadShow({
    download: initialDownload,
}: DownloadShowProps) {
    const [download, setDownload] = useState(initialDownload);

    // A completed download still needs live updates if transcoding
    // (dispatched right as the download settles) hasn't reached its own
    // terminal state yet — otherwise the page would never notice the video
    // becoming playable once optimisation finishes.
    const isSettled =
        download.status === 'failed' ||
        (download.status === 'completed' &&
            download.transcode_status !== null &&
            TRANSCODE_TERMINAL_STATUSES.includes(download.transcode_status));

    useEffect(() => {
        if (isSettled) {
            return;
        }

        const channelName = `torrent-job.${download.id}`;
        echo.private(channelName)
            .listen('.progress.updated', (payload: Download) => {
                setDownload(payload);
            })
            .subscribed(() => {
                // Closes the race between this page's server render and the
                // channel actually becoming authorized: a progress broadcast
                // sent during that window is missed outright (Reverb doesn't
                // replay past messages), so a one-off catch-up fetch once
                // subscribed — not a recurring poll — covers it.
                router.reload({ only: ['download'] });
            })
            .error((status: unknown) => {
                console.error(
                    'Failed to subscribe to torrent job progress channel.',
                    status,
                );
            });

        return () => {
            echo.leave(channelName);
        };
    }, [download.id, isSettled]);

    const percent =
        download.total_bytes && download.total_bytes > 0
            ? Math.min(
                  100,
                  Math.round(
                      (download.downloaded_bytes / download.total_bytes) * 100,
                  ),
              )
            : null;

    const heading = download.title ?? 'Téléchargement';

    const waitingReason =
        download.status === 'failed'
            ? "Le téléchargement a échoué : la lecture n'est pas possible."
            : download.downloaded_bytes === 0
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
                    description={
                        STATUS_LABELS[download.status] ?? download.status
                    }
                />

                {download.can_play ? (
                    <video
                        controls
                        preload="metadata"
                        className="aspect-video w-full max-w-3xl rounded-xl border bg-black"
                        src={stream.url(download.id)}
                    >
                        Votre navigateur ne prend pas en charge la lecture vidéo
                        intégrée.
                    </video>
                ) : (
                    <div className="flex aspect-video w-full max-w-3xl flex-col items-center justify-center gap-2 rounded-xl border bg-muted text-sm text-muted-foreground">
                        {download.status !== 'failed' &&
                            download.transcode_status !== 'failed' && (
                                <Spinner className="size-6" />
                            )}
                        {waitingReason}
                    </div>
                )}

                <div className="max-w-md space-y-3 rounded-xl border bg-card p-4">
                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary transition-all"
                            style={{
                                width: `${percent ?? (isSettled ? 100 : 0)}%`,
                            }}
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
                        <p className="text-sm text-muted-foreground">
                            {download.message}
                        </p>
                    )}
                </div>

                {(download.source || download.format) && (
                    <div className="max-w-md space-y-2 rounded-xl border bg-card p-4 text-sm">
                        {download.source && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Source
                                </span>
                                <span>
                                    {SOURCE_LABELS[download.source] ??
                                        download.source}
                                </span>
                            </div>
                        )}

                        {download.format && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Format d'origine
                                </span>
                                <span>{download.format}</span>
                            </div>
                        )}

                        {download.total_bytes !== null && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Taille
                                </span>
                                <span>{formatBytes(download.total_bytes)}</span>
                            </div>
                        )}

                        {(download.seeders !== null ||
                            download.peers !== null) && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Seeders / Peers
                                </span>
                                <span>
                                    {download.seeders ?? '—'} /{' '}
                                    {download.peers ?? '—'}
                                </span>
                            </div>
                        )}

                        {download.transcode_status && (
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Optimisation
                                </span>
                                <span className="text-right">
                                    {TRANSCODE_STATUS_LABELS[
                                        download.transcode_status
                                    ] ?? download.transcode_status}
                                </span>
                            </div>
                        )}
                    </div>
                )}

                {download.media_info && (
                    <div className="max-w-md space-y-2 rounded-xl border bg-card p-4 text-sm">
                        <h2 className="font-medium">Détails techniques</h2>

                        {download.media_info.width !== null &&
                            download.media_info.height !== null && (
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">
                                        Résolution
                                    </span>
                                    <span>
                                        {download.media_info.width}×
                                        {download.media_info.height}
                                    </span>
                                </div>
                            )}

                        {download.media_info.duration_seconds !== null && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Durée
                                </span>
                                <span>
                                    {formatDuration(
                                        download.media_info.duration_seconds,
                                    )}
                                </span>
                            </div>
                        )}

                        {download.media_info.video_codec && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Codec vidéo
                                </span>
                                <span>{download.media_info.video_codec}</span>
                            </div>
                        )}

                        {download.media_info.audio_codec && (
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground">
                                    Codec audio
                                </span>
                                <span>{download.media_info.audio_codec}</span>
                            </div>
                        )}
                    </div>
                )}

                {download.attempted_candidates.length > 0 && (
                    <div className="max-w-md space-y-2 rounded-xl border bg-card p-4 text-sm">
                        <h2 className="font-medium">
                            Candidats précédemment essayés
                        </h2>

                        <ul className="space-y-1">
                            {download.attempted_candidates.map(
                                (candidate, index) => (
                                    <li
                                        key={index}
                                        className="flex items-center justify-between gap-4 text-muted-foreground"
                                    >
                                        <span>
                                            {SOURCE_LABELS[
                                                candidate.source ?? ''
                                            ] ??
                                                candidate.source ??
                                                '—'}
                                        </span>
                                        <span className="text-right">
                                            {candidate.message ?? 'Échec'}
                                        </span>
                                    </li>
                                ),
                            )}
                        </ul>
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
