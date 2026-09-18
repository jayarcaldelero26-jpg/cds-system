<?php

test('shared attachment dropzone keeps click browse and drag drop on the same validated input path', function (): void {
    $source = file_get_contents(resource_path('js/Components/Attachments/AttachmentDropzone.jsx'));

    expect($source)
        ->toContain("onClick={()=>input.current?.click()}")
        ->toContain("onKeyDown={e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();input.current?.click();}}}")
        ->toContain('onDrop={e=>{e.preventDefault();setDragging(false);add(e.dataTransfer.files);}}')
        ->toContain('<input ref={input} type="file"')
        ->toContain('accept={accept}')
        ->toContain('disabled={disabled||!canManage}')
        ->toContain('file.name} is not an allowed file type.')
        ->toContain('file.name} exceeds the maximum file size.');
});

test('file attachment wrappers reuse the shared attachment dropzone', function (): void {
    expect(file_get_contents(resource_path('js/Components/Crud/FileAttachmentPanel.jsx')))
        ->toContain("import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';")
        ->toContain('<AttachmentDropzone');

    expect(file_get_contents(resource_path('js/Components/SubmissionTracking/RoutingAttachmentField.jsx')))
        ->toContain("import AttachmentDropzone from '@/Components/Attachments/AttachmentDropzone';")
        ->toContain('<AttachmentDropzone');
});
