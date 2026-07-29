import React from 'react';

export default function SuccessModal({ show, onClose, title = "Success!", message = "Action completed successfully." }) {
    if (!show) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
            {/* Self-contained animation styles para dili na kinahanglan sa Index */}
            <style>{`
                @keyframes stroke {
                    100% { stroke-dashoffset: 0; }
                }
                @keyframes rotateCheck {
                    0% { transform: rotate(-45deg) scale(0); opacity: 0; }
                    50% { transform: rotate(15deg) scale(1.1); opacity: 1; }
                    100% { transform: rotate(0deg) scale(1); opacity: 1; }
                }
                @keyframes popIn {
                    0% { transform: scale(0.8); opacity: 0; }
                    100% { transform: scale(1); opacity: 1; }
                }

                .animate-pop-in {
                    animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
                }
                .checkmark-circle {
                    animation: popIn 0.4s ease-in-out forwards;
                }
                .checkmark-check {
                    stroke-dasharray: 48;
                    stroke-dashoffset: 48;
                    animation: stroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.2s forwards, rotateCheck 0.4s ease-in-out forwards;
                }
            `}</style>

            <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 dark:border-emerald-900 text-center animate-pop-in">
                <div className="checkmark-circle mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-emerald-100 dark:bg-emerald-950 mb-4 shadow-sm">
                    <svg className="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor">
                        <path className="checkmark-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2 font-sans">{title}</h3>
                <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">{message}</p>
                <button
                    onClick={onClose}
                    className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-sm transition text-sm"
                >
                    Continue
                </button>
            </div>
        </div>
    );
}
