<?php

return [
    /*
     * The `unique` message for images.title. Overridden per-request rather than
     * through validation.php's `custom` block, which is keyed by attribute and
     * would also catch a document's title, whose scope is still its owner.
     */
    'duplicateTitle' => 'This document already has an image with that title.',
];
