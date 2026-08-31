import { createElement, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudTable from '@/Components/Crud/CrudTable';
import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatReportDateTime } from '@/Utils/dateFormatters';

const auditDash = '\u2014';
const metadataLabels = {
    recipient_name: 'Recipient Name',
    recipient_email: 'Recipient Email',
    target_office: 'Target Office',
    target_office_key: 'Target Office Key',
    protected_area_id: 'Protected Area',
    is_active: 'Status',
    cc_emails: 'CC Emails',
    attention_line: 'Attention Designation',
    notes: 'Notes',
};

const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const isSensitiveKey = key => ['password', 'current_password', 'app_key', 'db_password', 'token', 'session'].includes(String(key).toLowerCase())
    || String(key).toLowerCase().includes('credential');
const labelFor = key => metadataLabels[key] || String(key).replace(/[_-]+/g, ' ').replace(/\b\w/g, character => character.toUpperCase());

function parseStructuredValue(value) {
    if (typeof value !== 'string') return value;
    const text = value.trim();
    if (!text.startsWith('{') && !text.startsWith('[')) return value;
    try {
        return JSON.parse(text);
    } catch {
        return value;
    }
}

function readableScalar(value, fieldKey = '') {
    if (value === null || value === undefined || value === '') return auditDash;
    if (typeof value === 'boolean') {
        return fieldKey === 'is_active' ? (value ? 'Active' : 'Inactive') : (value ? 'Yes' : 'No');
    }
    return String(value);
}

function ReadableValue({ value, fieldKey = '' }) {
    const parsed = parseStructuredValue(value);
    if (Array.isArray(parsed)) {
        if (parsed.length === 0) return createElement('span', { className: 'text-gray-500' }, auditDash);
        return createElement(
            'div',
            { className: 'space-y-1' },
            ...parsed.map((item, index) => createElement(
                'div',
                { key: index, className: 'whitespace-pre-wrap break-words' },
                isRecord(item) ? createElement(ReadableValue, { value: item }) : readableScalar(item, fieldKey),
                index < parsed.length - 1 ? ', ' : '',
            )),
        );
    }
    if (isRecord(parsed)) {
        const entries = Object.entries(parsed).filter(([key]) => !isSensitiveKey(key));
        if (entries.length === 0) return createElement('span', { className: 'text-gray-500' }, auditDash);
        return createElement(
            'div',
            { className: 'space-y-1 rounded-lg bg-gray-50 p-2 dark:bg-gray-800/70' },
            ...entries.map(([key, nestedValue]) => createElement(
                'div',
                { key, className: 'grid grid-cols-1 gap-1 sm:grid-cols-[10rem_minmax(0,1fr)]' },
                createElement('span', { className: 'font-medium text-gray-500' }, labelFor(key)),
                createElement(ReadableValue, { value: nestedValue, fieldKey: key }),
            )),
        );
    }
    return createElement('span', { className: 'whitespace-pre-wrap break-words' }, readableScalar(parsed, fieldKey));
}

function changedFields(before, after) {
    if (!isRecord(before) || !isRecord(after)) return [];
    return [...new Set([...Object.keys(before), ...Object.keys(after)])]
        .filter(key => !isSensitiveKey(key))
        .filter(key => JSON.stringify(parseStructuredValue(before[key])) !== JSON.stringify(parseStructuredValue(after[key])));
}

function MetadataRow({ label, value, fieldKey }) {
    return createElement(
        'div',
        { className: 'grid grid-cols-1 gap-1 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800 sm:grid-cols-[12rem_minmax(0,1fr)]' },
        createElement('span', { className: 'font-semibold text-gray-500' }, label),
        createElement(ReadableValue, { value, fieldKey }),
    );
}

function AuditSummary({ record }) {
    const items = [
        ['Date & Time', formatReportDateTime(record?.created_at, auditDash)],
        ['Actor', record?.actor || auditDash],
        ['Action', record?.action || auditDash],
        ['Module / Entity', record?.module || record?.entity_type || auditDash],
        ['Record', record?.entity_id || auditDash],
        ['Summary', record?.summary || auditDash],
        ['Updated', formatReportDateTime(record?.updated_at, auditDash)],
    ];

    return createElement(
        'div',
        { className: 'grid grid-cols-1 gap-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50 sm:grid-cols-2' },
        ...items.map(([label, value]) => createElement(
            'div',
            { key: label, className: 'min-w-0' },
            createElement('p', { className: 'text-[11px] font-semibold uppercase text-gray-500 dark:text-gray-400' }, label),
            createElement('div', { className: 'mt-1 whitespace-normal break-words text-sm font-semibold text-gray-900 dark:text-white' }, value),
        )),
    );
}

