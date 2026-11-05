import { useId, useRef } from 'react';
import Button from '@/Components/Button';
import DateRangePicker from '@/Components/DateRangePicker';

export function createDateConductedRowKeyManager(scope) {
    let nextId = 0;
    let keys = [];

    return {
        getKeys(count) {
            while (keys.length < count) keys.push(`${scope}-row-${nextId++}`);
            if (keys.length > count) keys = keys.slice(0, count);
            return [...keys];
        },
        add() {
            const key = `${scope}-row-${nextId++}`;
            keys.push(key);
            return key;
        },
        remove(index) {
            keys = keys.filter((_, rowIndex) => rowIndex !== index);
            return [...keys];
        },
    };
}

export default function DateConductedRangesField({
    idPrefix = 'date-conducted-range',
    rowSetKey = 'default',
    ranges = [],
    required = false,
    groupError = '',
    rangeError = () => '',
    onRangeChange = () => {},
    onAddRange = () => {},
    onRemoveRange = () => {},
}) {
    const reactId = useId().replace(/:/g, '');
    const groupId = `date-conducted-${reactId}`;
    const rangeIdPrefix = `${idPrefix}-${reactId}`;
    const managerRef = useRef(null);
    const generationRef = useRef(0);
    const actionRefs = useRef(new Map());
    const addButtonRef = useRef(null);

    if (!managerRef.current || !Object.is(managerRef.current.rowSetKey, rowSetKey)) {
        const scope = `${rangeIdPrefix}-set-${generationRef.current++}`;
        managerRef.current = { rowSetKey, manager: createDateConductedRowKeyManager(scope) };
    }

    const manager = managerRef.current.manager;
    const rowKeys = manager.getKeys(ranges.length);
    const hintId = `${groupId}-guidance`;
    const groupErrorId = `${groupId}-error`;
    const describedBy = [hintId, groupError ? groupErrorId : ''].filter(Boolean).join(' ');

    const addRange = () => {
        manager.add();
        onAddRange();
    };

    const removeRange = index => {
        const remainingKeys = manager.remove(index);
        const focusKey = remainingKeys[index] || remainingKeys[index - 1] || '';
        onRemoveRange(index);
        window.requestAnimationFrame(() => {
            if (focusKey && actionRefs.current.has(focusKey)) actionRefs.current.get(focusKey).focus();
            else addButtonRef.current?.focus();
        });
    };

    return <fieldset className="cds-date-conducted-group col-span-full min-w-0" aria-describedby={describedBy} data-date-conducted-group>
        <legend className="mb-1 text-xs font-semibold text-gray-700 dark:text-gray-200">
            Date Conducted {required && <><span aria-hidden="true" className="text-red-600 dark:text-red-400">*</span><span className="sr-only"> required</span></>}
        </legend>
        <p id={hintId} className="mb-2 text-xs leading-5 text-gray-500 dark:text-gray-400" data-date-conducted-guidance>
            Choose today or an earlier date (Philippines time).
        </p>
        <div className="space-y-3" data-date-conducted-list>
            {ranges.map((range, index) => {
                const rowKey = rowKeys[index];
                const id = `${rangeIdPrefix}-${index}`;
                return <div key={rowKey} className="cds-date-conducted-row min-w-0" data-date-conducted-row>
                    <DateRangePicker
                        id={id}
                        label={`Date ${index + 1}`}
                        required={required}
                        actualDate
                        showActualDateHelper={false}
                        describedBy={describedBy}
                        compactSeptember
                        value={{ from: range?.from || '', to: range?.to || '' }}
                        onChange={value => onRangeChange(index, value)}
                        error={rangeError(range, index) || ''}
                        className="min-w-0"
                    />
                    <Button
                        ref={node => {
                            if (node) actionRefs.current.set(rowKey, node);
                            else actionRefs.current.delete(rowKey);
                        }}
                        type="button"
                        size="compact"
                        variant="danger"
                        onClick={() => removeRange(index)}
                        className="cds-date-conducted-remove cds-compact-action"
                    >Remove</Button>
                </div>;
            })}
        </div>
        {groupError && <p id={groupErrorId} className="mt-2 text-sm leading-5 text-red-700 dark:text-red-300" role="alert">{groupError}</p>}
        <Button
            ref={addButtonRef}
            type="button"
            size="compact"
            variant="primary"
            onClick={addRange}
            className="cds-compact-action mt-3"
        >+ Add Date</Button>
    </fieldset>;
}
