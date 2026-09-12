import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { clear } from '@/routes/admin/torrent-jobs';

type TorrentJobAdminProps = {
    counts: {
        total: number;
        active: number;
        failed: number;
    };
};

const SCOPE_LABELS: Record<string, string> = {
    all: 'Tous les téléchargements',
    stuck: 'Bloqués ou en échec uniquement',
};

export default function TorrentJobsAdmin({ counts }: TorrentJobAdminProps) {
    const [scope, setScope] = useState('stuck');
    const [deleteFiles, setDeleteFiles] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(
            clear.url(),
            { scope, delete_files: deleteFiles },
            {
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Administration des téléchargements" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    variant="small"
                    title="Administration des téléchargements"
                    description="Nettoyer les téléchargements enregistrés dans la base"
                />

                <div className="grid max-w-md grid-cols-3 gap-3 text-center">
                    <div className="rounded-xl border bg-card p-4">
                        <div className="text-2xl font-semibold">
                            {counts.total}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Total
                        </div>
                    </div>
                    <div className="rounded-xl border bg-card p-4">
                        <div className="text-2xl font-semibold">
                            {counts.active}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            En cours
                        </div>
                    </div>
                    <div className="rounded-xl border bg-card p-4">
                        <div className="text-2xl font-semibold">
                            {counts.failed}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            En échec
                        </div>
                    </div>
                </div>

                <div className="max-w-md space-y-4 rounded-xl border bg-card p-4">
                    <div className="grid gap-2">
                        <Label htmlFor="scope">Portée</Label>
                        <Select value={scope} onValueChange={setScope}>
                            <SelectTrigger id="scope" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="stuck">
                                    {SCOPE_LABELS.stuck}
                                </SelectItem>
                                <SelectItem value="all">
                                    {SCOPE_LABELS.all}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="delete_files"
                            checked={deleteFiles}
                            onCheckedChange={(checked) =>
                                setDeleteFiles(checked === true)
                            }
                        />
                        <Label htmlFor="delete_files" className="font-normal">
                            Supprimer aussi les fichiers sur disque
                        </Label>
                    </div>

                    <Button
                        variant="destructive"
                        onClick={() => setConfirmOpen(true)}
                        disabled={processing}
                    >
                        Nettoyer
                    </Button>
                </div>
            </div>

            <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Confirmer le nettoyage</DialogTitle>
                        <DialogDescription>
                            Cette action va supprimer définitivement «{' '}
                            {SCOPE_LABELS[scope]} »
                            {deleteFiles
                                ? ', y compris les fichiers sur disque'
                                : ''}
                            . Cette action est irréversible.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmOpen(false)}
                        >
                            Annuler
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submit}
                            disabled={processing}
                        >
                            Confirmer la suppression
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