function AuditDetailContent({ detail, state }) {
    if (state === 'loading') return createElement('p', { className: 'text-sm text-gray-500' }, 'Loading audit log details...');
    if (state === 'error') return createElement('p', { className: 'text-sm text-red-600' }, 'Audit log details could not be loaded. Close this window and try again.');
    if (!detail) return null;

    const metadata = detail.metadata && isRecord(detail.metadata) ? detail.metadata : {};
    const before = parseStructuredValue(metadata.before);
    const after = parseStructuredValue(metadata.after);
    const changed = changedFields(before, after);
    const genericEntries = Object.entries(metadata)
        .filter(([key]) => changed.length === 0 || !['before', 'after'].includes(key))
        .filter(([key]) => !isSensitiveKey(key));
    const changedSection = changed.length > 0
        ? createElement(
            'section',
            { className: 'space-y-3' },
            createElement('h3', { className: 'text-xs font-extrabold uppercase tracking-wide text-gray-500' }, 'Changed Fields'),
            createElement(
                'div',
                { className: 'overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700' },
                createElement(
                    'table',
                    { className: 'min-w-full text-left text-sm' },
                    createElement('thead', { className: 'bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400' },
                        createElement('tr', null,
                            createElement('th', { className: 'px-4 py-3 font-semibold' }, 'Field'),
                            createElement('th', { className: 'px-4 py-3 font-semibold' }, 'Before'),
                            createElement('th', { className: 'px-4 py-3 font-semibold' }, 'After'),
                        ),
                    ),
                    createElement('tbody', { className: 'divide-y divide-gray-100 dark:divide-gray-800' },
                        ...changed.map(key => createElement('tr', { key },
                            createElement('th', { className: 'min-w-40 px-4 py-3 align-top font-semibold text-gray-600 dark:text-gray-300' }, labelFor(key)),
                            createElement('td', { className: 'min-w-48 px-4 py-3 align-top' }, createElement(ReadableValue, { value: before[key], fieldKey: key })),
                            createElement('td', { className: 'min-w-48 px-4 py-3 align-top' }, createElement(ReadableValue, { value: after[key], fieldKey: key })),
                        )),
                    ),
                ),
            ),
        )
        : null;
    const metadataSection = genericEntries.length > 0
        ? createElement(
            'section',
            { className: 'space-y-3' },
            createElement('h3', { className: 'text-xs font-extrabold uppercase tracking-wide text-gray-500' }, 'Metadata'),
            createElement('div', { className: 'space-y-2 rounded-xl border border-gray-200 p-4 dark:border-gray-700' },
                ...genericEntries.map(([key, value]) => createElement(MetadataRow, { key, label: labelFor(key), value, fieldKey: key })),
            ),
        )
        : changed.length === 0
            ? createElement('p', { className: 'text-sm text-gray-500' }, 'No additional metadata recorded.')
            : null;
    const technicalRows = [
        detail.ip_address ? createElement(MetadataRow, { key: 'ip_address', label: 'IP Address', value: detail.ip_address }) : null,
        detail.user_agent ? createElement(MetadataRow, { key: 'user_agent', label: 'User Agent', value: detail.user_agent }) : null,
    ].filter(Boolean);
    const technicalSection = createElement(
        'section',
        { className: 'space-y-3' },
        createElement('h3', { className: 'text-xs font-extrabold uppercase tracking-wide text-gray-500' }, 'Technical Details'),
        technicalRows.length > 0
            ? createElement('div', { className: 'space-y-2 rounded-xl border border-gray-200 p-4 dark:border-gray-700' }, ...technicalRows)
            : createElement('p', { className: 'text-sm text-gray-500' }, 'No technical details recorded.'),
    );

    return createElement('div', { className: 'space-y-5' }, changedSection, metadataSection, technicalSection);
}

