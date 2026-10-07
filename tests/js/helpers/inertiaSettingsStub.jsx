import React from 'react';

export const usePage = () => globalThis.__settingsNavigationPage;
export const Head = () => null;
export const Link = ({ href, children, ...props }) => React.createElement('a', { href, ...props }, children);
export const useForm = data => ({ data, errors: {}, processing: false, setData() {}, setDefaults() {}, put() {} });
