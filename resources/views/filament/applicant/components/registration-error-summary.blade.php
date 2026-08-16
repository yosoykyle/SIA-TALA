@php
    $fieldLabels = [
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'privacy_acknowledged' => 'Privacy acknowledgement',
    ];
    $fieldErrors = [];

    foreach ($errors->getMessages() as $errorKey => $messages) {
        $field = str_starts_with($errorKey, 'data.') ? substr($errorKey, 5) : $errorKey;

        if (array_key_exists($field, $fieldLabels)) {
            $fieldErrors[$field] = $messages;
        }
    }
@endphp

@if ($fieldErrors !== [])
    <div
        id="applicant-registration-error-summary"
        class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700 outline-none focus-visible:ring-2 focus-visible:ring-danger-600 focus-visible:ring-offset-2 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-200 dark:focus-visible:ring-offset-gray-950"
        role="alert"
        aria-live="assertive"
        tabindex="-1"
        x-data
        x-init="$nextTick(() => {
            const fields = @js(array_keys($fieldErrors));

            fields.forEach((field) => {
                const input = document.getElementById(`form.${field}`);

                if (! input) {
                    return;
                }

                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', `form.${field}-errors`);
            });

            $el.focus({ preventScroll: true });
            $el.scrollIntoView({ block: 'nearest' });
        })"
    >
        <p class="font-semibold">Review the highlighted fields</p>
        <ul class="mt-2 list-disc space-y-1 ps-5">
            @foreach ($fieldErrors as $field => $messages)
                <li id="form.{{ $field }}-errors">
                    <a
                        class="underline decoration-danger-400 underline-offset-2 hover:text-danger-900 focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger-600 dark:hover:text-danger-50"
                        data-error-field="{{ $field }}"
                        href="#form.{{ $field }}"
                    >
                        <span class="font-medium">{{ $fieldLabels[$field] }}:</span>
                        {{ implode(' ', $messages) }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
