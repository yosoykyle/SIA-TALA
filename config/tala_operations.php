<?php

return [
    'backup_overdue_after_hours' => env('TALA_BACKUP_EVIDENCE_OVERDUE_AFTER_HOURS'),
    'restore_overdue_after_days' => env('TALA_RESTORE_EVIDENCE_OVERDUE_AFTER_DAYS'),
    'prospective_rpo_hours' => 6,
    'prospective_rto_hours' => 8,
];
