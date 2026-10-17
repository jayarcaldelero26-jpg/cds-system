import './PamoReportPendingAnimation.css';

const phases = {
    save: ['Saving draft…', 'Please wait while your draft is saved.'],
    submit: ['Submitting report…', 'Please wait while the report is submitted.'],
    send: ['Sending document', 'Please wait while the current document and routing action are saved.'],
    update: ['Updating document', 'Please wait while the official document update is saved.'],
    receive: ['Receiving final documents', 'The receipt and signed files are being recorded before archive verification.'],
    archive: ['Uploading / verifying archive', 'The server is checking every final file against its private Drive copy.'],
    retry: ['Uploading / verifying archive', 'The saved receipt and exact signed set are being checked again.'],
    review: ['Submitting MOV review', 'The review request and its custody action are being recorded.'],
};

export default function PamoReportPendingAnimation({ intent = 'save' }) {
    const [message, subtitle] = phases[intent] || phases.save;

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
            <p className="cds-pamo-pending__subtitle">{subtitle}</p>
            <div className="cds-pamo-pending__dots" aria-hidden="true"><span className="cds-pamo-pending__dot" /><span className="cds-pamo-pending__dot" /><span className="cds-pamo-pending__dot" /></div>
        </section>
    </div>;
}
