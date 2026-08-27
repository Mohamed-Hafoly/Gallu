<?php

return [
    /*
     * The `unique` message for documents.title. Overridden per-request rather
     * than through validation.php's `custom` block, which is keyed by attribute
     * and would also catch an image's title, whose scope is its document.
     */
    'duplicateTitle' => 'This team already has a document with that title.',
];
