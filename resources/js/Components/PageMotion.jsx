import { useContext, useLayoutEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { animatePageEntrance, navigationPageKey } from '../Utils/shellMotion.mjs';
import { NavigationPendingContext } from './NavigationPendingContext.jsx';

const wrappedPages = new WeakMap();

/** A single content-only entrance shared by every resolved Inertia page. */
export default function PageMotion({ children }) {
    const page = usePage();
    const navigationPending = useContext(NavigationPendingContext);
    const navigationKey = navigationPageKey(page.url);
    const previousNavigationKey = useRef(null);
    const contentRef = useRef(null);

    useLayoutEffect(() => {
        if (navigationPending) return undefined;
        if (previousNavigationKey.current === navigationKey) return undefined;
        previousNavigationKey.current = navigationKey;
        return animatePageEntrance(contentRef.current);
    }, [navigationKey, navigationPending]);

    return <div ref={contentRef} data-cds-page-content="true">{children}</div>;
}

/** Keep the original page props and layout while reusing the same wrapper type. */
export function withPageMotion(Page) {
    let MotionPage = wrappedPages.get(Page);
    if (!MotionPage) {
        MotionPage = function MotionPage(props) {
            return <PageMotion><Page {...props} /></PageMotion>;
        };
        wrappedPages.set(Page, MotionPage);
    }
    Object.assign(MotionPage, Page);
    MotionPage.displayName = 'PageMotion(' + (Page.displayName || Page.name || 'Page') + ')';
    return MotionPage;
}
