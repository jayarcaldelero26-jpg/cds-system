import DatePicker from '@/Components/DatePicker';
import DateConductedRangesField from '@/Components/Reports/DateConductedRangesField';
import { FloatingInput, FloatingSelect } from '@/Components/Form';
import CrudSection from '@/Components/Crud/CrudSection';

/** Existing Conservation report fields for generic PA workflows. */
export default function PamoGenericSubmissionFields({ workflow, form, rangeEnabled, periodField, periodOptions, onActivityChange, onPeriodChange, onDateConductedChange, onDateAccomplishedChange, onRangeChange, onAddRange, onRemoveRange, rowSetKey = 'default', draftMode = false }) {
    const documentOptions = workflow.documents || [];
    const contentRequired = !draftMode;
    const activityValue = typeof form.data.activity_name === 'string' ? form.data.activity_name : '';

    return <CrudSection title="Report information"><div className="cds-report-form-fields-container"><div className="cds-report-form-fields-grid">
        <div className="col-span-full min-w-0"><FloatingInput id="pamo-draft-activity" label="Name of Activity" value={activityValue} onChange={event => onActivityChange(event.target.value)} error={form.errors.activity_name} required={contentRequired && workflow.activity_required !== false} /></div>
        {!workflow.period_represents_document && <div className="min-w-0"><FloatingSelect id="pamo-draft-document" label="Type of Document" value={form.data.document_type || ''} onChange={event => form.setData('document_type', event.target.value)} error={form.errors.document_type} required={contentRequired && workflow.document_type_required === true}><option value="">Select Type of Document</option>{documentOptions.map(document => <option key={document} value={document}>{document === 'Reso' ? 'Resolution' : document}</option>)}</FloatingSelect></div>}
        {!workflow.period_represents_document && <div className="min-w-0"><FloatingSelect id="pamo-draft-period" label={workflow.period_label || 'Reporting Period'} value={form.data[periodField] || ''} onChange={onPeriodChange} error={form.errors[periodField]} required={contentRequired && workflow.period_required === true}><option value="">Select {workflow.period_label || 'Reporting Period'}</option>{periodOptions.map(period => <option key={period.value} value={period.value}>{period.label}</option>)}</FloatingSelect></div>}
        {rangeEnabled
            ? <DateConductedRangesField
                idPrefix="pamo-draft-range"
                rowSetKey={rowSetKey}
                ranges={form.data.date_conducted_ranges || []}
                required={contentRequired && Boolean(workflow.date_conducted_required)}
                groupError={form.errors.date_conducted_ranges}
                rangeError={(_, index) => form.errors[`date_conducted_ranges.${index}.from`] || form.errors[`date_conducted_ranges.${index}.to`]}
                onRangeChange={onRangeChange}
                onAddRange={onAddRange}
                onRemoveRange={onRemoveRange}
            />
            : <div className="min-w-0"><DatePicker id="pamo-draft-date-conducted" label="Date Conducted" actualDate compactSeptember required={contentRequired && Boolean(workflow.date_conducted_required)} value={form.data.date_conducted || ''} onChange={onDateConductedChange} error={form.errors.date_conducted} /></div>}
        <div className="min-w-0"><DatePicker id="pamo-draft-date-accomplished" label="Date Accomplished" actualDate compactSeptember required={contentRequired && workflow.date_accomplished_required !== false} value={form.data.date_accomplished || ''} onChange={onDateAccomplishedChange} error={form.errors.date_accomplished} /></div>
    </div></div></CrudSection>;
}
