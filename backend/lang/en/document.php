<?php

return [
    /*
     * The `unique` message for documents.title. Overridden per-request rather
     * than through validation.php's `custom` block, which is keyed by attribute
     * and would also catch an image's title, whose scope is its document.
     */
    'duplicateTitle' => 'This team already has a document with that title.',

    /*
     * The 409 from DocumentController::restore when the owning team is in the
     * bin too. Surfaced by the SPA off the response body, for anyone who
     * reaches the endpoint past the disabled button.
     */
    'teamTrashed' => 'This document’s team is deleted. Restore that team first — it brings back every document and image in its bin.',
];
