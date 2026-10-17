import { Link } from '@inertiajs/react';

function paginationLabel(value) {
    return String(value).replaceAll('&laquo;', '«').replaceAll('&raquo;', '»');
}

export default function LaravelPaginationLinks({ links = [], visitOptions = {} }) {
    if (!Array.isArray(links) || links.length <= 3) return null;

    return <nav aria-label="Pagination">
        <div className="flex flex-wrap gap-1">
            {links.map((link, index) => {
                const label = paginationLabel(link.label);
                const className = `cds-pagination-control rounded-lg px-3 py-1.5 text-xs font-bold transition ${link.active ? 'cds-pagination-current' : ''} ${link.url ? '' : 'cursor-not-allowed opacity-50'}`;

                return link.url
                    ? <Link key={`${link.label}-${index}`} href={link.url} preserveState preserveScroll {...visitOptions} aria-current={link.active ? 'page' : undefined} className={className}>{label}</Link>
                    : <span key={`${link.label}-${index}`} aria-disabled="true" className={className}>{label}</span>;
            })}
        </div>
    </nav>;
}
