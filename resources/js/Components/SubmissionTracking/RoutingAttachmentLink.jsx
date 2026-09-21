import { useState } from "react";
import { downloadWithProgress } from "@/Utils/downloadWithProgress";

const size = (value) =>
    !Number.isFinite(Number(value))
        ? null
        : Number(value) < 1024 * 1024
          ? `${(Number(value) / 1024).toFixed(1)} KB`
          : `${(Number(value) / 1024 / 1024).toFixed(1)} MB`;

export default function RoutingAttachmentLink({ attachment }) {
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState("");
    if (!attachment) return null;
    const download = async () => {
        setError("");
        setProgress({});
        try {
            await downloadWithProgress(
                attachment.download_url,
                attachment.name,
                setProgress,
            );
            setProgress({ percentage: 100, complete: true });
            setTimeout(() => setProgress(null), 1600);
        } catch (e) {
            setProgress(null);
            setError(e.message || "Download failed.");
        }
    };
    const percent = Number.isFinite(Number(progress?.percentage))
        ? Math.max(0, Math.min(100, Number(progress.percentage)))
        : null;
    return (
        <div className="mt-2 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-2 text-[11px] dark:border-slate-700 dark:bg-slate-900/50">
            <p className="font-bold text-slate-700 dark:text-slate-200">
                Attachment
            </p>
            <p className="mt-0.5 truncate text-slate-600 dark:text-slate-300">
                {attachment.name}
                {size(attachment.size) ? ` · ${size(attachment.size)}` : ""}
            </p>
            <div className="mt-1.5 flex gap-2">
                {attachment.preview_url && (
                    <a
                        href={attachment.preview_url}
                        target="_blank"
                        rel="noreferrer"
                        className="font-bold text-green-800 hover:underline"
                    >
                        Preview
                    </a>
                )}
                <button
                    type="button"
                    disabled={Boolean(progress)}
                    onClick={download}
                    className="font-bold text-green-800 hover:underline disabled:opacity-50"
                >
                    {progress ? "Downloading…" : "Download"}
                </button>
            </div>
            {progress && (
                <>
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded bg-green-100">
                        {percent === null ? (
                            <div className="h-full w-1/3 animate-pulse bg-green-600" />
                        ) : (
                            <div
                                className="h-full bg-green-600"
                                style={{ width: `${percent}%` }}
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow={percent}
                            />
                        )}
                    </div>
                    <p className="mt-1 text-slate-500">
                        {progress.complete
                            ? "Download complete"
                            : percent === null
                              ? "Downloading document…"
                              : `${percent}%${progress.total ? ` · ${size(progress.loaded)} / ${size(progress.total)}` : ""}`}
                    </p>
                </>
            )}
            {error && (
                <p className="mt-1 text-red-700" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}
