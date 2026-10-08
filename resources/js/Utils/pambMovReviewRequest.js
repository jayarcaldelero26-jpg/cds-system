/** Leave an empty optional file input out of the multipart request entirely. */
export function pambMovReviewPayload(data = {}) {
    const { attachment, ...payload } = data;

    return typeof File !== "undefined" && attachment instanceof File
        ? { ...payload, attachment }
        : payload;
}
