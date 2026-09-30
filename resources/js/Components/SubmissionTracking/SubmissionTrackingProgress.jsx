import { progressMeasureForSubmission } from "@/Utils/submissionTrackingPresentation";

export default function SubmissionTrackingProgress({ row }) {
    const measure = progressMeasureForSubmission(row);
    if (!measure) return null;
    const { label, value: progress } = measure;

    return (
        <div className="w-full" aria-label={label}>
            <div className="mb-2 flex items-center justify-between gap-3">
                <p className="text-xs font-semibold text-gray-800 dark:text-slate-200">
                    {label}
                </p>
                <span className="text-xs font-medium tabular-nums text-gray-600 dark:text-slate-300">
                    {Math.round(progress)}%
                </span>
            </div>
            <div
                className="h-3 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
                role="progressbar"
                aria-label={label}
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow={progress}
            >
                <div
                    className="submission-tracking-progress__fill relative h-full overflow-hidden rounded-full bg-gradient-to-r from-green-800 via-emerald-700 to-blue-800 transition-[width]"
                    style={{ width: `${progress}%` }}
                >
                    <span className="submission-tracking-progress__sweep" aria-hidden="true" />
                </div>
            </div>
        </div>
    );
}
