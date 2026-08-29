<?php

use Illuminate\Support\Facades\File;

test('authenticated sidebar follows the consolidated Conservation and Development hierarchy', function () {
    $sidebar = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    $conservation = strpos($sidebar, "label: 'Conservation Unit'");
    $manual = strpos($sidebar, "label: 'Manual Operation'");
    $protectedArea = strpos($sidebar, "label: 'Protected Area Management and Development'");
    $wildlife = strpos($sidebar, "label: 'Wildlife Conservation and Protection'");
    $database = strpos($sidebar, "label: 'Conservation Database'");

    expect($manual)->toBeGreaterThan($conservation)
        ->and($manual)->toBeLessThan($protectedArea)
        ->and($protectedArea)->toBeLessThan($wildlife)
        ->and($wildlife)->toBeLessThan($database)
        ->and($sidebar)->not->toContain("label: 'ENGP Dashboard'")
        ->and($sidebar)->not->toContain("label: 'OPERATIONS'");
});

test('NGP places the external generator before report submission monitoring', function () {
    $sidebar = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect(strpos($sidebar, "label: 'ENGP IAC Generator'"))
        ->toBeLessThan(strpos($sidebar, "label: 'Report Submission Monitoring'"));

    expect($sidebar)
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('engpIacGeneratorUrl');
});

test('all expandable sidebar groups use the reusable chevron disclosure icon', function () {
    $sidebar = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($sidebar)
        ->toContain('function DisclosureIcon')
        ->toContain("expanded ? 'rotate-90' : ''")
        ->toContain('<DisclosureIcon expanded={isOpen}')
        ->not->toContain("isOpen ? 'minus' : 'plus'")
        ->not->toContain("name === 'plus'");
});

test('sidebar scopes the current Inertia URL for active navigation checks', function () {
    $sidebar = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($sidebar)->toContain("function Sidebar({ open, onClose, auth, engpIacGeneratorUrl }) {\n    const { url } = usePage();")
        ->and($sidebar)->toContain('matchesNavigationItem(item, url)')
        ->and($sidebar)->toContain('matchesNavigationItem(child, url)');
});

test('sidebar preserves desktop scroll without active-item auto-scrolling', function () {
    $sidebar = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($sidebar)
        ->not->toContain('scrollIntoView(')
        ->not->toContain('navigationElement.scrollTo(')
        ->toContain("edats-sidebar-scroll-position")
        ->toContain('navigationElement.scrollTop = Number(savedPosition) || 0')
        ->toContain("router.on('start', captureNavigationPosition)")
        ->toContain('navigationElement.addEventListener(\'scroll\', rememberPosition');
});

test('the resolver assigns one persistent shell to authenticated pages', function () {
    $app = File::get(resource_path('js/app.jsx'));
    $layout = File::get(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

    expect($app)
        ->toContain("import { AuthenticatedShell } from './Layouts/AuthenticatedLayout';")
        ->toContain("const pageModules = import.meta.glob('./Pages/**/*.jsx');")
        ->toContain("const isPublicPage = (name) => name === 'Welcome' || name.startsWith('Auth/');")
        ->toContain('const resolve = async (name) =>')
        ->toContain('const pageModule = await resolvePageComponent')
        ->toContain('const Page = pageModule.default ?? pageModule;')
        ->toContain('if (!isPublicPage(name) && !Page.layout)')
        ->toContain('Page.layout = (pageElement) => <AuthenticatedShell>{pageElement}</AuthenticatedShell>;')
        ->toContain('return Page;')
        ->not->toContain('page.layout =')
        ->not->toContain('defaultLayout:');

    expect($layout)
        ->toContain('export function AuthenticatedShell')
        ->toContain('export default function AuthenticatedLayout')
        ->toContain('<Head title={title} />')
        ->toContain("router.on('navigate'")
        ->not->toContain('if (!auth?.user) return children;')
        ->not->toContain('useLayoutEffect')
        ->not->toContain('key={url}')
        ->not->toContain('key={pathname}');
});

test('auth-only pages stay outside the authenticated shell', function () {
    $app = File::get(resource_path('js/app.jsx'));
    $waitingApproval = File::get(resource_path('js/Pages/Auth/WaitingApproval.jsx'));
    $welcome = File::get(resource_path('js/Pages/Welcome.jsx'));

    expect($app)
        ->toContain("name === 'Welcome'")
        ->toContain("name.startsWith('Auth/')");

    expect($waitingApproval)
        ->toContain("import AuthLayout from '../../Layouts/AuthLayout';")
        ->not->toContain('AuthenticatedLayout');

    expect($welcome)->not->toContain('AuthenticatedLayout');
});
