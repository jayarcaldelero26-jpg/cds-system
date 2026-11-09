import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { createThemeSwitcher, readPreferredTheme } from './shellMotion.mjs';

/** Share theme preference and transition behavior across guest and signed-in controls. */
export function useThemeMotion() {
    const [darkMode, setDarkMode] = useState(readPreferredTheme);
    const controllerRef = useRef(null);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', darkMode);
    }, [darkMode]);

    useEffect(() => () => controllerRef.current?.dispose(), []);

    const toggleTheme = () => {
        controllerRef.current ??= createThemeSwitcher({
            initialDark: darkMode,
            applyTheme: next => flushSync(() => setDarkMode(next)),
        });
        controllerRef.current.toggle();
    };

    return { darkMode, toggleTheme };
}
