import RoutingAttachmentField from '@/Components/SubmissionTracking/RoutingAttachmentField';

export default function CorrectionReferenceAttachment({ action, file, onChange, error, disabled, processing, uploadProgress }) {
    if (!action?.correction_reference_allowed) return null;

    return <RoutingAttachmentField
        file={file}
        onChange={onChange}
        error={error}
        disabled={disabled}
        processing={processing}
        uploadProgress={uploadProgress}
        attachmentAllowed
        correctionAttachment
        hideCurrentDocument
        attachmentTitle="Correction Reference (Optional)"
        attachmentHelperText="Attach a separate reference for this correction return. This does not replace the current official document."
    />;
}
