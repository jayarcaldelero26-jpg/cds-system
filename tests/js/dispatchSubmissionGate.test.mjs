import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import {
    beginInitialPenroDispatch,
    finishInitialPenroDispatch,
} from "../../resources/js/Utils/dispatchSubmissionGate.js";

test("a second synchronous initial PENRO dispatch submit is blocked until the request finishes", () => {
    const ref = { current: false };

    assert.equal(beginInitialPenroDispatch(ref, "dispatch_penro_records_to_cds_focal"), true);
    assert.equal(beginInitialPenroDispatch(ref, "dispatch_penro_records_to_cds_focal"), false);

    finishInitialPenroDispatch(ref, "dispatch_penro_records_to_cds_focal");

    assert.equal(beginInitialPenroDispatch(ref, "dispatch_penro_records_to_cds_focal"), true);
});

test("non-initial actions retain normal repeat behavior", () => {
    const ref = { current: false };

    assert.equal(beginInitialPenroDispatch(ref, "receive_at_penro_records"), true);
    assert.equal(beginInitialPenroDispatch(ref, "receive_at_penro_records"), true);
    assert.equal(ref.current, false);
});

test("Submission Tracking uses the synchronous gate and releases it when the POST finishes", () => {
    const source = readFileSync(
        new URL("../../resources/js/Pages/SubmissionTracking/Index.jsx", import.meta.url),
        "utf8",
    );

    assert.match(source, /beginInitialPenroDispatch\(dispatchSubmitInFlight, stage\)/);
    assert.match(source, /onFinish:\s*\(\)\s*=>\s*finishInitialPenroDispatch\(dispatchSubmitInFlight, stage\)/);
});
