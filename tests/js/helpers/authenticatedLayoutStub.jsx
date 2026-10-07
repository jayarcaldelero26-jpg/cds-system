import React from 'react';

export default function AuthenticatedLayout({ children, title }) {
    return React.createElement('div', { 'data-layout-title': title }, children);
}
