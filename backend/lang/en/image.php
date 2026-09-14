<?php

return [
    /*
     * The `unique` message for images.title. Overridden per-request rather than
     * through validation.php's `custom` block, which is keyed by attribute and
     * would also catch a document's title, whose scope is still its owner.
     */
    'duplicateTitle' => 'This document already has an image with that title.',

    /*
     * The 409 from ImageController::restore when the owning document is in the
     * bin too. Reachable only through the API today — no screen lists an image
     * whose document is trashed — but it binds every role, the image's own
     * uploader included.
     */
    'documentTrashed' => 'This image’s document is deleted. Restore that document first — it brings back every image in its bin.',
];
