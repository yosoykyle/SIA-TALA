<?php

namespace App\Actions\Scheduling;

use App\Filament\Pages\MyAvailability;
use App\Mail\FacultyAvailabilityRequestedMail;
use App\Models\FacultyAvailabilityDeclaration;
use App\Models\OperationalEvent;
use App\Models\TermCalendarPackage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class FacultyAvailabilityRequestService
{
    /**
     * @param  list<int>  $facultyUserIds
     * @return EloquentCollection<int, OperationalEvent>
     */
    public function request(TermCalendarPackage $package, array $facultyUserIds, User $actor): EloquentCollection
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            abort(403);
        }

        if ($package->state !== TermCalendarPackage::StateActive || $package->faculty_availability_due_at === null) {
            throw ValidationException::withMessages([
                'calendar_package' => 'Activate an exact-Term Calendar Package with a Faculty availability deadline before sending requests.',
            ]);
        }

        $facultyUserIds = collect($facultyUserIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($facultyUserIds === []) {
            throw ValidationException::withMessages([
                'faculty_user_ids' => 'Select at least one affected Faculty member.',
            ]);
        }

        $faculty = User::query()
            ->whereKey($facultyUserIds)
            ->where('status', User::StatusActive)
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
            ->orderBy('id')
            ->get();

        if ($faculty->count() !== count($facultyUserIds)) {
            throw ValidationException::withMessages([
                'faculty_user_ids' => 'Every selected recipient must be an active Faculty account.',
            ]);
        }

        $alreadyDeclared = FacultyAvailabilityDeclaration::query()
            ->where('term_id', $package->term_id)
            ->whereIn('faculty_user_id', $facultyUserIds)
            ->pluck('faculty_user_id');

        if ($alreadyDeclared->isNotEmpty()) {
            throw ValidationException::withMessages([
                'faculty_user_ids' => 'A selected Faculty member has already declared for this Term. Routine saves and corrections do not create another request email.',
            ]);
        }

        $created = [];

        DB::transaction(function () use ($package, $faculty, &$created): void {
            $locked = TermCalendarPackage::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();

            foreach ($faculty as $recipient) {
                $event = OperationalEvent::query()->firstOrCreate(
                    [
                        'event_domain' => OperationalEvent::DomainNotifications,
                        'external_id' => "faculty-availability-request:term:{$locked->term_id}:generation:{$locked->version}:user:{$recipient->id}",
                    ],
                    [
                        'integration' => OperationalEvent::IntegrationMail,
                        'channel' => OperationalEvent::ChannelEmail,
                        'direction' => OperationalEvent::DirectionOutbound,
                        'event_type' => OperationalEvent::TypeFacultyAvailabilityRequestedEmail,
                        'event_version' => '1',
                        'user_id' => $recipient->id,
                        'recipient_snapshot' => [
                            'user_id' => (int) $recipient->id,
                            'email' => (string) $recipient->email,
                        ],
                        'status' => OperationalEvent::StatusPending,
                        'occurred_at' => now(),
                        'related_record_type' => TermCalendarPackage::class,
                        'related_record_id' => $locked->id,
                        'payload' => [
                            'term_id' => (int) $locked->term_id,
                            'request_generation' => (int) $locked->version,
                            'due_at' => CarbonImmutable::parse((string) $locked->faculty_availability_due_at)->toIso8601String(),
                            'availability_url' => MyAvailability::getUrl(['termId' => $locked->term_id]),
                        ],
                    ],
                );

                if ($event->wasRecentlyCreated) {
                    $created[] = ['event' => $event, 'recipient' => $recipient];
                }
            }
        }, 3);

        foreach ($created as $delivery) {
            $this->queue($delivery['event'], $delivery['recipient'], $package);
        }

        return OperationalEvent::query()
            ->where('related_record_type', TermCalendarPackage::class)
            ->where('related_record_id', $package->id)
            ->where('event_type', OperationalEvent::TypeFacultyAvailabilityRequestedEmail)
            ->whereIn('user_id', $facultyUserIds)
            ->orderBy('id')
            ->get();
    }

    public function resend(OperationalEvent $event, User $actor): OperationalEvent
    {
        if (! $actor->hasRole(User::StaffRoleRegistrar)) {
            abort(403);
        }

        $outcome = DB::transaction(function () use ($event): array {
            $locked = OperationalEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->event_type !== OperationalEvent::TypeFacultyAvailabilityRequestedEmail
                || $locked->status !== OperationalEvent::StatusFailed) {
                throw ValidationException::withMessages([
                    'notification' => 'Only a failed Faculty availability request can be resent.',
                ]);
            }

            $recipient = User::query()->whereKey($locked->user_id)->firstOrFail();
            $package = TermCalendarPackage::query()->whereKey($locked->related_record_id)->firstOrFail();
            $locked->forceFill([
                'status' => OperationalEvent::StatusPending,
                'processed_at' => null,
                'failed_at' => null,
                'diagnostics' => null,
            ])->save();

            return ['event' => $locked->fresh(), 'recipient' => $recipient, 'package' => $package];
        }, 3);

        $this->queue($outcome['event'], $outcome['recipient'], $outcome['package']);

        return $outcome['event']->fresh();
    }

    private function queue(OperationalEvent $event, User $recipient, TermCalendarPackage $package): void
    {
        $email = trim((string) $recipient->email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->markFailed($event, 'Recipient email is missing or invalid.');

            return;
        }

        $package->loadMissing('term');
        $payload = is_array($event->payload) ? $event->payload : [];

        try {
            Mail::to($recipient)->queue(new FacultyAvailabilityRequestedMail(
                operationalEventId: (int) $event->id,
                recipientName: (string) $recipient->name,
                termLabel: (string) $package->term?->label,
                dueAt: CarbonImmutable::parse((string) $package->faculty_availability_due_at)
                    ->timezone(config('app.display_timezone'))
                    ->format('M j, Y g:i A').' Asia/Manila',
                availabilityUrl: (string) ($payload['availability_url'] ?? MyAvailability::getUrl(['termId' => $package->term_id])),
            ));
        } catch (Throwable $exception) {
            $this->markFailed($event, 'Mail could not be queued.', $exception);
        }
    }

    private function markFailed(OperationalEvent $event, string $reason, ?Throwable $exception = null): void
    {
        $diagnostics = ['reason' => $reason];

        if ($exception instanceof Throwable) {
            $diagnostics['exception_type'] = class_basename($exception);
        }

        $event->forceFill([
            'status' => OperationalEvent::StatusFailed,
            'processed_at' => now(),
            'sent_at' => null,
            'failed_at' => now(),
            'diagnostics' => $diagnostics,
        ])->save();
    }
}
