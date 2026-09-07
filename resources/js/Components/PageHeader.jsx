import { Icon } from '@iconify/react';
import monitoringMountainForest from '../../images/dashboard/monitoring-mountain-forest.png';

const primaryOverlay = 'linear-gradient(90deg, rgba(5, 120, 62, 0.90) 0%, rgba(10, 124, 66, 0.78) 45%, rgba(4, 94, 52, 0.74) 100%)';
const depthOverlay = 'linear-gradient(to top, rgba(0, 55, 30, 0.48) 0%, rgba(0, 55, 30, 0.10) 45%, transparent 70%)';

export default function PageHeader({
    title,
    description,
    subtitle,
    icon = 'solar:document-text-linear',
    eyebrow,
    rightContent,
    actions,
    compact = false,
    periodTitle,
    periodSubtitle,
    variant = 'default',
    className = '',
}) {
    const supportingText = subtitle || description;
    const metadata = periodTitle || periodSubtitle ? (
        <div className="shrink-0 text-left sm:text-right">
            {periodTitle && <p className="text-sm font-bold text-white">{periodTitle}</p>}
            {periodSubtitle && <p className="mt-1 text-xs text-green-200">Monitoring period: {periodSubtitle}</p>}
        </div>
    ) : null;

    return (
        <section
            className={`relative isolate overflow-hidden rounded-2xl bg-cover bg-center text-white shadow-md ${compact ? 'min-h-[88px] px-5 py-4 sm:px-6' : 'min-h-[104px] px-6 py-[18px] sm:px-7 sm:py-5'} ${className}`}
            data-page-header-variant={variant}
            style={{ backgroundImage: `url(${monitoringMountainForest})`, backgroundPosition: 'center 58%', backgroundRepeat: 'no-repeat' }}
        >
            <div className="pointer-events-none absolute inset-0" style={{ background: primaryOverlay }} aria-hidden="true" />
            <div className="pointer-events-none absolute inset-0" style={{ background: depthOverlay }} aria-hidden="true" />

            <div className={`relative z-10 flex ${compact ? 'min-h-[56px]' : 'min-h-[68px]'} flex-col justify-center gap-3 sm:flex-row sm:items-center sm:justify-between`}>
                <div className="flex min-w-0 items-start gap-3">
                    {icon && <span className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/25 bg-white/10 text-green-50 shadow-inner">
                        <Icon icon={icon} width="20" height="20" aria-hidden="true" />
                    </span>}
                    <div className="min-w-0">
                        {eyebrow && <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.14em] text-green-100/85">{eyebrow}</p>}
                        <h1 className="text-xl font-bold tracking-tight text-white sm:text-2xl">{title}</h1>
                        {supportingText && <p className="mt-1 max-w-3xl text-xs leading-5 text-green-100 sm:text-sm">{supportingText}</p>}
                    </div>
                </div>

                {(metadata || rightContent || actions) && <div className="flex flex-wrap items-center justify-start gap-3 sm:justify-end">
                    {metadata}
                    {rightContent}
                    {actions && <div className="flex flex-wrap items-center justify-start gap-2 sm:justify-end">{actions}</div>}
                </div>}
            </div>
        </section>
    );
}
