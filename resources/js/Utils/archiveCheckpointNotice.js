export function archiveCheckpointPendingNotice(processing, source, stage) {
    if (!processing || source !== 'conservation' || stage !== 'dispatch_penro_records_to_cds_focal') return null;

    return {
        title: 'Archive checkpoint in progress',
        message: 'CDS-SMART is verifying the report in the existing archive before finishing this dispatch. Keep this window open; the action remains disabled while it is processing.',
    };
}
