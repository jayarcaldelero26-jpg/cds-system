import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
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
    return server.ssrLoadModule(`/resources/js/${path}`);
};

test.after(async () => { await server?.close(); });

function makeHookHarness(useConfirmedFormSubmit, onSubmit) {
    const internals = React.__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE;
    const previousDispatcher = internals.H;
    const previousFormData = globalThis.FormData;
    const slots = [];
    let cursor = 0;
    let gate;
    const selectedFile = new File(['report attachment'], 'mov.pdf', { type: 'application/pdf', lastModified: 1700000000000 });
    const form = {
        entries: [['title', 'Quarterly report'], ['attachment', selectedFile]],
        valid: true,
        reportedValidity: 0,
        checkValidity() { return this.valid; },
        reportValidity() { this.reportedValidity++; },
        requestSubmit() {
            const event = { currentTarget: this, preventDefault() {} };
            gate.handleSubmit(event);
        },
    };

    globalThis.FormData = class FixtureFormData {
        constructor(target) { this.target = target; }
        entries() {
            return this.target.entries.map(([name, value]) => [
                name,
                value instanceof File ? new File([value], value.name, { type: value.type, lastModified: value.lastModified }) : value,
            ])[Symbol.iterator]();
        }
    };

    const render = () => {
        cursor = 0;
        internals.H = {
            useRef(initialValue) {
                const index = cursor++;
                return slots[index] ??= { current: initialValue };
            },
            useState(initialValue) {
                const index = cursor++;
                if (!(index in slots)) slots[index] = typeof initialValue === 'function' ? initialValue() : initialValue;
                return [slots[index], next => { slots[index] = typeof next === 'function' ? next(slots[index]) : next; }];
            },
            useEffect() { cursor++; },
        };
        gate = useConfirmedFormSubmit({ processing: false, onSubmit });
        return gate;
    };

    return {
        form,
        render,
        restore() {
            internals.H = previousDispatcher;
            globalThis.FormData = previousFormData;
        },
    };
}

test('cancel preserves report fields and selected file without sending a request', async () => {
    const { useConfirmedFormSubmit } = await load('Components/Crud/useConfirmedFormSubmit.js');
    let requests = 0;
    const harness = makeHookHarness(useConfirmedFormSubmit, () => { requests++; });
    try {
        let gate = harness.render();
        gate.handleSubmit({ currentTarget: harness.form, preventDefault() {} });
        gate = harness.render();
        assert.equal(gate.confirmationOpen, true);
        gate.cancelConfirmation();
        gate = harness.render();
        assert.equal(gate.confirmationOpen, false);
        assert.equal(requests, 0);
        assert.equal(harness.form.entries[0][1], 'Quarterly report');
        assert.equal(harness.form.entries[1][1].name, 'mov.pdf');
    } finally {
        harness.restore();
    }
});

test('confirm submits the unchanged payload once and blocks a rapid duplicate', async () => {
    const { useConfirmedFormSubmit } = await load('Components/Crud/useConfirmedFormSubmit.js');
    const sent = [];
    const harness = makeHookHarness(useConfirmedFormSubmit, event => {
        event.preventDefault();
        sent.push(harness.form.entries.map(([name, value]) => [name, value]));
    });
    try {
        let gate = harness.render();
        gate.handleSubmit({ currentTarget: harness.form, preventDefault() {} });
        gate = harness.render();
        gate.confirmSubmit();
        gate.confirmSubmit();
        assert.equal(sent.length, 1);
        assert.deepEqual(sent[0].map(([name, value]) => [name, name === 'attachment' ? value.name : value]), [
            ['title', 'Quarterly report'], ['attachment', 'mov.pdf'],
        ]);
        gate = harness.render();
        assert.equal(gate.confirmationSubmitting, true);
        assert.equal(gate.confirmationOpen, true);
    } finally {
        harness.restore();
    }
});

test('a changed form requires a new confirmation and invalid input never opens the dialog', async () => {
    const { useConfirmedFormSubmit } = await load('Components/Crud/useConfirmedFormSubmit.js');
    let requests = 0;
    const harness = makeHookHarness(useConfirmedFormSubmit, () => { requests++; });
    try {
        let gate = harness.render();
        gate.handleSubmit({ currentTarget: harness.form, preventDefault() {} });
        harness.form.entries[0][1] = 'Updated report';
        gate = harness.render();
        gate.confirmSubmit();
        gate = harness.render();
        assert.equal(gate.confirmationChanged, true);
        assert.equal(requests, 0);
        gate.confirmSubmit();
        assert.equal(requests, 1);

        const invalidHarness = makeHookHarness(useConfirmedFormSubmit, () => { requests++; });
        try {
            invalidHarness.form.valid = false;
            const invalidGate = invalidHarness.render();
            invalidGate.handleSubmit({ currentTarget: invalidHarness.form, preventDefault() {} });
            const afterInvalid = invalidHarness.render();
            assert.equal(afterInvalid.confirmationOpen, false);
            assert.equal(invalidHarness.form.reportedValidity, 1);
            assert.equal(requests, 1);
        } finally {
            invalidHarness.restore();
        }
    } finally {
        harness.restore();
    }
});
