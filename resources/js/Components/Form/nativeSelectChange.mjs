/**
 * Change a native select without updating React's value tracker before the
 * bubbling change event reaches React's delegated event handler.
 */
export function setNativeSelectValueAndDispatch(select, value) {
    if (!select) return;

    const nativeSetter = Object.getOwnPropertyDescriptor(
        select.ownerDocument?.defaultView?.HTMLSelectElement?.prototype || Object.getPrototypeOf(select),
        'value',
    )?.set;

    if (nativeSetter) nativeSetter.call(select, value);
    else select.value = value;

    const EventConstructor = select.ownerDocument?.defaultView?.Event || Event;
    select.dispatchEvent(new EventConstructor('change', { bubbles: true }));
}
