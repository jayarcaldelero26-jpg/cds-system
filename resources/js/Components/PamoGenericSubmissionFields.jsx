import Button from '@/Components/Button';
import DatePicker from '@/Components/DatePicker';
import DateRangePicker from '@/Components/DateRangePicker';
import { FloatingInput, FloatingSelect } from '@/Components/Form';
import ScopedOptionSelect from '@/Components/Form/ScopedOptionSelect';
import CrudSection from '@/Components/Crud/CrudSection';

/** Existing Conservation report fields for catalog-backed generic PA workflows. */
export default function PamoGenericSubmissionFields({ workflow, form, rangeEnabled, periodField, periodOptions, onActivityChange, onPeriodChange, onDateConductedChange, onDateAccomplishedChange, onRangeChange, onAddRange, onRemoveRange, fieldError, draftMode = false }) {
    const documentOptions = (workflow.activity_documents || {})[form.data.activity_name] || workflow.documents || [];
    const contentRequired = !draftMode;

    return <CrudSection title="Report information"><div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {workflow.activities?.length > 1
            ? <ScopedOptionSelect id="pamo-draft-activity" label="Name of Activity" value={form.data.activity_name || ''} options={workflow.activities.map(activity => ({ id: activity, label: activity }))} onChange={onActivityChange} error={form.errors.activity_name} placeholder="Search activities" required={contentRequired && workflow.activity_required !== false} />
            : <FloatingInput id="pamo-draft-activity" label="Name of Activity" value={form.data.activity_name || ''} onChange={onActivityChange} error={form.errors.activity_name} required={contentRequired && workflow.activity_required !== false} />}
        {!workflow.period_represents_document && <FloatingSelect id="pamo-draft-document" label="Type of Document" value={form.data.document_type || ''} onChange={event => form.setData('document_type', event.target.value)} error={form.errors.document_type} required={contentRequired && workflow.document_type_required === true}><option value="">Select Type of Document</option>{documentOptions.map(document => <option key={document} value={document}>{document === 'Reso' ? 'Resolution' : document}</option>)}</FloatingSelect>}
        {!workflow.period_represents_document && <FloatingSelect id="pamo-draft-period" label={workflow.period_label || 'Reporting Period'} value={form.data[periodField] || ''} onChange={onPeriodChange} error={form.errors[periodField]} required={contentRequired && workflow.period_required === true}><option value="">Select {workflow.period_label || 'Reporting Period'}</option>{periodOptions.map(period => <option key={period.value} value={period.value}>{period.label}</option>)}</FloatingSelect>}
        {rangeEnabled
            ? <div className="sm:col-span-2 space-y-2"><p className="text-xs font-semibold text-gray-700 dark:text-gray-200">Date Conducted {contentRequired && workflow.date_conducted_required && <span className="text-red-600">*</span>}</p>{(form.data.date_conducted_ranges || []).map((range, index) => <div key={index} className="flex flex-wrap items-end gap-2"><div className="min-w-52 flex-1"><DateRangePicker id={`pamo-draft-range-${index}`} label={`Date ${index + 1}`} required={contentRequired && Boolean(workflow.date_conducted_required)} actualDate compactSeptember value={range} onChange={value => onRangeChange(index, value)} error={form.errors[`date_conducted_ranges.${index}.from`] || form.errors[`date_conducted_ranges.${index}.to`]} /></div><Button type="button" size="default" variant="danger" onClick={() => onRemoveRange(index)}>Remove</Button></div>)}<Button type="button" size="default" variant="primary" onClick={onAddRange}>+ Add Date</Button>{fieldError('date_conducted_ranges')}</div>
            : <DatePicker id="pamo-draft-date-conducted" label="Date Conducted" actualDate compactSeptember required={contentRequired && Boolean(workflow.date_conducted_required)} value={form.data.date_conducted || ''} onChange={onDateConductedChange} error={form.errors.date_conducted} />}
        <DatePicker id="pamo-draft-date-accomplished" label="Date Accomplished" actualDate compactSeptember required={contentRequired && workflow.date_accomplished_required !== false} value={form.data.date_accomplished || ''} onChange={onDateAccomplishedChange} error={form.errors.date_accomplished} />
    </div></CrudSection>;
}
