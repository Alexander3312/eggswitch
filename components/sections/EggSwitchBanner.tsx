import React, { useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import { Alert } from '@/components/elements/alert';
import { NavLink } from 'react-router-dom';
import http from '@/api/http';

interface PendingReinstall {
    status: 'waiting_backup' | 'ready_to_wipe' | 'wiping' | 'done' | 'failed' | 'cancelled';
}

const LABELS: Record<string, string> = {
    waiting_backup: 'Safety backup in progress before the game switch.',
    ready_to_wipe: 'The game switch will start shortly.',
    wiping: 'Reinstall in progress: the server is starting from scratch with the new game.',
};

const ACTIVE = ['waiting_backup', 'ready_to_wipe', 'wiping'];

/**
 * Injected into the server console page via Server.Terminal.BeforeContent
 * (see components/Components.yml) — a silent failure here must never
 * break the console display itself.
 */
export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const id = ServerContext.useStoreState((state) => state.server.data?.id);
    const [pending, setPending] = useState<PendingReinstall | null>(null);

    useEffect(() => {
        if (!uuid) return;

        let cancelled = false;

        const load = () => {
            http.get('/extensions/eggswitch/status', { params: { server: uuid } })
                .then(({ data }) => {
                    if (!cancelled) setPending(data.data);
                })
                .catch(() => undefined);
        };

        load();
        const interval = window.setInterval(load, 15000);

        return () => {
            cancelled = true;
            window.clearInterval(interval);
        };
    }, [uuid]);

    if (!pending || !ACTIVE.includes(pending.status)) {
        return null;
    }

    return (
        <Alert type={'warning'} className={'eggswitch-console-banner mb-4'}>
            {LABELS[pending.status] || 'A game switch is in progress for this server.'}{' '}
            <NavLink to={`/server/${id}/eggswitch`} className={'eggswitch-console-banner__link underline font-bold'}>
                View details
            </NavLink>
        </Alert>
    );
};
