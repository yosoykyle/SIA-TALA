@php
    $student = $detail['student'];
    $projection = $detail['projection'];
    $recorded = $projection['conferral'];
    $missingCurriculum = collect($projection['blockers'])->contains('code', 'curriculum:requirements-missing');
@endphp
<div class="tala-tor-request-content" wire:key="graduation-student-{{ $student->id }}">
    <x-record-identity :name="$student->last_name.', '.collect([$student->first_name, $student->middle_name])->filter()->implode(' ')" :number="$student->student_number" :program="$student->program?->name" :initials="mb_substr($student->first_name,0,1).mb_substr($student->last_name,0,1)" />
    <section class="tala-graduation-status" aria-label="Graduation status">
        <header><x-filament::icon :icon="$recorded ? 'heroicon-o-academic-cap' : ($projection['blockers'] !== [] ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')" aria-hidden="true" /><div><p>Graduation review</p><h3>{{ $recorded ? 'Graduation recorded' : ($missingCurriculum ? 'Cannot evaluate yet' : ($projection['blockers'] !== [] ? 'Requirements need attention' : 'Requirements are complete')) }}</h3></div></header>
        @if ($recorded)
            <p class="tala-graduation-degree">{{ $recorded->degree_name }}</p>
            <p>{{ $recorded->conferred_on ? 'Graduated '.$recorded->conferred_on->format('M j, Y') : 'Graduation date not supplied' }}</p>
            @if ($recorded->honor_text)<p>{{ $recorded->honor_text }}</p>@endif
            @if (auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar))<div class="tala-graduation-status-action">{{ $this->getAction('correctGraduation') }}</div>@endif
        @else
            @foreach ($projection['blockers'] as $blocker)
                <div class="tala-graduation-blocker"><h4>{{ $blocker['reason'] }}</h4><p>{{ $blocker['recovery'] }}</p></div>
            @endforeach
            @if ($projection['blockers'] === [])<p>Record the school’s actual graduation result. Requirements are checked again before saving.</p>@endif
            <div class="tala-graduation-source"><div><p>Curriculum used for this review</p><strong>{{ $student->curriculumVersion?->name ?? 'No curriculum assigned' }}</strong></div>
                @if ($projection['blockers'] !== [] && $student->curriculumVersion && \App\Filament\Resources\CurriculumVersions\CurriculumVersionResource::canView($student->curriculumVersion))
                    {{ \Filament\Actions\Action::make('openCurriculum')->label('Open curriculum')->color('gray')->icon('heroicon-o-book-open')->url(\App\Filament\Resources\CurriculumVersions\CurriculumVersionResource::getUrl('view',['record'=>$student->curriculum_version_id])) }}
                @endif
            </div>
            @if ($projection['blockers'] === [] && auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar))<div class="tala-graduation-status-action">{{ $this->getAction('recordGraduation') }}</div>@endif
        @endif
    </section>
    <x-record-history heading="Graduation history" :paginator="$detail['history']" :has-entries="$detail['history']->isNotEmpty()" method="gotoGraduationHistoryPage">
        @foreach ($detail['history'] as $graduation)
            <x-record-history-item :heading="$graduation->active_scope_key ? 'Graduation recorded · Current result' : 'Earlier graduation result'" :date="$graduation->recorded_at" icon="heroicon-o-academic-cap">
                <p><strong>{{ $graduation->degree_name }}</strong></p><p>{{ $graduation->conferred_on ? 'Graduated '.$graduation->conferred_on->format('M j, Y') : 'Graduation date not supplied' }}</p>@if ($graduation->honor_text)<p>{{ $graduation->honor_text }}</p>@endif
            </x-record-history-item>
        @endforeach
        <x-slot:empty>No graduation has been recorded for this student.</x-slot:empty>
    </x-record-history>
</div>
