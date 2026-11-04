import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import CrudFormModal from '@/Components/Crud/CrudFormModal';

function ModalFocusFixture() {
    const [open, setOpen] = useState(false);
    const [title, setTitle] = useState('');
    window.__modalFocusQa = { requests: 0 };

    return <main className="p-8">
        <button type="button" id="launcher" onClick={() => setOpen(true)}>Open isolated form</button>
        {open && <CrudFormModal
            open
            mode="create"
            title="Isolated QA form"
            subtitle="Mocked save callback only"
            onClose={() => setOpen(false)}
            onSubmit={event => {
                event.preventDefault();
                window.__modalFocusQa.requests += 1;
            }}
            saveLabel="Create isolated record"
            confirmation={{
                title: 'Confirm isolated action?',
                message: 'This fixture only increments an in-memory counter.',
                confirmLabel: 'Save isolated action',
                cancelLabel: 'Go Back',
            }}
            motion="none"
            maxWidth="max-w-md"
        >
            <label className="block" htmlFor="fixture-title">Title</label>
            <input
                id="fixture-title"
                name="title"
                type="text"
                required
                value={title}
                onChange={event => setTitle(event.target.value)}
            />
        </CrudFormModal>}
    </main>;
}

createRoot(document.getElementById('root')).render(<ModalFocusFixture />);
