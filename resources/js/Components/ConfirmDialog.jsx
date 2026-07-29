export default function ConfirmDialog({
    open,
    title = "Are you sure you want to change the data?",
    message = "Once update, you will not be able to revert it!",
    confirmLabel = "OK",
    cancelLabel = "Cancel",
    onConfirm,
    onCancel
}) {
    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
            <style>{`
                @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
            `}</style>
            <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full shadow-2xl border border-gray-200 dark:border-gray-700 text-center animate-pop-in">
                <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-teal-100 dark:bg-teal-950 text-teal-600 dark:text-teal-400 mb-4 shadow-sm text-2xl font-bold">
                    ?
                </div>
                <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-2">{title}</h3>
                <p className="text-sm text-gray-500 dark:text-gray-400 mb-6">{message}</p>
                <div className="flex justify-center gap-3">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="flex-1 rounded-xl bg-gray-200 dark:bg-gray-700 px-4 py-2.5 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600 transition"
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        className="flex-1 rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-700 transition shadow-md"
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
