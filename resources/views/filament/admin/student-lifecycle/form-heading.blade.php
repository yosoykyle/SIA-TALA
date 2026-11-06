<header @class(['tala-lifecycle-detail-heading' => filled($type), 'tala-lifecycle-choice-heading' => blank($type)])>
    <h2 class="fi-section-header-heading">{{ match ($type) {
        null => 'What changed?',
        \App\Models\StudentLifecycleChange::TypeLeaveOfAbsence => 'Leave details',
        \App\Models\StudentLifecycleChange::TypeWithdrawal => 'Withdrawal details',
        \App\Models\StudentLifecycleChange::TypeTransferOut => 'Transfer details',
        \App\Models\StudentLifecycleChange::TypeReactivation => 'Return details',
        \App\Models\StudentLifecycleChange::TypeProgramShift => 'Program change details',
        default => 'Inactivity details',
    } }}</h2>
    @if (blank($type))<p>Choose the change already approved by the school.</p>@endif
</header>
