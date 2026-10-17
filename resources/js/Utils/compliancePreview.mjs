export const PREVIEW_ERROR_MESSAGE = 'Email preview could not be generated. Please try again.';
export const NO_PREVIEW_REPORTS_MESSAGE = 'No qualifying reports for this alert type.';

export async function readCompliancePreviewResponse(response) {
    const body = await response.text();
    let payload = null;

    try {
        payload = body ? JSON.parse(body) : null;
    } catch {
        throw new Error(PREVIEW_ERROR_MESSAGE);
    }

    if (!response.ok) {
        const message = response.status === 422 && payload?.message === NO_PREVIEW_REPORTS_MESSAGE
            ? payload.message
            : PREVIEW_ERROR_MESSAGE;
        throw new Error(message);
    }

    if (!payload || typeof payload.subject !== 'string' || typeof payload.html !== 'string') {
        throw new Error(PREVIEW_ERROR_MESSAGE);
    }

    return payload;
}

export function compliancePreviewErrorMessage(error) {
    return error?.message === NO_PREVIEW_REPORTS_MESSAGE
        ? NO_PREVIEW_REPORTS_MESSAGE
        : PREVIEW_ERROR_MESSAGE;
}