export default function AuditLogList({ logs = { data: [] }, filters = {}, eventTypes = [] }) {
    const [selected, setSelected] = useState(null);
    const [detail, setDetail] = useState(null);
    const [detailState, setDetailState] = useState('idle');
    const requestId = useRef(0);
    const [search, setSearch] = useState(filters.search || '');

    const apply = changes => router.get(
        route('audit-logs.index'),
        { ...filters, search: search || undefined, ...changes },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const openDetails = async log => {
        const currentRequest = ++requestId.current;
        setSelected(log);
        setDetail(null);
        setDetailState('loading');

        try {
            const response = await fetch(route('audit-logs.show', log.id), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Unable to load audit log details.');
            const payload = await response.json();
            if (currentRequest === requestId.current) {
                setDetail(payload);
                setDetailState('ready');
            }
        } catch {
            if (currentRequest === requestId.current) setDetailState('error');
        }
    };

    const closeDetails = () => {
        requestId.current += 1;
        setSelected(null);
        setDetail(null);
        setDetailState('idle');
    };

    const columns = [
        { key: 'created_at', label: 'Date & Time', render: row => formatReportDateTime(row.created_at, auditDash) },
        { key: 'actor', label: 'Actor', render: row => row.actor || auditDash },
        { key: 'action', label: 'Action', render: row => createElement('span', { className: 'whitespace-normal break-words font-semibold text-gray-900 dark:text-white' }, row.action || auditDash) },
        { key: 'module', label: 'Module / Entity', render: row => row.module || row.entity_type || auditDash },
        { key: 'entity_id', label: 'Record', render: row => row.entity_id || auditDash },
        { key: 'summary', label: 'Summary', render: row => createElement('span', { className: 'whitespace-normal break-words' }, row.summary || auditDash) },
    ];
    const shown = detail || selected;
    const inputClass = 'h-10 rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-800';
    const filtersPanel = createElement(
        'div',
        { className: 'grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-4 dark:border-gray-800 dark:bg-gray-900' },
        createElement('input', { value: search, onChange: event => setSearch(event.target.value), onKeyDown: event => event.key === 'Enter' && apply({}), placeholder: 'Search', className: inputClass }),
        createElement('select', { defaultValue: filters.event_type || '', onChange: event => apply({ event_type: event.target.value || undefined }), className: inputClass }, createElement('option', { value: '' }, 'All event types'), ...eventTypes.map(type => createElement('option', { key: type }, type))),
        createElement('input', { type: 'date', defaultValue: filters.date_from || '', onChange: event => apply({ date_from: event.target.value || undefined }), className: inputClass }),
        createElement('input', { type: 'date', defaultValue: filters.date_to || '', onChange: event => apply({ date_to: event.target.value || undefined }), className: inputClass }),
    );
    const pagination = logs.links?.length > 3 ? createElement(
        'div',
        { className: 'flex flex-wrap gap-1' },
        ...logs.links.map((link, index) => createElement('button', {
            key: index,
            type: 'button',
            disabled: !link.url,
            onClick: () => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true }),
            className: 'rounded-lg px-3 py-1.5 text-xs font-bold transition ' + (link.active ? 'bg-green-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-green-50 dark:bg-gray-700 dark:text-gray-200') + ' disabled:cursor-not-allowed disabled:opacity-40',
            dangerouslySetInnerHTML: { __html: link.label },
        })),
    ) : null;

    return createElement(
        AuthenticatedLayout,
        { title: 'Audit Logs' },
        createElement(PageHeader, { title: 'Audit Logs', description: 'Read-only record of important administrative and workflow changes.' }),
        createElement(
            'div',
            { className: 'mt-6 space-y-4' },
            filtersPanel,
            createElement(CrudTable, {
                title: 'Audit Log',
                subtitle: 'Important state changes only; page views are not recorded.',
                columns,
                rows: logs.data || [],
                rowKey: 'id',
                onRowClick: openDetails,
                emptyTitle: 'No audit events',
                emptyDescription: 'Administrative and workflow events will appear here.',
                pagination: logs.data?.length > 0 ? pagination : null,
                tableClassName: 'min-w-[1050px]',
            }),
            createElement(CrudDetailsModal, {
                open: Boolean(selected),
                title: 'Audit Log Details',
                subtitle: '',
                onClose: closeDetails,
                summary: shown ? createElement(AuditSummary, { record: shown }) : null,
            }, createElement(AuditDetailContent, { detail, state: detailState })),
        ),
    );
}
