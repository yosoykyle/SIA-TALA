@props([
    'presentation',
    'focused' => false,
    'controlled' => false,
    'embedded' => false,
    'nativeDetails' => false,
    'showWindow' => true,
    'interactive' => false,
    'editable' => false,
    'isOfficial' => false,
    'scheduleContext' => [],
    'initialView' => 'whole',
    'initialFormat' => 'week',
    'initialEntity' => '',
    'initialProgram' => '',
    'initialYear' => '',
    'initialSearch' => '',
])
@php
    $overviewId = 'timetable-overview-'.\Illuminate\Support\Str::uuid();
    $meetingId = $overviewId.'-meeting';
    $renderMeetings = $interactive
        ? ($presentation['allMeetings'] ?? $presentation['meetings'] ?? [])
        : ($presentation['meetings'] ?? []);
    $gridSlots = $presentation['gridSlots'] ?? [];
@endphp

<section
    {{ $attributes->class([
        'tala-timetable',
        'tala-timetable-focused' => $focused,
        'tala-timetable-interactive' => $interactive,
        'tala-timetable-editable' => $editable,
        'tala-timetable-official' => $isOfficial,
    ]) }}
    x-data="{
        mode: {{ \Illuminate\Support\Js::from($interactive && $initialFormat === 'list' ? 'list' : 'weekly') }},
        selected: null,
        interactive: {{ \Illuminate\Support\Js::from((bool) $interactive) }},
        editable: {{ \Illuminate\Support\Js::from((bool) $editable) }},
        isOfficial: {{ \Illuminate\Support\Js::from((bool) $isOfficial) }},
        meetings: {{ \Illuminate\Support\Js::from($renderMeetings) }},
        filters: {faculty: '', room: '', class: '', modality: ''},
        viewMode: {{ \Illuminate\Support\Js::from(in_array($initialView, ['whole', 'section', 'faculty', 'room'], true) ? $initialView : 'whole') }},
        entityFilter: {{ \Illuminate\Support\Js::from((string) ($initialEntity ?? '')) }},
        programFilter: {{ \Illuminate\Support\Js::from((string) ($initialProgram ?? '')) }},
        yearFilter: {{ \Illuminate\Support\Js::from((string) ($initialYear ?? '')) }},
        searchQuery: {{ \Illuminate\Support\Js::from((string) ($initialSearch ?? '')) }},
        teachingGrid: {{ \Illuminate\Support\Js::from($scheduleContext['teachingGrid'] ?? []) }},
        blockedPeriods: {{ \Illuminate\Support\Js::from($presentation['blockedPeriods'] ?? []) }},
        teacherAvailability: {{ \Illuminate\Support\Js::from($scheduleContext['teacherAvailability'] ?? []) }},
        rooms: {{ \Illuminate\Support\Js::from($scheduleContext['rooms'] ?? []) }},
        qualifiedTeachersByCourse: {{ \Illuminate\Support\Js::from($scheduleContext['qualifiedTeachersByCourse'] ?? []) }},
        dayOptions: {{ \Illuminate\Support\Js::from($scheduleContext['dayOptions'] ?? []) }},
        timeSlotOptions: {{ \Illuminate\Support\Js::from($scheduleContext['timeSlotOptions'] ?? []) }},
        sectionOptions: {{ \Illuminate\Support\Js::from($scheduleContext['sectionOptions'] ?? []) }},
        facultyOptions: {{ \Illuminate\Support\Js::from($scheduleContext['facultyOptions'] ?? []) }},
        roomOptions: {{ \Illuminate\Support\Js::from($scheduleContext['roomOptions'] ?? []) }},
        draggingId: null,
        activeMoveId: null,
        hoverSlotKey: null,
        editForm: {
            day: 1,
            startsAt: '08:00',
            roomId: '',
            facultyId: '',
        },
        init() {
            this.$watch('selected', (val) => {
                const m = this.meetings.find(item => item.id === val);
                if (m) {
                    this.syncEditForm(m);
                }
            });
        },
        syncEditForm(m) {
            this.editForm.day = m.day || 1;
            this.editForm.startsAt = m.startsAt || '08:00';
            this.editForm.roomId = m.roomId ? String(m.roomId) : '';
            this.editForm.facultyId = m.facultyId ? String(m.facultyId) : '';
        },
        get view() {
            return this.viewMode;
        },
        get format() {
            return this.mode === 'list' ? 'list' : 'week';
        },
        setView(newMode) {
            this.setViewMode(newMode);
        },
        setViewMode(newMode) {
            this.viewMode = newMode;
            this.entityFilter = '';
        },
        setFormat(newFormat) {
            this.mode = newFormat === 'list' ? 'list' : 'weekly';
        },
        hasActiveFilters() {
            return this.viewMode !== 'whole'
                || this.entityFilter !== ''
                || this.programFilter !== ''
                || this.yearFilter !== ''
                || (this.searchQuery || '').trim() !== '';
        },
        clearAllFilters() {
            this.viewMode = 'whole';
            this.entityFilter = '';
            this.programFilter = '';
            this.yearFilter = '';
            this.searchQuery = '';
            this.filters = {faculty: '', room: '', class: '', modality: ''};
        },
        matches(meeting) {
            if (!meeting) return false;
            if (!this.interactive) {
                return Object.entries(this.filters).every(([key, value]) => !value || meeting[key] === value);
            }
            if (this.viewMode === 'section' && this.entityFilter !== '') {
                const secMatch = (meeting.sections || []).some(s => String(s.id) === String(this.entityFilter) || s.label === this.entityFilter)
                    || meeting.class === this.entityFilter;
                if (!secMatch) return false;
            } else if (this.viewMode === 'faculty' && this.entityFilter !== '') {
                if (meeting.faculty !== this.entityFilter) return false;
            } else if (this.viewMode === 'room' && this.entityFilter !== '') {
                if (meeting.room !== this.entityFilter) return false;
            }
            if (this.programFilter !== '' || this.yearFilter !== '') {
                const progYearMatch = (meeting.sections || []).some(s => {
                    const pOk = !this.programFilter || s.program === this.programFilter;
                    const yOk = !this.yearFilter || (s.years || []).includes(this.yearFilter);
                    return pOk && yOk;
                });
                if (!progYearMatch) return false;
            }
            const q = (this.searchQuery || '').trim().toLowerCase();
            if (q !== '') {
                const haystack = [
                    meeting.courseCode,
                    meeting.courseTitle,
                    meeting.class,
                    meeting.faculty,
                    meeting.room,
                    meeting.dayLabel,
                    meeting.time,
                    meeting.component,
                    meeting.componentBadge,
                ].filter(Boolean).join(' ').toLowerCase();
                if (!haystack.includes(q)) return false;
            }
            return true;
        },
        get visibleCount() {
            return this.meetings.filter(meeting => this.matches(meeting)).length;
        },
        get selectedMeeting() {
            return this.meetings.find(meeting => meeting.id === this.selected) ?? null;
        },
        get activeMoveMeeting() {
            if (!this.activeMoveId) return null;
            return this.meetings.find(meeting => meeting.id === this.activeMoveId) ?? null;
        },
        get currentScopeLabel() {
            if (this.viewMode === 'section') {
                if (!this.entityFilter) return 'By student section';
                const found = this.sectionOptions.find(s => String(s.id) === String(this.entityFilter));
                return found ? found.label : this.entityFilter;
            }
            if (this.viewMode === 'faculty') {
                return this.entityFilter || 'By teacher';
            }
            if (this.viewMode === 'room') {
                return this.entityFilter || 'By room';
            }
            return 'Whole Term';
        },
        isMeetingPinned(id) {
            const m = this.meetings.find(item => item.id === id);
            return Boolean(m && m.isPinned);
        },
        startDragging(id, event) {
            if (!this.editable) return;
            this.draggingId = id;
            this.activeMoveId = id;
            if (event && event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(id));
            }
        },
        stopDragging() {
            this.draggingId = null;
            this.hoverSlotKey = null;
        },
        startMoveOnGrid() {
            if (!this.editable || !this.selectedMeeting) return;
            this.activeMoveId = this.selectedMeeting.id;
            this.$dispatch('close-modal', { id: '{{ $meetingId }}' });
        },
        cancelMoveMode() {
            this.activeMoveId = null;
            this.draggingId = null;
            this.hoverSlotKey = null;
        },
        evaluateSlotForMeeting(meeting, dayNumber, slotStart, overrideRoomId = null, overrideFacultyId = null) {
            if (!meeting) return { valid: false, reason: '' };
            const duration = Number(meeting.durationMinutes || ((meeting.end || 0) - (meeting.start || 0)) || 60);
            const slotEnd = Number(slotStart) + duration;
            const day = Number(dayNumber);
            const targetFacultyId = overrideFacultyId !== null && overrideFacultyId !== '' ? Number(overrideFacultyId) : Number(meeting.facultyId || 0);
            const targetRoomId = overrideRoomId !== null && overrideRoomId !== '' ? Number(overrideRoomId) : Number(meeting.roomId || 0);

            const dayGrid = this.teachingGrid[day] || this.teachingGrid[String(day)] || [];
            if (dayGrid.length > 0) {
                const withinGrid = dayGrid.some(g => Number(slotStart) >= Number(g.start) && slotEnd <= Number(g.end));
                if (!withinGrid) {
                    return { valid: false, reason: 'Outside school teaching hours' };
                }
            }

            const breakHit = (this.blockedPeriods || []).find(b => Number(b.day) === day && Number(slotStart) < Number(b.end) && slotEnd > Number(b.start));
            if (breakHit) {
                return { valid: false, reason: breakHit.label || 'Institutional break' };
            }

            if (targetFacultyId > 0) {
                const avail = this.teacherAvailability[targetFacultyId] || this.teacherAvailability[String(targetFacultyId)];
                if (avail) {
                    if (avail.mode === 'cannot_teach') {
                        return { valid: false, reason: 'Teacher unavailable this Term' };
                    }
                    if (avail.mode === 'windows') {
                        const fitsWindow = (avail.windows || []).some(w => Number(w.day) === day && Number(w.start) <= Number(slotStart) && Number(w.end) >= slotEnd);
                        if (!fitsWindow) {
                            return { valid: false, reason: 'Outside teacher availability' };
                        }
                    }
                    const blockedByDecl = (avail.unavailability || []).some(u => Number(u.day) === day && Number(slotStart) < Number(u.end) && slotEnd > Number(u.start));
                    if (blockedByDecl) {
                        return { valid: false, reason: 'Teacher unavailable at this time' };
                    }
                }
            }

            const meetingSectionIds = (meeting.sections || []).map(s => String(s.id));
            for (const other of this.meetings) {
                if (String(other.id) === String(meeting.id) || !other.plottable || Number(other.day) !== day) {
                    continue;
                }
                const overlaps = Number(slotStart) < Number(other.end) && slotEnd > Number(other.start);
                if (!overlaps) continue;

                if (targetFacultyId > 0 && Number(other.facultyId) === targetFacultyId) {
                    return { valid: false, reason: 'Teacher busy (' + other.courseCode + ')' };
                }
                if (targetRoomId > 0 && Number(other.roomId) === targetRoomId) {
                    return { valid: false, reason: 'Room occupied (' + other.courseCode + ')' };
                }
                const sharesSection = (other.class && meeting.class && other.class === meeting.class)
                    || (other.sections || []).some(s => meetingSectionIds.includes(String(s.id)));
                if (sharesSection) {
                    return { valid: false, reason: 'Section busy (' + other.courseCode + ')' };
                }
            }

            return { valid: true, reason: 'Available · Conflict-free' };
        },
        slotClasses(dayNumber, slotStart, startsAt) {
            if (!this.activeMoveMeeting) return {};
            const check = this.evaluateSlotForMeeting(this.activeMoveMeeting, dayNumber, slotStart);
            const key = dayNumber + ':' + startsAt;
            const isCurrent = Number(this.activeMoveMeeting.day) === Number(dayNumber) && this.activeMoveMeeting.startsAt === startsAt;
            return {
                'is-slot-available': check.valid && !isCurrent,
                'is-slot-current': isCurrent,
                'is-slot-blocked': !check.valid,
                'is-slot-hover': this.hoverSlotKey === key,
            };
        },
        slotCueText(dayNumber, slotStart, label) {
            if (!this.activeMoveMeeting) return '';
            const check = this.evaluateSlotForMeeting(this.activeMoveMeeting, dayNumber, slotStart);
            return check.valid ? ('Available · ' + label) : check.reason;
        },
        onDragOverSlot(dayNumber, startsAt) {
            if (!this.editable || !this.activeMoveMeeting) return;
            this.hoverSlotKey = dayNumber + ':' + startsAt;
        },
        onDragLeaveSlot(dayNumber, startsAt) {
            const key = dayNumber + ':' + startsAt;
            if (this.hoverSlotKey === key) {
                this.hoverSlotKey = null;
            }
        },
        onDropSlot(dayNumber, startsAt) {
            if (!this.editable || !this.activeMoveMeeting) return;
            const rowId = Number(this.activeMoveMeeting.rowId || this.activeMoveMeeting.id);
            this.cancelMoveMode();
            if (rowId > 0 && this.$wire && typeof this.$wire.moveCandidateMeeting === 'function') {
                this.$wire.moveCandidateMeeting(rowId, Number(dayNumber), String(startsAt));
            }
        },
        onClickMoveSlot(dayNumber, startsAt) {
            if (!this.editable || !this.activeMoveMeeting) return;
            const rowId = Number(this.activeMoveMeeting.rowId || this.activeMoveMeeting.id);
            this.cancelMoveMode();
            if (rowId > 0 && this.$wire && typeof this.$wire.moveCandidateMeeting === 'function') {
                this.$wire.moveCandidateMeeting(rowId, Number(dayNumber), String(startsAt));
            }
        },
        togglePinSelected() {
            if (!this.editable || !this.selectedMeeting) return;
            const target = this.selectedMeeting;
            const rowId = Number(target.rowId || target.id);
            target.isPinned = !target.isPinned;
            if (rowId > 0 && this.$wire && typeof this.$wire.togglePinMeeting === 'function') {
                this.$wire.togglePinMeeting(rowId);
            }
        },
        compatibleRoomsFor(meeting) {
            if (!meeting) return this.rooms;
            const badge = meeting.componentBadge || (meeting.component === 'Laboratory' ? 'LAB' : 'LEC');
            const reqType = (meeting.requiredRoomType || '').toUpperCase();
            const isLab = badge === 'LAB' || reqType.includes('LAB');
            const matching = this.rooms.filter(r => {
                const rt = (r.roomType || '').toUpperCase();
                return isLab ? rt.includes('LAB') : !rt.includes('LAB');
            });
            return matching.length > 0 ? matching : this.rooms;
        },
        qualifiedTeachersFor(meeting) {
            if (!meeting) return [];
            const list = this.qualifiedTeachersByCourse[meeting.courseCode] || [];
            if (list.length > 0) return list;
            if (meeting.facultyId && meeting.faculty) {
                return [{ id: meeting.facultyId, name: meeting.faculty }];
            }
            return [];
        },
        evaluateProposedEdit() {
            if (!this.selectedMeeting) return { valid: true, reason: '' };
            const slotObj = (this.timeSlotOptions || []).find(s => s.value === this.editForm.startsAt);
            const startMin = slotObj ? slotObj.minutes : 480;
            return this.evaluateSlotForMeeting(
                this.selectedMeeting,
                this.editForm.day,
                startMin,
                this.editForm.roomId,
                this.editForm.facultyId
            );
        },
        submitInlineEdit() {
            if (!this.editable || !this.selectedMeeting) return;
            const rowId = Number(this.selectedMeeting.rowId || this.selectedMeeting.id);
            const day = Number(this.editForm.day || this.selectedMeeting.day || 1);
            const startsAt = String(this.editForm.startsAt || this.selectedMeeting.startsAt || '08:00');
            const roomId = this.editForm.roomId ? Number(this.editForm.roomId) : null;
            const facultyId = this.editForm.facultyId ? Number(this.editForm.facultyId) : null;
            this.$dispatch('close-modal', { id: '{{ $meetingId }}' });
            if (rowId > 0 && this.$wire && typeof this.$wire.updateCandidateMeeting === 'function') {
                this.$wire.updateCandidateMeeting(rowId, day, startsAt, roomId, facultyId);
            }
        }
    }"
    x-on:keydown.escape.window="if (activeMoveId !== null) { cancelMoveMode() }"
