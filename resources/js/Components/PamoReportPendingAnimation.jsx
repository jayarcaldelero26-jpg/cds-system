import './PamoReportPendingAnimation.css';

export default function PamoReportPendingAnimation({ intent = 'save' }) {
    const submitting = intent === 'submit';
    const message = submitting ? 'Submitting report…' : 'Saving draft…';

    return <div className="cds-pamo-pending">
        <section className="cds-pamo-pending__popup" role="status" aria-live="polite" aria-atomic="true" aria-busy="true" data-cds-busy-status tabIndex={-1}>
            <p className="cds-pamo-pending__brand">CDS-SMART</p>
            <div className="cds-pamo-pending__scene" aria-hidden="true">
                <div className="cds-pamo-pending__glow" />
                <div className="cds-pamo-pending__flight-trail" />
                <div className="cds-pamo-pending__paper cds-pamo-pending__paper-back"><span /><span /><span /></div>
                <div className="cds-pamo-pending__paper"><span /><span /><span /></div>
            </div>
            <h3 className="cds-pamo-pending__title">{message}</h3>
            <p className="cds-pamo-pending__subtitle">Please wait while your report {submitting ? 'is submitted.' : 'is saved.'}</p>
            <div className="cds-pamo-pending__dots" aria-hidden="true"><span className="cds-pamo-pending__dot" /><span className="cds-pamo-pending__dot" /><span className="cds-pamo-pending__dot" /></div>
        </section>
    </div>;
}
