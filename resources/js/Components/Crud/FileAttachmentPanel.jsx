import React from 'react';
import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';

export default function FileAttachmentPanel(props) {
    return <AttachmentDropzone
        {...props}
        files={props.selectedFiles}
        existingAttachments={props.existingFiles}
        helperText={props.helperText || props.acceptedTypesHint}
    />;
}