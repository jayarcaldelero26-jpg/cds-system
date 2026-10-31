import { shouldActivateRow } from '../../../Components/Crud/rowActivation.mjs';

export function activateParticipantRow(event, rowElement, participant, onOpen) {
    if (!shouldActivateRow(event, rowElement)) return false;
    if (event.type === 'keydown') event.preventDefault();
    if (event.type === 'click') rowElement.focus({ preventScroll: true });
    onOpen(participant, event);
    return true;
}
