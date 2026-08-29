<?php

return [
    // Calendar days before a live authoritative deadline.
    'due_soon_days' => (int) env('EDATS_NOTIFICATION_DUE_SOON_DAYS', 3),
];
