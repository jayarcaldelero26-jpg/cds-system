export function executeRecipientDelete({
    action,
    actionInFlight,
    route,
    router,
    setActionError,
    setSending,
    setSelectedRecipient,
    setConfirmAction,
    editingRecipientId,
    resetRecipient,
}) {
    const recipient = action?.recipient;
    if (action?.type !== 'delete-recipient' || !recipient?.id || actionInFlight.current) return false;

    const deletedId = recipient.id;
    actionInFlight.current = true;
    setActionError('');
    setSending(true);

    router.delete(route('compliance-alerts.recipients.destroy', deletedId), {
        preserveScroll: true,
        onSuccess: () => {
            setActionError('');
            setSelectedRecipient(current => current?.id === deletedId ? null : current);
            setConfirmAction(current => current?.recipient?.id === deletedId ? null : current);
            if (editingRecipientId === deletedId) resetRecipient();
        },
        onError: errors => {
            const message = Object.values(errors || {}).find(value => typeof value === 'string' && value.trim());
            setActionError(message || 'The action was not completed. Review the current access and record state, then try again.');
        },
        onHttpException: () => setActionError('The server could not complete this action. Your current record remains open; please review it and try again.'),
        onNetworkError: () => setActionError('The request did not reach the server. Your current record remains open; check the connection and try again.'),
        onFinish: () => {
            setSending(false);
            actionInFlight.current = false;
        },
    });

    return true;
}

export function cancelRecipientDelete({ sending, setActionError, setConfirmAction }) {
    if (sending) return false;
    setActionError('');
    setConfirmAction(null);
    return true;
}