>
    @if (! $embedded)<div class="tala-timetable-heading">
        <div>
            <h3>{{ $presentation['title'] }}</h3>
            <p>{{ $presentation['source'] }}</p>
        </div>
        @if (! $controlled)
        <div class="tala-timetable-controls" aria-label="Timetable view">
            <x-tala-navigation label="Timetable view"><x-filament::tabs.item x-on:click="mode = 'weekly'" x-bind:class="{'fi-active': mode === 'weekly'}" x-bind:aria-pressed="mode === 'weekly'">Weekly overview</x-filament::tabs.item><x-filament::tabs.item x-on:click="mode = 'list'" x-bind:class="{'fi-active': mode === 'list'}" x-bind:aria-pressed="mode === 'list'">Meeting list</x-filament::tabs.item></x-tala-navigation>
            @if (! $focused)
                <x-filament::button color="gray" x-on:click="$dispatch('open-modal', {id: '{{ $overviewId }}'})">Whole timetable</x-filament::button>
            @endif
        </div>
        @endif
    </div>
    @endif

    @if (($presentation['synthetic'] ?? false) && ! $embedded)<p class="tala-timetable-guide">Synthetic review data</p>@endif
    @if ($renderMeetings === [])
        <p class="tala-timetable-empty">No meetings are recorded for this version. Return to the timetable workflow to prepare or select a timetable.</p>
    @else
        @if ($interactive)
            <div class="tala-interactive-toolbar" role="region" aria-label="Instant timetable filters and view controls">
                <div class="tala-interactive-toolbar__primary">
                    <x-tala-navigation compact label="Timetable view" class="tala-interactive-view-tabs">
                        <x-filament::tabs.item
                            alpine-active="viewMode === 'whole'"
                            x-bind:aria-pressed="viewMode === 'whole'"
                            x-on:click="setViewMode('whole')"
                        >
                            All (Whole Term)
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            alpine-active="viewMode === 'section'"
                            x-bind:aria-pressed="viewMode === 'section'"
                            x-on:click="setViewMode('section')"
                        >
                            By section
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            alpine-active="viewMode === 'faculty'"
                            x-bind:aria-pressed="viewMode === 'faculty'"
                            x-on:click="setViewMode('faculty')"
                        >
                            By teacher
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            alpine-active="viewMode === 'room'"
                            x-bind:aria-pressed="viewMode === 'room'"
                            x-on:click="setViewMode('room')"
                        >
                            By room
                        </x-filament::tabs.item>
                    </x-tala-navigation>

                    <div class="tala-interactive-entity-picker" x-cloak x-show="viewMode === 'section'">
                        <label for="{{ $overviewId }}-section-select" class="sr-only">Student section</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="{{ $overviewId }}-section-select" x-model="entityFilter">
                                <option value="">All student sections</option>
                                <template x-for="sec in sectionOptions" :key="sec.id">
                                    <option :value="sec.id" x-text="sec.label + (sec.program && sec.program !== 'Program not associated' ? ' · ' + sec.program : '')"></option>
                                </template>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    <div class="tala-interactive-entity-picker" x-cloak x-show="viewMode === 'faculty'">
                        <label for="{{ $overviewId }}-faculty-select" class="sr-only">Teacher</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="{{ $overviewId }}-faculty-select" x-model="entityFilter">
                                <option value="">All teachers</option>
                                <template x-for="fac in facultyOptions" :key="fac">
                                    <option :value="fac" x-text="fac"></option>
                                </template>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    <div class="tala-interactive-entity-picker" x-cloak x-show="viewMode === 'room'">
                        <label for="{{ $overviewId }}-room-select" class="sr-only">Room</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="{{ $overviewId }}-room-select" x-model="entityFilter">
                                <option value="">All rooms</option>
                                <template x-for="rm in roomOptions" :key="rm">
                                    <option :value="rm" x-text="rm"></option>
                                </template>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    @if (count($scheduleContext['programOptions'] ?? []) > 1)
                        <div class="tala-interactive-entity-picker">
                            <label for="{{ $overviewId }}-program-select" class="sr-only">Program</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="{{ $overviewId }}-program-select" x-model="programFilter">
                                    <option value="">All programs</option>
                                    @foreach ($scheduleContext['programOptions'] as $prog)
                                        <option value="{{ $prog }}">{{ $prog }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @endif

                    @if (count($scheduleContext['yearOptions'] ?? []) > 1)
                        <div class="tala-interactive-entity-picker">
                            <label for="{{ $overviewId }}-year-select" class="sr-only">Year level</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="{{ $overviewId }}-year-select" x-model="yearFilter">
                                    <option value="">All year levels</option>
                                    @foreach ($scheduleContext['yearOptions'] as $yr)
                                        <option value="{{ $yr }}">{{ $yr }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @endif
                </div>

                <div class="tala-interactive-toolbar__secondary">
                    <div class="tala-interactive-search">
                        <label for="timetable-meeting-search" class="sr-only">Search meetings</label>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input
                                id="timetable-meeting-search"
                                type="search"
                                placeholder="Search course, section, teacher, room…"
                                maxlength="255"
                                x-model="searchQuery"
                            />
                        </x-filament::input.wrapper>
                    </div>

                    <x-filament::button
                        type="button"
                        color="gray"
                        size="sm"
                        class="tala-interactive-clear-btn"
                        x-cloak
                        x-show="hasActiveFilters()"
                        x-on:click="clearAllFilters()"
                    >
                        Clear filters
                    </x-filament::button>

                    <x-tala-navigation compact label="Timetable format" class="tala-interactive-format-tabs">
                        <x-filament::tabs.item
                            icon="heroicon-o-calendar-days"
                            alpine-active="mode === 'weekly'"
                            x-bind:aria-pressed="mode === 'weekly'"
                            x-on:click="setFormat('week')"
                            aria-label="View weekly timetable"
                        >
                            Week
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            icon="heroicon-o-list-bullet"
                            alpine-active="mode === 'list'"
                            x-bind:aria-pressed="mode === 'list'"
                            x-on:click="setFormat('list')"
                            aria-label="View meeting list"
                        >
                            List
                        </x-filament::tabs.item>
                    </x-tala-navigation>
                </div>
            </div>

            <div class="tala-interactive-meta-bar">
                <p class="tala-work-footnote">
                    <strong x-text="currentScopeLabel">Whole Term</strong>
                    <template x-if="programFilter"><span> · <span x-text="programFilter"></span></span></template>
                    <template x-if="yearFilter"><span> · <span x-text="yearFilter"></span></span></template>
                    · <span x-text="visibleCount">{{ count($renderMeetings) }}</span> of {{ count($renderMeetings) }} meetings shown · Asia/Manila
                </p>
                @if ($editable)
                    <p class="tala-interactive-hint">
                        <span class="tala-interactive-hint__dot" aria-hidden="true"></span>
                        <span>Drag any card to an emerald conflict-free slot, or click a card to pin or edit its schedule.</span>
                    </p>
                @elseif ($isOfficial)
                    <p class="tala-interactive-hint is-locked">
                        <x-filament::icon icon="heroicon-m-lock-closed" />
                        <span>Official timetable is locked. Click any meeting to inspect details.</span>
                    </p>
                @endif
            </div>

            @if ($editable)
                <div class="tala-move-slot-banner" x-cloak x-show="activeMoveMeeting !== null" role="status" aria-live="polite">
                    <div class="tala-move-slot-banner__info">
                        <span class="tala-move-slot-banner__pulse" aria-hidden="true"></span>
                        <div>
                            <strong>Highlighting available conflict-free slots for <span x-text="activeMoveMeeting?.courseCode + ' · ' + activeMoveMeeting?.class"></span></strong>
                            <span>(<span x-text="activeMoveMeeting?.duration"></span> · <span x-text="activeMoveMeeting?.faculty"></span> · <span x-text="activeMoveMeeting?.room"></span>) — Drop or click any emerald slot on the grid to move this meeting.</span>
                        </div>
                    </div>
                    <button type="button" class="tala-move-slot-banner__cancel" x-on:click="cancelMoveMode()">
                        Cancel move (Esc)
                    </button>
                </div>
            @endif
        @elseif (! $controlled)
        <details class="tala-timetable-filters">
            <summary>Filter by faculty, room, class or modality</summary>
            <div class="tala-timetable-filter-fields">
                @foreach (['faculty' => 'Faculty', 'room' => 'Room', 'class' => 'Class', 'modality' => 'Modality'] as $field => $label)
                    <label>
                        <span>{{ $label }}</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select x-model="filters.{{ $field }}" x-on:change="selected = null">
                                <option value="">All {{ strtolower($label) }}</option>
                                @foreach ($presentation['filters'][$field] as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @endforeach
                <x-filament::button color="gray" x-on:click="filters = {faculty: '', room: '', class: '', modality: ''}; selected = null">Clear filters</x-filament::button>
            </div>
        </details>
        @endif
        @if (! $embedded)<p class="tala-timetable-guide">
            <span aria-live="polite"><span x-text="visibleCount">{{ count($renderMeetings) }}</span> of {{ count($renderMeetings) }} meetings shown.</span>
            Select a meeting to see its details. Times are in Asia/Manila.
            @if (! $presentation['snapshotAvailable'])
                Recorded calendar geometry is unavailable; the overview uses recorded meeting days and times.
            @endif
        </p>
        @endif
        <p x-cloak x-show="visibleCount === 0" class="tala-timetable-empty">
            No meetings match these filters.
            @if ($interactive)
                <button type="button" class="tala-inline-clear-link" x-on:click="clearAllFilters()">Clear filters</button>
            @else
                Clear the filters to see the full timetable.
            @endif
        </p>

        @if (! $nativeDetails)
            <x-filament::modal footer-actions-alignment="end" :id="$meetingId" width="xl" sticky-header sticky-footer teleport="body" class="tala-meeting-dialog">
                <x-slot name="heading">
                    <div class="tala-inspector-heading-row">
                        <span x-text="selectedMeeting?.courseTitle || selectedMeeting?.courseCode || 'Meeting details'"></span>
                        <span
                            class="tala-timetable-component-badge"
                            :class="(selectedMeeting?.componentBadge || (selectedMeeting?.component === 'Laboratory' ? 'LAB' : 'LEC')) === 'LAB' ? 'is-lab' : 'is-lec'"
                            x-text="selectedMeeting?.componentBadge || (selectedMeeting?.component === 'Laboratory' ? 'LAB' : 'LEC')"
                        ></span>
                    </div>
                </x-slot>
                <x-slot name="description">
                    <span class="tala-inspector-subline">
                        <strong x-text="selectedMeeting?.courseCode"></strong> · <span x-text="selectedMeeting?.class"></span>
                        <template x-if="selectedMeeting?.isPinned">
                            <span class="tala-inspector-pinned-pill">
                                <x-filament::icon icon="heroicon-s-map-pin" />
                                <span>Pinned slot</span>
                            </span>
                        </template>
                        @if ($editable)
                            <span class="tala-inspector-state-pill is-draft">Draft candidate · Editable</span>
                        @elseif ($isOfficial)
                            <span class="tala-inspector-state-pill is-official">Official · Locked</span>
                        @endif
                    </span>
                </x-slot>

                <div class="tala-inspector-body">
                    <dl class="tala-meeting-facts">
                        <div><dt>When</dt><dd><span x-text="selectedMeeting?.dayLabel"></span> · <span x-text="selectedMeeting?.time"></span></dd></div>
                        <div><dt>Location</dt><dd x-text="selectedMeeting?.room"></dd></div>
                        <div><dt>Instructor</dt><dd x-text="selectedMeeting?.faculty"></dd></div>
                        <div><dt>Duration</dt><dd x-text="selectedMeeting?.duration"></dd></div>
                        <div><dt>Delivery</dt><dd><span x-text="selectedMeeting?.modality"></span> · <span x-text="selectedMeeting?.component"></span> (<span x-text="selectedMeeting?.componentBadge || (selectedMeeting?.component === 'Laboratory' ? 'LAB' : 'LEC')"></span>)</dd></div>
                        <div><dt>Record status</dt><dd x-text="selectedMeeting?.status"></dd></div>
                    </dl>

                    <p x-cloak x-show="selectedMeeting && !selectedMeeting.plottable" class="tala-timetable-guide">Meeting time is unavailable or invalid. Contact the Registrar.</p>
                    <p x-cloak x-show="selectedMeeting?.offGrid" class="tala-timetable-guide">This meeting is outside the recorded teaching window. Contact the Registrar.</p>

                    @if ($editable)
                        <div class="tala-inspector-quick-actions">
                            <button
                                type="button"
                                class="tala-inspector-btn"
                                :class="{ 'is-pinned': selectedMeeting?.isPinned }"
                                x-on:click="togglePinSelected()"
                            >
                                <x-filament::icon icon="heroicon-s-map-pin" />
                                <span x-text="selectedMeeting?.isPinned ? 'Unpin meeting' : 'Pin meeting'">Pin meeting</span>
                            </button>

                            <button
                                type="button"
                                class="tala-inspector-btn"
                                x-on:click="startMoveOnGrid()"
                            >
                                <x-filament::icon icon="heroicon-o-arrows-pointing-out" />
                                <span>Highlight available slots &amp; move on grid</span>
                            </button>
                        </div>

                        <section class="tala-inspector-edit-card" aria-label="Edit schedule">
                            <header class="tala-inspector-edit-card__header">
                                <h4>Edit schedule</h4>
                                <span
                                    class="tala-inspector-slot-check"
                                    :class="evaluateProposedEdit().valid ? 'is-valid' : 'is-conflict'"
                                    x-text="evaluateProposedEdit().reason"
                                ></span>
                            </header>

                            <div class="tala-inspector-edit-grid">
                                <label class="tala-inspector-field">
                                    <span>Day</span>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select x-model.number="editForm.day">
                                            <template x-for="d in dayOptions" :key="d.value">
                                                <option :value="d.value" x-text="d.label"></option>
                                            </template>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </label>

                                <label class="tala-inspector-field">
                                    <span>Start time</span>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select x-model="editForm.startsAt">
                                            <template x-for="slot in timeSlotOptions" :key="slot.value">
                                                <option :value="slot.value" x-text="slot.label"></option>
                                            </template>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </label>

                                <label class="tala-inspector-field">
                                    <span>Room (<span x-text="(selectedMeeting?.componentBadge || 'LEC') === 'LAB' ? 'Laboratory compatible' : 'Lecture compatible'"></span>)</span>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select x-model="editForm.roomId">
                                            <template x-for="rm in compatibleRoomsFor(selectedMeeting)" :key="rm.id">
                                                <option :value="String(rm.id)" x-text="rm.code + (rm.name ? ' · ' + rm.name : '') + ' (' + rm.capacity + ' seats)'"></option>
                                            </template>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </label>

                                <label class="tala-inspector-field">
                                    <span>Qualified teacher</span>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select x-model="editForm.facultyId">
                                            <template x-for="teacher in qualifiedTeachersFor(selectedMeeting)" :key="teacher.id">
                                                <option :value="String(teacher.id)" x-text="teacher.name"></option>
                                            </template>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </label>
                            </div>

                            <div class="tala-inspector-edit-card__footer">
                                <span class="tala-inspector-edit-note">Draft adjustment · No approval memo required while under review.</span>
                                <x-filament::button
                                    type="button"
                                    color="primary"
                                    size="sm"
                                    icon="heroicon-m-check"
                                    x-on:click="submitInlineEdit()"
                                >
                                    Save schedule changes
                                </x-filament::button>
                            </div>
                        </section>
                    @elseif ($isOfficial)
                        <div class="tala-inspector-locked-notice">
                            <div>
                                <strong>Official timetable · Locked</strong>
                                <p>Draft drag-and-drop and direct edits are disabled after publication to protect enrolled students and faculty.</p>
                            </div>
                            @if (! empty($scheduleContext['reviseOfficialUrl']))
                                <x-filament::button
                                    tag="a"
                                    color="gray"
                                    size="sm"
                                    icon="heroicon-o-pencil-square"
                                    :href="$scheduleContext['reviseOfficialUrl']"
                                >
                                    Revise official schedule
                                </x-filament::button>
                            @endif
                        </div>
                    @endif
                </div>

                <x-slot name="footerActions"><x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: '{{ $meetingId }}' })">Close</x-filament::button></x-slot>
            </x-filament::modal>
        @endif

        @if ($presentation['slots'] > 0 && $presentation['days'] !== [])
            @if ($showWindow)<p x-show="mode === 'weekly'" class="tala-timetable-window">Recorded teaching window · {{ $presentation['ticks'][0]['label'] }}–{{ $presentation['ticks'][count($presentation['ticks']) - 1]['label'] }} · Asia/Manila</p>@endif
            <div x-show="mode === 'weekly'" class="tala-timetable-weekly" tabindex="0" role="region" aria-label="Weekly calendar, full recorded teaching window. Scroll to later hours." style="--timetable-days: {{ count($presentation['days']) }}; --timetable-slots: {{ $presentation['slots'] }};">
                <div class="tala-timetable-axis" aria-hidden="true">
                    <div class="tala-timetable-day-heading">Time</div>
                    <div class="tala-timetable-axis-body">
                        @foreach ($presentation['ticks'] as $tick)
                            <span @class(['tala-timetable-hour-label' => str_contains($tick['label'], ':00 '), 'tala-timetable-first-label' => $loop->first, 'tala-timetable-last-label' => $loop->last]) style="top: {{ $tick['offset'] }}%">{{ $tick['label'] }}</span>
                        @endforeach
                    </div>
                </div>
                @foreach ($presentation['days'] as $day)
                    <section class="tala-timetable-day" aria-label="{{ $day['label'] }} meetings">
                        <h4 class="tala-timetable-day-heading">{{ $day['label'] }}</h4>
                        <div class="tala-timetable-day-body">
                            @foreach ($presentation['ticks'] as $tick)
                                <span @class(['tala-timetable-rule', 'tala-timetable-hour-rule' => str_contains($tick['label'], ':00 ')]) aria-hidden="true" style="top: {{ $tick['offset'] }}%"></span>
                            @endforeach
                            @foreach ($presentation['blockedPeriods'] as $period)
                                @if ($period['day'] === $day['number'])
                                    <span @class(['tala-timetable-break', 'tala-timetable-compact-break' => isset($period['start'], $period['end']) && $period['end'] - $period['start'] < 30]) style="top: {{ $period['top'] }}%; height: {{ $period['height'] }}%;" title="{{ $period['label'] }} · {{ $period['time'] }}">
                                        <span class="tala-timetable-break-heading"><x-filament::icon icon="heroicon-o-clock" /><strong>{{ $period['label'] }}</strong></span>
                                        <span class="tala-timetable-break-time">{{ $period['time'] }}</span>
                                    </span>
                                @endif
                            @endforeach

                            @if ($editable && $gridSlots !== [])
                                <div class="tala-timetable-slot-layer" :class="{ 'is-active': activeMoveId !== null }" aria-hidden="true">
                                    @foreach ($gridSlots as $slot)
                                        <div
                                            class="tala-timetable-drop-slot"
                                            data-day="{{ $day['number'] }}"
                                            data-starts-at="{{ $slot['startsAt'] }}"
                                            style="top: {{ $slot['top'] }}%; height: {{ $slot['height'] }}%;"
                                            :class="slotClasses({{ $day['number'] }}, {{ $slot['start'] }}, '{{ $slot['startsAt'] }}')"
                                            x-on:dragover.prevent="onDragOverSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}')"
                                            x-on:dragleave="onDragLeaveSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}')"
                                            x-on:drop.prevent="onDropSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}')"
                                            x-on:click="onClickMoveSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}')"
                                        >
                                            <span class="tala-timetable-slot-cue" x-show="activeMoveId !== null" x-text="slotCueText({{ $day['number'] }}, {{ $slot['start'] }}, '{{ $slot['label'] }}')"></span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @foreach ($renderMeetings as $meeting)
                                @if ($meeting['plottable'] && $meeting['day'] === $day['number'])
                                    @php
                                        [$startsAt, $endsAt] = explode('–', $meeting['time'], 2);
                                        $roomLabel = \Illuminate\Support\Str::length($meeting['room']) > 24
                                            ? '…'.\Illuminate\Support\Str::substr($meeting['room'], -16)
                                            : $meeting['room'];
                                        $componentBadge = $meeting['componentBadge'] ?? (($meeting['component'] ?? '') === 'Laboratory' ? 'LAB' : 'LEC');
                                        $isPinned = (bool) ($meeting['isPinned'] ?? false);
                                    @endphp
                                    <button
                                        type="button"
                                        data-course-color="{{ $presentation['courseColors'][$meeting['courseCode']] ?? (crc32($meeting['courseCode']) % 4) + 1 }}"
                                        data-component="{{ $componentBadge }}"
                                        data-meeting-id="{{ $meeting['id'] }}"
                                        class="tala-timetable-meeting {{ $componentBadge === 'LAB' || ($meeting['component'] ?? '') === 'Laboratory' ? 'tala-timetable-laboratory' : 'tala-timetable-lecture' }} {{ $meeting['end'] - $meeting['start'] < 60 ? 'tala-timetable-compact-meeting' : '' }} {{ $isPinned ? 'is-pinned' : '' }}"
                                        style="top: {{ $meeting['top'] }}%; height: {{ $meeting['height'] }}%; inset-inline-start: {{ $meeting['lane'] / $meeting['lanes'] * 100 }}%; width: {{ 100 / $meeting['lanes'] }}%;"
                                        x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))"
                                        @if ($editable)
                                            draggable="true"
                                            x-on:dragstart="startDragging({{ \Illuminate\Support\Js::from($meeting['id']) }}, $event)"
                                            x-on:dragend="stopDragging()"
                                            :class="{ 'is-dragging': draggingId === {{ \Illuminate\Support\Js::from($meeting['id']) }}, 'is-pinned': isMeetingPinned({{ \Illuminate\Support\Js::from($meeting['id']) }}) }"
                                        @endif
                                        @if ($nativeDetails) wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })"
                                        @else x-on:click="selected = {{ \Illuminate\Support\Js::from($meeting['id']) }}; $dispatch('open-modal', { id: '{{ $meetingId }}' })" @endif
                                        aria-haspopup="dialog"
                                        @if (! $nativeDetails) aria-controls="{{ $meetingId }}" @endif
                                        aria-label="{{ $meeting['courseCode'] }}, {{ $meeting['courseTitle'] }}, {{ $meeting['class'] }}, {{ $day['label'] }} {{ $meeting['time'] }}, {{ $meeting['faculty'] }}, {{ $meeting['room'] }}, {{ $meeting['component'] }} ({{ $componentBadge }}), {{ $meeting['modality'] }}{{ $isPinned ? ', Pinned' : '' }}{{ $meeting['offGrid'] ? ', outside recorded teaching grid' : '' }}"
                                    >
                                        <span class="tala-timetable-tile-header">
                                            <span class="tala-timetable-tile-identity">
                                                <span class="tala-timetable-tile-code">{{ $meeting['courseCode'] }}</span>
                                                @if (filled($meeting['class'] ?? null) && ($meeting['class'] ?? '') !== 'Class unavailable')
                                                    <span class="tala-timetable-tile-sep" aria-hidden="true">·</span>
                                                    <span class="tala-timetable-tile-class">{{ $meeting['class'] }}</span>
                                                @endif
                                            </span>
                                            <span class="tala-timetable-tile-badges">
                                                <span
                                                    class="tala-timetable-pin-badge"
                                                    title="Pinned meeting slot"
                                                    @if (! $isPinned) x-cloak @endif
                                                    x-show="isMeetingPinned({{ \Illuminate\Support\Js::from($meeting['id']) }})"
                                                >
                                                    <x-filament::icon icon="heroicon-s-map-pin" />
                                                    <span class="sr-only">Pinned</span>
                                                </span>
                                                <span class="tala-timetable-component-badge {{ $componentBadge === 'LAB' ? 'is-lab' : 'is-lec' }}">{{ $componentBadge }}</span>
                                            </span>
                                        </span>
                                        <span class="tala-timetable-tile-body">
                                            <span class="tala-timetable-tile-clock">
                                                <span class="tala-timetable-tile-start">{{ \Illuminate\Support\Str::beforeLast($startsAt, ' ') }}</span>
                                                <span class="tala-timetable-tile-meridiem">{{ \Illuminate\Support\Str::afterLast($startsAt, ' ') }}</span>
                                                <span class="tala-timetable-tile-end">to {{ $endsAt }}</span>
                                            </span>
                                            <span class="tala-timetable-tile-text">
                                                <strong class="tala-timetable-course-title">{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong>
                                                @if (filled($meeting['faculty'] ?? null) && ! in_array($meeting['faculty'], ['Faculty unassigned', 'Faculty unavailable'], true))
                                                    <span class="tala-timetable-tile-teacher" title="{{ $meeting['faculty'] }}">
                                                        <x-filament::icon icon="heroicon-o-user" />
                                                        <span>{{ $meeting['faculty'] }}</span>
                                                    </span>
                                                @endif
                                            </span>
                                        </span>
                                        <span class="tala-timetable-tile-location" title="{{ $meeting['room'] }}">
                                            <x-filament::icon icon="heroicon-o-map-pin" />
                                            <span>{{ $roomLabel }}</span>
                                        </span>
                                        @if ($meeting['offGrid'])<span class="tala-timetable-attention" title="Outside recorded teaching grid">Outside grid</span>@endif
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif

        <div class="tala-timetable-list" :class="{'tala-timetable-list-active': mode === 'list'}">
            @foreach ($renderMeetings as $meeting)
                @php
                    $componentBadge = $meeting['componentBadge'] ?? (($meeting['component'] ?? '') === 'Laboratory' ? 'LAB' : 'LEC');
                    $isPinned = (bool) ($meeting['isPinned'] ?? false);
                @endphp
                @if ($nativeDetails)
                    @php
                        $mobileMeta = collect([
                            $meeting['courseCode'] ?? null,
                            ($meeting['class'] ?? '') !== 'Class unavailable' ? ($meeting['class'] ?? null) : null,
                        ])->filter()->implode(' · ');
                    @endphp
                    <div class="tala-timetable-list-meeting {{ ! $meeting['plottable'] ? 'tala-timetable-unplaced' : '' }}" x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))">
                        <button type="button" class="tala-timetable-mobile-meeting" data-course-color="{{ $presentation['courseColors'][$meeting['courseCode']] ?? (crc32($meeting['courseCode']) % 4) + 1 }}" wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })">
                            <span class="tala-timetable-list-time">{{ $meeting['dayLabel'] }}<br>{{ $meeting['time'] }}</span>
                            <span class="tala-timetable-mobile-course">
                                <strong>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong>
                                @if ($mobileMeta !== '')
                                    <span class="tala-timetable-list-title">{{ $mobileMeta }} · {{ $componentBadge }}</span>
                                @endif
                            </span>
                            <span class="tala-timetable-meeting-room">{{ $meeting['room'] }}<span class="tala-timetable-list-title">{{ $meeting['faculty'] ?? 'View details' }}</span></span>
                        </button>
                    </div>
                @else
                <div class="tala-timetable-list-meeting {{ ! $meeting['plottable'] ? 'tala-timetable-unplaced' : '' }}" x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))">
                    <button type="button" class="tala-timetable-mobile-meeting" data-course-color="{{ $presentation['courseColors'][$meeting['courseCode']] ?? (crc32($meeting['courseCode']) % 4) + 1 }}" aria-haspopup="dialog" aria-controls="{{ $meetingId }}" x-on:click="selected = {{ \Illuminate\Support\Js::from($meeting['id']) }}; $dispatch('open-modal', { id: '{{ $meetingId }}' })">
                        <span class="tala-timetable-list-time">{{ $meeting['dayLabel'] }}<br>{{ $meeting['time'] }}</span>
                        <span class="tala-timetable-mobile-course">
                            <strong>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong>
                            <span class="tala-timetable-list-title">
                                {{ $meeting['courseCode'] }} · {{ $meeting['class'] }} · <span class="tala-timetable-component-badge {{ $componentBadge === 'LAB' ? 'is-lab' : 'is-lec' }}">{{ $componentBadge }}</span>
                                <span @if (! $isPinned) x-cloak @endif x-show="isMeetingPinned({{ \Illuminate\Support\Js::from($meeting['id']) }})" class="tala-timetable-pin-inline">· Pinned</span>
                            </span>
                        </span>
                        <span class="tala-timetable-meeting-room">{{ $meeting['room'] }}<span class="tala-timetable-list-title">{{ $meeting['faculty'] ?? 'View details' }}</span></span>
                    </button>
                </div>
                @endif
            @endforeach
        </div>
        @if (collect($renderMeetings)->contains('plottable', false))
            <p class="tala-timetable-guide">Some meetings have an invalid or missing day or time. They remain visible in the meeting list.</p>
        @endif
    @endif
</section>

@if (! $focused)
    <x-filament::modal footer-actions-alignment="end" :id="$overviewId" width="screen" sticky-header heading="Whole timetable">
        <x-tala-timetable :presentation="$presentation" :focused="true" />
    </x-filament::modal>
@endif
