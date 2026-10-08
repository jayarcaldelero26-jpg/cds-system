import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
import { viteReactInterop } from './helpers/viteReactInterop.mjs';

let server;
const load = async path => {
    server ??= await createServer({
        configFile: false,
        plugins: [viteReactInterop(), react()],
        resolve: { alias: { '@': resolve('resources/js') } },
        server: { middlewareMode: true, hmr: false },
        appType: 'custom',
        logLevel: 'error',
    });
    return server.ssrLoadModule('/resources/js/' + path);
};

test.after(async () => { await server?.close(); });

test('PENRO Records dispatch shows a pending archive notice and disables repeat submission', async () => {
    const { archiveCheckpointPendingNotice } = await load('Utils/archiveCheckpointNotice.js');
    const { default: CrudFormModal } = await load('Components/Crud/CrudFormModal.jsx');
    const notice = archiveCheckpointPendingNotice(true, 'conservation', 'dispatch_penro_records_to_cds_focal');
    const markup = renderToStaticMarkup(React.createElement(CrudFormModal, {
        open: true,
        mode: 'edit',
        title: 'Dispatch to CDS Focal',
        processing: true,
        systemNotice: notice,
        onClose() {},
        onSubmit() {},
    }, React.createElement('p', null, 'Synthetic archive checkpoint action')));

    assert.match(markup, /Archive checkpoint in progress/);
    assert.match(markup, /verifying the report in the existing archive/);
    assert.match(markup, /type="submit"[^>]*disabled=""/);
    assert.equal(archiveCheckpointPendingNotice(false, 'conservation', 'dispatch_penro_records_to_cds_focal'), null);
    assert.equal(archiveCheckpointPendingNotice(true, 'bms', 'dispatch_penro_records_to_cds_focal'), null);
    assert.equal(archiveCheckpointPendingNotice(true, 'conservation', 'receive_at_cds_focal'), null);
});
