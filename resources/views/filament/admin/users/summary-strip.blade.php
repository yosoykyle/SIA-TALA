@php
    $totalAccounts = (int) ($metrics['total'] ?? 0);
    $attention = (int) ($metrics['attention'] ?? 0);
    $staffTotal = (int) ($metrics['staff'] ?? 0);
    $activeStaff = (int) ($metrics['active_staff'] ?? 0);
    $staffMfa = (int) ($metrics['staff_mfa_enrolled'] ?? 0);
    $multiRoleTotal = (int) ($metrics['multi_role'] ?? 0);
    $learnersTotal = (int) ($metrics['learners'] ?? 0);
    $mfaGap = max(0, $activeStaff - $staffMfa);
    $queueUrl = fn (string $queue): string => $queue === 'all'
        ? \App\Filament\Resources\Users\UserResource::getUrl('index')
        : \App\Filament\Resources\Users\UserResource::getUrl('index', ['queue' => $queue]);

    $items = [
        [
            'label' => 'All accounts',
            'value' => number_format($totalAccounts),
            'meta' => number_format($staffTotal).' staff · '.number_format($learnersTotal).' '.\Illuminate\Support\Str::plural('learner', $learnersTotal),
            'icon' => 'heroicon-o-users',
            'href' => $queueUrl('all'),
            'active' => $activeQueue === 'all',
            'tone' => 'default',
        ],
        [
            'label' => 'Needs attention',
            'value' => number_format($attention),
            'meta' => $attention > 0 ? 'Pending invite, verification, or disabled' : 'No accounts waiting for action',
            'icon' => 'heroicon-o-exclamation-circle',
            'href' => $queueUrl('attention'),
            'active' => $activeQueue === 'attention',
            'tone' => $attention > 0 ? 'danger' : 'default',
        ],
        [
            'label' => 'Staff accounts',
            'value' => number_format($staffTotal),
            'meta' => $mfaGap > 0
                ? number_format($staffMfa).' of '.number_format($activeStaff).' active MFA enrolled'
                : 'All '.number_format($activeStaff).' active staff MFA enrolled',
            'icon' => 'heroicon-o-shield-check',
            'href' => $queueUrl('staff'),
            'active' => $activeQueue === 'staff',
            'tone' => 'success',
        ],
        [
            'label' => 'Multi-role',
            'value' => number_format($multiRoleTotal),
            'meta' => $multiRoleTotal > 0 ? 'Assigned to 2 or more roles' : 'No multi-role accounts',
            'icon' => 'heroicon-o-arrows-right-left',
            'href' => $queueUrl('multi-role'),
            'active' => $activeQueue === 'multi-role',
            'tone' => 'default',
        ],
        [
            'label' => 'Learners',
            'value' => number_format($learnersTotal),
            'meta' => 'Applicants and students',
            'icon' => 'heroicon-o-academic-cap',
            'href' => $queueUrl('learners'),
            'active' => $activeQueue === 'learners',
            'tone' => 'default',
        ],
    ];
@endphp

<x-tala-kpi-strip :items="$items" aria-label="Accounts summary" />
