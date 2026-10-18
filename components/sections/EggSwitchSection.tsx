import React, { useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { Alert } from '@/components/elements/alert';
import { Button } from '@/components/elements/button/index';
import { Dialog } from '@/components/elements/dialog';
import Spinner from '@/components/elements/Spinner';
import FlashMessageRender from '@/components/FlashMessageRender';
import { useFlashKey } from '@/plugins/useFlash';
import http from '@/api/http';

interface EggVariable {
    name: string;
    env_variable: string;
    default_value: string;
    rules: string;
}

interface EggOption {
    id: number;
    label: string;
    description: string;
    variables: EggVariable[];
}

interface NestGroup {
    name: string;
    eggs: EggOption[];
}

interface PendingReinstall {
    id: number;
    status: 'waiting_backup' | 'ready_to_wipe' | 'wiping' | 'done' | 'failed' | 'cancelled';
    with_backup: boolean;
    failed_reason: string | null;
    target_egg_id: number;
}

const STATUS_LABELS: Record<PendingReinstall['status'], string> = {
    waiting_backup: 'Safety backup in progress before the game switch.',
    ready_to_wipe: 'The game switch will start shortly.',
    wiping: 'Reinstall in progress: the server is starting from scratch with the new game.',
    done: 'Game switch completed.',
    failed: 'The game switch failed.',
    cancelled: 'The game switch was cancelled.',
};

const CANCELLABLE: PendingReinstall['status'][] = ['waiting_backup', 'ready_to_wipe'];
const ACTIVE: PendingReinstall['status'][] = ['waiting_backup', 'ready_to_wipe', 'wiping'];

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const serverName = ServerContext.useStoreState((state) => state.server.data?.name) || '';
    const { addError, clearFlashes, clearAndAddHttpError } = useFlashKey('eggswitch');

    const [loading, setLoading] = useState(true);
    const [nests, setNests] = useState<NestGroup[]>([]);
    const [currentEggId, setCurrentEggId] = useState<number | null>(null);
    const [backupLimit, setBackupLimit] = useState(0);
    const [backupUsed, setBackupUsed] = useState(0);
    const [nodeReachable, setNodeReachable] = useState(true);

    const [pending, setPending] = useState<PendingReinstall | null>(null);

    const [selectedNestName, setSelectedNestName] = useState<string | null>(null);
    const [selectedEggId, setSelectedEggId] = useState<number | null>(null);
    const [environment, setEnvironment] = useState<Record<string, string>>({});
    const [withBackup, setWithBackup] = useState(false);

    const [dialogOpen, setDialogOpen] = useState(false);
    const [confirmName, setConfirmName] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [cancelling, setCancelling] = useState(false);

    const hasBackupSlot = backupLimit > 0 && backupUsed < backupLimit;

    const loadStatus = () => {
        if (!uuid) return;

        http.get('/extensions/eggswitch/status', { params: { server: uuid } })
            .then(({ data }) => setPending(data.data))
            .catch((error) => console.error(error));
    };

    useEffect(() => {
        if (!uuid) return;

        setLoading(true);
        Promise.all([
            http.get('/extensions/eggswitch/eggs', { params: { server: uuid } }),
            http.get('/extensions/eggswitch/status', { params: { server: uuid } }),
        ])
            .then(([eggsRes, statusRes]) => {
                setNests(eggsRes.data.data);
                setCurrentEggId(eggsRes.data.current_egg_id);
                setBackupLimit(eggsRes.data.server?.backup_limit ?? 0);
                setBackupUsed(eggsRes.data.server?.backup_used ?? 0);
                setNodeReachable(eggsRes.data.server?.node_reachable ?? true);
                setPending(statusRes.data.data);
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setLoading(false));
    }, [uuid]);

    // While a request is active, keep track of its progress
    // without the user having to reload the page manually.
    useEffect(() => {
        if (!pending || !ACTIVE.includes(pending.status)) return;

        const interval = window.setInterval(loadStatus, 8000);
        return () => window.clearInterval(interval);
    }, [pending?.status, uuid]);

    // Grouping by nest (name + deduplication) is done on the back end
    // (GET /eggs returns groups directly) — the front end doesn't need to
    // know any nest_id, it just displays what it is given.
    const allEggs = nests.flatMap((nest) => nest.eggs);
    const selectedEgg = allEggs.find((e) => e.id === selectedEggId) || null;
    const eggsInSelectedNest = nests.find((n) => n.name === selectedNestName)?.eggs ?? [];

    const selectNest = (nestName: string | null) => {
        setSelectedNestName(nestName);
        setSelectedEggId(null);
        setEnvironment({});
    };

    const openConfirm = () => {
        if (!selectedEggId) {
            addError('Choose a game before continuing.');
            return;
        }
        clearFlashes();
        setConfirmName('');
        setDialogOpen(true);
    };

    const submit = () => {
        if (!uuid || !selectedEggId) return;
        if (confirmName !== serverName) {
            addError('The confirmation name does not match the server name.');
            return;
        }

        setSubmitting(true);
        clearFlashes();

        http.post('/extensions/eggswitch/reinstall', {
            server_uuid: uuid,
            egg_id: selectedEggId,
            confirm_server_name: confirmName,
            with_backup: withBackup,
            environment,
        })
            .then(({ data }) => {
                setDialogOpen(false);
                setPending(data.data);
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setSubmitting(false));
    };

    const cancel = () => {
        if (!uuid) return;

        setCancelling(true);
        clearFlashes();

        http.post('/extensions/eggswitch/cancel', { server_uuid: uuid })
            .then(({ data }) => setPending(data.data))
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setCancelling(false));
    };

    if (loading) {
        return (
            <ServerContentBlock title={'Switch game'} className={'eggswitch-page'}>
                <Spinner size={'large'} centered />
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title={'Switch game'} className={'eggswitch-page'}>
            <FlashMessageRender byKey={'eggswitch'} className={'eggswitch-flash mb-4'} />

            {pending && ACTIVE.includes(pending.status) && (
                <Alert type={'warning'} className={'eggswitch-active-alert mb-4'}>
                    <p className={'eggswitch-active-alert__text'}>{STATUS_LABELS[pending.status]}</p>
                    {CANCELLABLE.includes(pending.status) && (
                        <Button.Danger className={'eggswitch-cancel-btn mt-3'} disabled={cancelling} onClick={cancel}>
                            Cancel game switch
                        </Button.Danger>
                    )}
                </Alert>
            )}

            {/* Not reachable yet: /status only returns active requests (see TODO.md). */}
            {pending && pending.status === 'failed' && (
                <Alert type={'danger'} className={'eggswitch-failed-alert mb-4'}>
                    {pending.failed_reason || STATUS_LABELS.failed}
                </Alert>
            )}

            {(!pending || !ACTIVE.includes(pending.status)) && (
                <>
                    <p className={'eggswitch-intro mb-4 text-sm text-neutral-300'}>
                        Reinstall this server with a different game. <strong>All current server files
                        will be deleted.</strong> Select the safety backup option below if you want to keep
                        a copy.
                    </p>

                    {!nodeReachable && (
                        <Alert type={'danger'} className={'eggswitch-node-unreachable-alert mb-4'}>
                            This server's node is currently unreachable: a reinstall
                            would almost certainly fail. Try again later.
                        </Alert>
                    )}

                    {nests.length === 0 ? (
                        <Alert type={'info'} className={'eggswitch-empty-alert'}>No game is available to switch to on this panel.</Alert>
                    ) : selectedNestName === null ? (
                        <div className={'eggswitch-nest-grid grid grid-cols-1 md:grid-cols-2 gap-4 mb-4'}>
                            {nests.map((nest) => (
                                <button
                                    key={nest.name}
                                    type={'button'}
                                    onClick={() => selectNest(nest.name)}
                                    className={
                                        // eggswitch-nest-card: dedicated hook so a theme can target
                                        // this card precisely without depending on the Tailwind classes
                                        // below (reused elsewhere across the panel). The default
                                        // appearance doesn't change; these Tailwind classes remain the
                                        // source of truth as long as no theme overrides the hook.
                                        'eggswitch-nest-card p-4 rounded border cursor-pointer text-left border-neutral-600 bg-neutral-800 hover:border-primary-400'
                                    }
                                >
                                    <span className={'eggswitch-nest-card__title font-bold'}>{nest.name}</span>
                                    <p className={'eggswitch-nest-card__subtitle text-sm text-neutral-400 mt-1'}>
                                        {nest.eggs.length} game{nest.eggs.length > 1 ? 's' : ''} available
                                    </p>
                                </button>
                            ))}
                        </div>
                    ) : (
                        <>
                            <button
                                type={'button'}
                                onClick={() => selectNest(null)}
                                className={'eggswitch-back-btn mb-3 text-sm text-primary-300 hover:underline'}
                            >
                                ← Back to categories
                            </button>
                            <div className={'eggswitch-egg-grid grid grid-cols-1 md:grid-cols-2 gap-4 mb-4'}>
                                {eggsInSelectedNest.map((egg) => (
                                    <label
                                        key={egg.id}
                                        className={`eggswitch-egg-card ${
                                            selectedEggId === egg.id ? 'eggswitch-egg-card--selected' : ''
                                        } p-4 rounded border cursor-pointer ${
                                            selectedEggId === egg.id
                                                ? 'border-primary-400 bg-neutral-700'
                                                : 'border-neutral-600 bg-neutral-800'
                                        }`}
                                    >
                                        <div className={'eggswitch-egg-card__header flex items-center justify-between'}>
                                            <span className={'eggswitch-egg-card__title font-bold'}>{egg.label}</span>
                                            {egg.id === currentEggId && (
                                                <span className={'eggswitch-egg-card__current-badge text-xs text-neutral-400'}>Current game</span>
                                            )}
                                        </div>
                                        <p className={'eggswitch-egg-card__description text-sm text-neutral-400 mt-1'}>{egg.description}</p>
                                        <input
                                            type={'radio'}
                                            name={'eggswitch-egg'}
                                            className={'eggswitch-egg-card__radio hidden'}
                                            checked={selectedEggId === egg.id}
                                            onChange={() => {
                                                setSelectedEggId(egg.id);
                                                const defaults: Record<string, string> = {};
                                                egg.variables.forEach((v) => (defaults[v.env_variable] = v.default_value));
                                                setEnvironment(defaults);
                                            }}
                                        />
                                    </label>
                                ))}
                            </div>
                        </>
                    )}

                    {selectedEgg && selectedEgg.variables.length > 0 && (
                        <div className={'eggswitch-options mb-4'}>
                            <h3 className={'eggswitch-options__heading text-sm font-bold mb-2'}>{selectedEgg.label} options</h3>
                            {selectedEgg.variables.map((v) => (
                                <div key={v.env_variable} className={'eggswitch-variable mb-2'}>
                                    <label className={'eggswitch-variable__label block text-sm mb-1'}>{v.name}</label>
                                    <input
                                        className={'eggswitch-variable__input w-full rounded bg-neutral-700 border border-neutral-600 px-3 py-2 text-sm'}
                                        value={environment[v.env_variable] ?? ''}
                                        onChange={(e) => {
                                            // The value must be read here, before setState: inside the
                                            // functional callback below, the React synthetic event is
                                            // already recycled (currentTarget becomes null).
                                            const value = e.currentTarget.value;
                                            setEnvironment((prev) => ({ ...prev, [v.env_variable]: value }));
                                        }}
                                    />
                                </div>
                            ))}
                        </div>
                    )}

                    <label className={'eggswitch-backup-option flex items-center mb-4'}>
                        <input
                            type={'checkbox'}
                            className={'eggswitch-backup-option__checkbox mr-2'}
                            disabled={!hasBackupSlot}
                            checked={withBackup && hasBackupSlot}
                            onChange={(e) => setWithBackup(e.currentTarget.checked)}
                        />
                        <span className={'eggswitch-backup-option__label'}>
                            Make a safety backup before reinstalling
                            {!hasBackupSlot && (
                                <span className={'eggswitch-backup-option__hint text-neutral-400'}>
                                    {' '}
                                    (no backup slot available on this server)
                                </span>
                            )}
                        </span>
                    </label>

                    <Button className={'eggswitch-submit-btn'} disabled={!selectedEggId || !nodeReachable} onClick={openConfirm}>
                        Switch game
                    </Button>
                </>
            )}

            <Dialog
                open={dialogOpen}
                onClose={() => setDialogOpen(false)}
                title={'Confirm game switch'}
                preventExternalClose={submitting}
            >
                <p className={'eggswitch-confirm-dialog__text mb-4'}>
                    This action will <strong>delete all current files</strong> of{' '}
                    <strong>{serverName}</strong> and reinstall the server with{' '}
                    <strong>{selectedEgg?.label}</strong>
                    {withBackup && hasBackupSlot ? ', after a safety backup' : ', without a safety backup'}
                    . This cannot be undone.
                </p>
                <label className={'eggswitch-confirm-dialog__label block text-sm mb-1'}>
                    Type the server name (<code>{serverName}</code>) to confirm:
                </label>
                <input
                    className={'eggswitch-confirm-dialog__input w-full rounded bg-neutral-700 border border-neutral-600 px-3 py-2 text-sm mb-2'}
                    value={confirmName}
                    onChange={(e) => setConfirmName(e.currentTarget.value)}
                />
                <Dialog.Footer>
                    <Button.Text className={'eggswitch-confirm-dialog__cancel-btn'} onClick={() => setDialogOpen(false)}>Cancel</Button.Text>
                    <Button.Danger className={'eggswitch-confirm-dialog__submit-btn'} disabled={submitting || confirmName !== serverName} onClick={submit}>
                        Switch game
                    </Button.Danger>
                </Dialog.Footer>
            </Dialog>
        </ServerContentBlock>
    );
};
