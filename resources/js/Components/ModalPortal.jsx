import { createPortal } from 'react-dom';

/** Keep viewport dialogs outside page animation and skeleton containing blocks. */
export default function ModalPortal({ children }) {
    if (typeof document === 'undefined' || !document.body) return null;
    return createPortal(children, document.body);
}
