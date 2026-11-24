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
    'overviewId' => null,
])
@php
    $overviewId = $overviewId ?: str_replace('.', '-', uniqid('timetable-overview-', true));
    $meetingId = $overviewId.'-meeting';
    $renderMeetings = $interactive
        ? ($presentation['allMeetings'] ?? $presentation['meetings'] ?? [])
        : ($presentation['meetings'] ?? []);
    $gridSlots = $presentation['gridSlots'] ?? [];

    $activeDayNumbers = collect($presentation['days'] ?? [])
        ->pluck('number')
        ->map(fn ($n): int => (int) $n)
        ->values()
        ->all();
    $activeDayCount = count($activeDayNumbers);
    $sharedFullWidthBreaks = [];
    $sharedBreakKeys = [];

    if ($activeDayCount > 1 && ! empty($presentation['blockedPeriods'])) {
        $groupedBreaks = collect($presentation['blockedPeriods'])->groupBy(
            fn (array $period): string => ($period['start'] ?? '').'-'.($period['end'] ?? '').'-'.($period['label'] ?? ''),
        );

        foreach ($groupedBreaks as $breakKey => $group) {
            $coveredDays = $group->pluck('day')->map(fn ($n): int => (int) $n)->unique()->values()->all();
            if (count(array_intersect($activeDayNumbers, $coveredDays)) === $activeDayCount) {
                $first = $group->first();
                $isMiddayBreak = ($first['label'] ?? '') === 'Approved break'
                    && isset($first['start'], $first['end'])
                    && (int) $first['start'] >= 660
                    && (int) $first['end'] <= 870;

                $breakTime = (string) ($first['time'] ?? '');
                [$bStart, $bEnd] = array_pad(explode('–', $breakTime, 2), 2, '');
                $bStartMeridiem = \Illuminate\Support\Str::afterLast($bStart, ' ');
                $bEndMeridiem = \Illuminate\Support\Str::afterLast($bEnd, ' ');
                $compactBreakTime = ($bStartMeridiem !== '' && $bStartMeridiem === $bEndMeridiem)
                    ? \Illuminate\Support\Str::beforeLast($bStart, ' ').'–'.$bEnd
                    : $breakTime;

                $sharedFullWidthBreaks[] = [
                    ...$first,
                    'displayLabel' => $isMiddayBreak ? 'Lunch break' : ($first['label'] ?? 'Break'),
                    'compactTime' => $compactBreakTime,
                ];
                $sharedBreakKeys[$breakKey] = true;
            }
        }
    }
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
        storageKey: {{ \Illuminate\Support\Js::from('tala-tt-state:'.$overviewId) }},
        selectedStorageKey: {{ \Illuminate\Support\Js::from('tala-tt-selected:'.$overviewId) }},
        hasExplicitServerFilters: {{ \Illuminate\Support\Js::from($initialView !== 'whole' || ($initialEntity ?? '') !== '' || ($initialProgram ?? '') !== '' || ($initialYear ?? '') !== '' || ($initialSearch ?? '') !== '' || $initialFormat === 'list') }},
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
        selectedListDay: 'all',
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
        gridDays: {{ \Illuminate\Support\Js::from($presentation['days'] ?? []) }},
        gridSlots: {{ \Illuminate\Support\Js::from($gridSlots) }},
        reviseOfficialUrl: {{ \Illuminate\Support\Js::from($scheduleContext['reviseOfficialUrl'] ?? '') }},
        draggingId: null,
        activeMoveId: null,
        isKeyboardMoveMode: false,
        hoverSlotKey: null,
        slotEvaluations: {},
        moveAnnouncement: '',
        editForm: {
            day: 1,
            startsAt: '08:00',
            roomId: '',
            facultyId: '',
        },
        init() {
            if (this.interactive && !this.hasExplicitServerFilters) {
                this.restoreFilterState();
            }
            if (this.interactive) {
                this.restoreSelectedMeeting();
            }
            this.$watch('selected', (val) => {
                const m = this.meetings.find(item => item.id === val);
                if (m) {
                    this.syncEditForm(m);
                }
                this.persistSelectedMeeting(m ?? null);
                if (val && window.innerWidth < 1152) {
                    this.$nextTick(() => this.$refs.railInspector?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
                }
            });
            if (this.interactive) {
                ['viewMode', 'entityFilter', 'programFilter', 'yearFilter', 'searchQuery', 'mode'].forEach((prop) => {
                    this.$watch(prop, () => this.persistFilterState());
                });
            }
        },
        meetingIdentity(m) {
            if (!m) return '';
            if (m.schedulingDemandId) {
                return Number(m.schedulingDemandId) + ':' + Number(m.meetingSequence || 1);
            }
            return String(m.id ?? '');
        },
        persistSelectedMeeting(m) {
            if (!this.interactive) return;
            try {
                const identity = this.meetingIdentity(m);
                if (identity) {
                    window.sessionStorage.setItem(this.selectedStorageKey, identity);
                } else {
                    window.sessionStorage.removeItem(this.selectedStorageKey);
                }
            } catch (e) {}
        },
        restoreSelectedMeeting() {
            if (!this.interactive) return;
            try {
                const savedIdentity = window.sessionStorage.getItem(this.selectedStorageKey);
                if (!savedIdentity) return;
                const matched = this.meetings.find(item =>
                    this.meetingIdentity(item) === savedIdentity || String(item.id) === savedIdentity
                );
                if (matched) {
                    this.selected = matched.id;
                    this.syncEditForm(matched);
                }
            } catch (e) {}
        },
        persistFilterState() {
            if (!this.interactive) return;
            try {
                window.sessionStorage.setItem(this.storageKey, JSON.stringify({
                    viewMode: this.viewMode,
                    entityFilter: this.entityFilter,
                    programFilter: this.programFilter,
                    yearFilter: this.yearFilter,
                    searchQuery: this.searchQuery,
                    mode: this.mode,
                }));
            } catch (e) {}
        },
        restoreFilterState() {
            try {
                const raw = window.sessionStorage.getItem(this.storageKey);
                if (!raw) return;
                const saved = JSON.parse(raw);
                if (!saved || typeof saved !== 'object') return;
                if (['whole', 'section', 'faculty', 'room'].includes(saved.viewMode)) {
                    this.viewMode = saved.viewMode;
                }
                if (typeof saved.entityFilter === 'string') {
                    this.entityFilter = saved.entityFilter;
                }
                if (typeof saved.programFilter === 'string') {
                    this.programFilter = saved.programFilter;
                }
                if (typeof saved.yearFilter === 'string') {
                    this.yearFilter = saved.yearFilter;
                }
                if (typeof saved.searchQuery === 'string') {
                    this.searchQuery = saved.searchQuery;
                }
                if (saved.mode === 'list' || saved.mode === 'weekly') {
                    this.mode = saved.mode;
                }
            } catch (e) {}
        },
        syncServerMeetings(nextMeetings) {
            const prevSelected = this.selectedMeeting;
            this.meetings = Array.isArray(nextMeetings) ? nextMeetings : [];
            if (this.selected !== null) {
                const direct = this.meetings.find(item => String(item.id) === String(this.selected));
                if (direct) {
                    this.selected = direct.id;
                    this.syncEditForm(direct);
                } else if (prevSelected) {
                    const matched = this.meetings.find(item =>
                        item.schedulingDemandId && prevSelected.schedulingDemandId
                        && Number(item.schedulingDemandId) === Number(prevSelected.schedulingDemandId)
                        && Number(item.meetingSequence || 1) === Number(prevSelected.meetingSequence || 1)
                    );
                    if (matched) {
                        this.selected = matched.id;
                        this.syncEditForm(matched);
                    }
                }
            }
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
            const switched = this.viewMode !== newMode;
            this.viewMode = newMode;
            if (switched) {
                this.entityFilter = '';
            }
            if (newMode === 'section' && !this.entityFilter) {
                this.entityFilter = this.sectionOptions[0]?.id ? String(this.sectionOptions[0].id) : '';
            } else if (newMode === 'faculty' && !this.entityFilter) {
                this.entityFilter = this.facultyOptions[0] ? String(this.facultyOptions[0]) : '';
            } else if (newMode === 'room' && !this.entityFilter) {
                this.entityFilter = this.roomOptions[0] ? String(this.roomOptions[0]) : '';
            } else if (newMode === 'whole') {
                this.entityFilter = '';
            }
        },
        setFormat(newFormat) {
            this.mode = newFormat === 'list' ? 'list' : 'weekly';
        },
        hasActiveFilters() {
            return this.viewMode !== 'whole'
                || this.entityFilter !== ''
                || this.programFilter !== ''
                || this.yearFilter !== ''
                || (this.mode === 'list' && this.selectedListDay !== 'all')
                || (this.searchQuery || '').trim() !== '';
        },
        clearAllFilters() {
            this.viewMode = 'whole';
            this.entityFilter = '';
            this.programFilter = '';
            this.yearFilter = '';
            this.searchQuery = '';
            this.selectedListDay = 'all';
            this.filters = {faculty: '', room: '', class: '', modality: ''};
        },
        matches(meeting) {
            if (!meeting) return false;
            if (this.mode === 'list' && this.selectedListDay !== 'all' && Number(meeting.day) !== Number(this.selectedListDay)) {
                return false;
            }
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
        get visibleLayout() {
            const layout = {};
            const visible = (this.meetings || [])
                .filter(m => m && m.plottable && this.matches(m))
                .slice()
                .sort((a, b) => (Number(a.day) - Number(b.day))
                    || (Number(a.start ?? 1441) - Number(b.start ?? 1441))
                    || (Number(a.end ?? 1441) - Number(b.end ?? 1441))
                    || String(a.id).localeCompare(String(b.id)));
            let group = [];
            let laneEnds = [];
            let currentDay = null;
            let groupEnd = null;
            const flushGroup = () => {
                const totalLanes = Math.max(1, laneEnds.length);
                for (const id of group) {
                    if (layout[id]) {
                        layout[id].lanes = totalLanes;
                    }
                }
            };
            for (const m of visible) {
                const start = Number(m.start ?? 0);
                const end = Number(m.end ?? 0);
                if (m.day !== currentDay || start >= groupEnd) {
                    flushGroup();
                    group = [];
                    laneEnds = [];
                    currentDay = m.day;
                    groupEnd = end;
                }
                let lane = 0;
                while (lane < laneEnds.length && laneEnds[lane] > start) {
                    lane++;
                }
                laneEnds[lane] = end;
                layout[m.id] = { lane, lanes: 1 };
                group.push(m.id);
                groupEnd = Math.max(groupEnd, end);
            }
            flushGroup();
            return layout;
        },
        meetingStyle(id, fallbackTop, fallbackHeight, fallbackLane, fallbackLanes) {
            const box = this.visibleLayout[id];
            const lane = box ? box.lane : fallbackLane;
            const lanes = box ? Math.max(1, box.lanes) : Math.max(1, fallbackLanes);
            return {
                top: fallbackTop + '%',
                height: fallbackHeight + '%',
                insetInlineStart: (lane / lanes * 100) + '%',
                width: (100 / lanes) + '%',
            };
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
        selectedClassLabel(meeting) {
            if (!meeting?.class) return '';
            const code = (meeting.courseCode || '').trim();
            const cls = meeting.class.trim();
            if (!cls || cls === 'Class unavailable') return '';
            if (code && cls.toUpperCase() === code.toUpperCase()) return '';
            if (code) {
                const escaped = code.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                return cls.replace(new RegExp('^' + escaped + '\\s*[·\\-:]\\s*', 'i'), '');
            }
            return cls;
        },
        reviseMeetingUrl(meeting) {
            if (!this.reviseOfficialUrl) return '';
            const mid = meeting?.sectionMeetingId;
            if (!mid) return this.reviseOfficialUrl;
            const sep = this.reviseOfficialUrl.includes('?') ? '&' : '?';
            return this.reviseOfficialUrl + sep + 'meetingId=' + encodeURIComponent(String(mid));
        },
        reviseSelectedOfficialMeeting() {
            const meeting = this.selectedMeeting;
            const sectionMeetingId = meeting?.sectionMeetingId ? Number(meeting.sectionMeetingId) : null;
            if (this.$wire && typeof this.$wire.mountAction === 'function') {
                this.$wire.mountAction(
                    'revisePublishedSchedule',
                    sectionMeetingId ? { section_meeting_id: sectionMeetingId } : {}
                );
                return;
            }
            const url = this.reviseMeetingUrl(meeting);
            if (url) {
                window.location.href = url;
            }
        },
        computeSlotEvaluations(meeting) {
            const evaluated = {};
            if (!meeting) {
                this.slotEvaluations = evaluated;
                return;
            }
            for (const day of (this.gridDays || [])) {
                const dayNumber = Number(day.number);
                for (const slot of (this.gridSlots || [])) {
                    const key = dayNumber + ':' + slot.startsAt;
                    const isCurrent = Number(meeting.day) === dayNumber && meeting.startsAt === slot.startsAt;
                    const check = this.evaluateSlotForMeeting(meeting, dayNumber, slot.start);
                    evaluated[key] = {
                        valid: check.valid,
                        reason: check.reason,
                        isCurrent,
                    };
                }
            }
            this.slotEvaluations = evaluated;
        },
        slotEvaluation(dayNumber, startsAt) {
            return this.slotEvaluations[dayNumber + ':' + startsAt] ?? null;
        },
        isSlotAvailable(dayNumber, startsAt) {
            const state = this.slotEvaluation(dayNumber, startsAt);
            return Boolean(state && state.valid && !state.isCurrent);
        },
        startDragging(id, event) {
            if (!this.editable) return;
            this.isKeyboardMoveMode = false;
            this.draggingId = id;
            this.activeMoveId = id;
            const target = this.activeMoveMeeting;
            this.computeSlotEvaluations(target);
            if (target) {
                this.moveAnnouncement = 'Move mode started for ' + (target.courseCode || 'meeting') + (target.class ? ' ' + target.class : '') + '. Choose a highlighted available slot or press Escape to cancel.';
            }
            if (event && event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(id));
            }
        },
        stopDragging() {
            this.draggingId = null;
            this.hoverSlotKey = null;
            if (!this.isKeyboardMoveMode) {
                this.cancelMoveMode(false);
            }
        },
        startMoveOnGrid() {
            if (!this.editable || !this.selectedMeeting) return;
            const target = this.selectedMeeting;
            this.isKeyboardMoveMode = true;
            this.activeMoveId = target.id;
            this.computeSlotEvaluations(target);
            this.moveAnnouncement = 'Move mode started for ' + (target.courseCode || 'meeting') + (target.class ? ' ' + target.class : '') + '. Use Tab to move between highlighted available slots or press Escape to cancel.';
            if (!this.interactive) {
                this.$dispatch('close-modal', { id: '{{ $meetingId }}' });
            }
            this.$nextTick(() => {
                const firstAvailable = this.$el.querySelector('.tala-timetable-drop-slot.is-slot-available');
                if (firstAvailable) {
                    firstAvailable.focus();
                } else {
                    this.$el.querySelector('.tala-move-slot-banner__cancel')?.focus();
                }
            });
        },
        cancelMoveMode(announce = true) {
            const wasActive = this.activeMoveId !== null;
            this.activeMoveId = null;
            this.draggingId = null;
            this.isKeyboardMoveMode = false;
            this.hoverSlotKey = null;
            this.slotEvaluations = {};
            if (announce && wasActive) {
                this.moveAnnouncement = 'Move cancelled.';
            }
        },
        evaluateSlotForMeeting(meeting, dayNumber, slotStart, overrideRoomId = null, overrideFacultyId = null) {
            if (!meeting) return { valid: false, reason: '' };
            const duration = Number(meeting.durationMinutes || ((meeting.end || 0) - (meeting.start || 0)) || 60);
            const slotEnd = Number(slotStart) + duration;
            const day = Number(dayNumber);
            const targetFacultyId = overrideFacultyId !== null && overrideFacultyId !== '' ? Number(overrideFacultyId) : Number(meeting.facultyId || 0);
            const targetRoomId = overrideRoomId !== null && overrideRoomId !== '' ? Number(overrideRoomId) : Number(meeting.roomId || 0);

            if (targetRoomId > 0) {
                const roomObj = (this.rooms || []).find(r => Number(r.id) === targetRoomId);
                const reqCap = Number(meeting.requiredCapacity || 0);
                if (roomObj && reqCap > 0 && Number(roomObj.capacity || 0) < reqCap) {
                    return { valid: false, reason: 'Room too small (' + roomObj.capacity + ' < ' + reqCap + ' seats)' };
                }
            }

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
        slotClasses(dayNumber, startsAt) {
            const check = this.slotEvaluation(dayNumber, startsAt);
            if (!check) return {};
            const key = dayNumber + ':' + startsAt;
            return {
                'is-slot-available': check.valid && !check.isCurrent,
                'is-slot-current': check.isCurrent,
                'is-slot-blocked': !check.valid,
                'is-slot-hover': this.hoverSlotKey === key,
            };
        },
        slotCueText(dayNumber, startsAt, label) {
            const check = this.slotEvaluation(dayNumber, startsAt);
            if (!check) return '';
            if (check.isCurrent) return 'Current · ' + label;
            return check.valid ? ('Move here · ' + label) : check.reason;
        },
        slotAriaLabel(dayNumber, dayLabel, startsAt, label) {
            const check = this.slotEvaluation(dayNumber, startsAt);
            const meeting = this.activeMoveMeeting;
            const targetName = meeting ? (meeting.courseCode + (meeting.class ? ' ' + meeting.class : '')) : 'meeting';
            if (!check) {
                return dayLabel + ', ' + label;
            }
            if (check.isCurrent) {
                return dayLabel + ', ' + label + ' — Current slot for ' + targetName + '.';
            }
            if (check.valid) {
                return dayLabel + ', ' + label + ' — Available, conflict-free. Select to move ' + targetName + '.';
            }
            return dayLabel + ', ' + label + ' — Unavailable: ' + check.reason + '.';
        },
        onDragOverSlot(dayNumber, startsAt) {
            if (!this.editable || !this.activeMoveMeeting) return;
            const key = dayNumber + ':' + startsAt;
            if (this.hoverSlotKey !== key) {
                this.hoverSlotKey = key;
            }
        },
        onDragLeaveSlot(dayNumber, startsAt) {
            const key = dayNumber + ':' + startsAt;
            if (this.hoverSlotKey === key) {
                this.hoverSlotKey = null;
            }
        },
        moveToSlot(dayNumber, startsAt, dayLabel = '', timeLabel = '') {
            if (!this.editable || !this.activeMoveMeeting) return;
            const check = this.slotEvaluation(dayNumber, startsAt);
            if (check && check.isCurrent) {
                this.moveAnnouncement = 'Meeting is already scheduled in this slot.';
                if (!this.isKeyboardMoveMode) {
                    this.cancelMoveMode(false);
                }
                return;
            }
            if (check && !check.valid) {
                this.moveAnnouncement = 'Cannot move here: ' + check.reason + '.';
                if (!this.isKeyboardMoveMode) {
                    this.cancelMoveMode(false);
                }
                return;
            }
            const meeting = this.activeMoveMeeting;
            const rowId = Number(meeting.rowId || meeting.id);
            const targetName = (meeting.courseCode || 'Meeting') + (meeting.class ? ' ' + meeting.class : '');
            this.persistSelectedMeeting(meeting);
            this.moveAnnouncement = 'Moving ' + targetName + (dayLabel && timeLabel ? (' to ' + dayLabel + ' at ' + timeLabel) : '') + '.';
            this.cancelMoveMode(false);
            if (rowId > 0 && this.$wire && typeof this.$wire.moveCandidateMeeting === 'function') {
                this.$wire.moveCandidateMeeting(rowId, Number(dayNumber), String(startsAt));
            }
        },
        onDropSlot(dayNumber, startsAt, dayLabel = '', timeLabel = '') {
            this.moveToSlot(dayNumber, startsAt, dayLabel, timeLabel);
        },
        onClickMoveSlot(dayNumber, startsAt, dayLabel = '', timeLabel = '') {
            this.moveToSlot(dayNumber, startsAt, dayLabel, timeLabel);
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
            const reqCap = Number(meeting.requiredCapacity || 0);
            const matching = this.rooms.filter(r => {
                const rt = (r.roomType || '').toUpperCase();
                const typeOk = isLab ? rt.includes('LAB') : !rt.includes('LAB');
                const capOk = reqCap <= 0 || Number(r.capacity || 0) >= reqCap || Number(r.id) === Number(meeting.roomId);
                return typeOk && capOk;
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
        timeSlotOptionLabel(slot) {
            if (!slot) return '';
            if (!this.selectedMeeting) return slot.label;
            const check = this.evaluateSlotForMeeting(
                this.selectedMeeting,
                this.editForm.day,
                slot.minutes,
                this.editForm.roomId,
                this.editForm.facultyId
            );
            const isCurrent = Number(this.selectedMeeting.day) === Number(this.editForm.day)
                && this.selectedMeeting.startsAt === slot.value;
            if (isCurrent && check.valid) {
                return slot.label + ' — Current slot';
            }
            return slot.label + ' — ' + (check.valid ? 'Available' : check.reason);
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
        async submitInlineEdit() {
            if (!this.editable || !this.selectedMeeting) return;
            if (!this.evaluateProposedEdit().valid) return;
            const rowId = Number(this.selectedMeeting.rowId || this.selectedMeeting.id);
            const day = Number(this.editForm.day || this.selectedMeeting.day || 1);
            const startsAt = String(this.editForm.startsAt || this.selectedMeeting.startsAt || '08:00');
            const roomId = this.editForm.roomId ? Number(this.editForm.roomId) : null;
            const facultyId = this.editForm.facultyId ? Number(this.editForm.facultyId) : null;
            this.persistSelectedMeeting(this.selectedMeeting);
            if (rowId > 0 && this.$wire && typeof this.$wire.updateCandidateMeeting === 'function') {
                try {
                    await this.$wire.updateCandidateMeeting(rowId, day, startsAt, roomId, facultyId);
                    if (!this.interactive) {
                        this.$dispatch('close-modal', { id: '{{ $meetingId }}' });
                    }
                } catch (e) {}
            }
        }
    }"
    x-on:keydown.escape.window="if (activeMoveId !== null) { cancelMoveMode() }"
>
    @if ($interactive)
        <div wire:key="meetings-payload-{{ md5(json_encode($renderMeetings)) }}" x-init="syncServerMeetings({{ \Illuminate\Support\Js::from($renderMeetings) }})" hidden aria-hidden="true"></div>
    @endif
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
            <div class="tala-timetable-studio-split">
                <div class="tala-timetable-studio-main">
                    @if ($editable)
                        <div class="sr-only" role="status" aria-live="polite" aria-atomic="true" x-text="moveAnnouncement"></div>
                    @endif
        @elseif (! $controlled)
        <details class="tala-timetable-filters">
            <summary>Filter by faculty, room, class or modality</summary>
            <div class="tala-timetable-filter-fields">
                @foreach (['faculty' => 'Teacher', 'room' => 'Room', 'class' => 'Class', 'modality' => 'Modality'] as $field => $label)
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

        @if (! $nativeDetails && ! $interactive)
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
                        <strong x-text="selectedMeeting?.courseCode"></strong><template x-if="selectedMeeting?.class && selectedMeeting.class.trim().toUpperCase() !== (selectedMeeting?.courseCode || '').trim().toUpperCase()"><span> · <span x-text="selectedMeeting.class.replace(new RegExp('^' + (selectedMeeting?.courseCode || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*[·\\-:]\\s*', 'i'), '')"></span></span></template>
                        <template x-if="selectedMeeting?.isPinned">
                            <span class="tala-inspector-pinned-pill">
                                <x-filament::icon icon="heroicon-m-lock-closed" />
                                <span>Locked slot</span>
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
                        <div><dt>Teacher</dt><dd x-text="selectedMeeting?.faculty"></dd></div>
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
                                title="Keeps this class fixed to this day, time, room, and teacher if you regenerate."
                                x-on:click="togglePinSelected()"
                            >
                                <span class="tala-inspector-btn__icon" x-show="selectedMeeting?.isPinned" x-cloak><x-filament::icon icon="heroicon-o-lock-open" /></span>
                                <span class="tala-inspector-btn__icon" x-show="!selectedMeeting?.isPinned"><x-filament::icon icon="heroicon-m-lock-closed" /></span>
                                <span x-text="selectedMeeting?.isPinned ? 'Unlock slot' : 'Lock slot'">Lock slot</span>
                            </button>

                            <button
                                type="button"
                                class="tala-inspector-btn"
                                x-on:click="startMoveOnGrid()"
                            >
                                <x-filament::icon icon="heroicon-o-arrows-pointing-out" />
                                <span>Move on grid</span>
                            </button>
                            <p class="tala-inspector-pin-helper">Keeps this class fixed to this day, time, room, and teacher if you regenerate.</p>
                        </div>

                        <section class="tala-inspector-edit-card" aria-label="Edit schedule">
                            <header class="tala-inspector-edit-card__header">
                                <h3>Edit schedule</h3>
                                <span
                                    class="tala-inspector-slot-check"
                                    role="status"
                                    aria-live="polite"
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
                                                <option :value="slot.value" x-text="timeSlotOptionLabel(slot)"></option>
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
                                    x-bind:disabled="!evaluateProposedEdit().valid"
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
                                    Revise schedule
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
                    <div class="tala-timetable-day-heading tala-timetable-axis-heading">Time</div>
                    <div class="tala-timetable-axis-body">
                        @foreach ($presentation['ticks'] as $tick)
                            <span @class(['tala-timetable-hour-label' => str_contains($tick['label'], ':00 '), 'tala-timetable-first-label' => $loop->first, 'tala-timetable-last-label' => $loop->last]) style="top: {{ $tick['offset'] }}%">{{ $tick['label'] }}</span>
                        @endforeach
                    </div>
                </div>
                @foreach ($presentation['days'] as $day)
                    <section @class(['tala-timetable-day', 'is-last-day' => $loop->last]) style="grid-column: {{ $loop->iteration + 1 }};" aria-label="{{ $day['label'] }} meetings">
                        <h2 class="tala-timetable-day-heading">{{ $day['label'] }}</h2>
                        <div class="tala-timetable-day-body">
                            @foreach ($presentation['ticks'] as $tick)
                                <span @class(['tala-timetable-rule', 'tala-timetable-hour-rule' => str_contains($tick['label'], ':00 ')]) aria-hidden="true" style="top: {{ $tick['offset'] }}%"></span>
                            @endforeach
                            @foreach ($presentation['blockedPeriods'] as $period)
                                @php
                                    $periodBreakKey = ($period['start'] ?? '').'-'.($period['end'] ?? '').'-'.($period['label'] ?? '');
                                @endphp
                                @if ($period['day'] === $day['number'] && ! isset($sharedBreakKeys[$periodBreakKey]))
                                    <span @class(['tala-timetable-break', 'tala-timetable-compact-break' => isset($period['start'], $period['end']) && $period['end'] - $period['start'] < 30]) style="top: {{ $period['top'] }}%; height: {{ $period['height'] }}%;" title="{{ $period['label'] }} · {{ $period['time'] }}">
                                        <span class="tala-timetable-break-heading"><x-filament::icon icon="heroicon-o-clock" /><strong>{{ $period['label'] }}</strong></span>
                                        <span class="tala-timetable-break-time">{{ $period['time'] }}</span>
                                    </span>
                                @endif
                            @endforeach

                            @if ($editable && $gridSlots !== [])
                                <div class="tala-timetable-slot-layer" :class="{ 'is-active': activeMoveId !== null }" :aria-hidden="activeMoveId === null ? 'true' : 'false'">
                                    @foreach ($gridSlots as $slot)
                                        <button
                                            type="button"
                                            class="tala-timetable-drop-slot"
                                            data-day="{{ $day['number'] }}"
                                            data-starts-at="{{ $slot['startsAt'] }}"
                                            style="top: {{ $slot['top'] }}%; height: {{ $slot['height'] }}%;"
                                            :tabindex="activeMoveId !== null && isSlotAvailable({{ $day['number'] }}, '{{ $slot['startsAt'] }}') ? 0 : -1"
                                            :aria-disabled="activeMoveId === null || !isSlotAvailable({{ $day['number'] }}, '{{ $slot['startsAt'] }}') ? 'true' : null"
                                            :aria-label="slotAriaLabel({{ $day['number'] }}, {{ \Illuminate\Support\Js::from($day['label']) }}, '{{ $slot['startsAt'] }}', {{ \Illuminate\Support\Js::from($slot['label']) }})"
                                            :class="slotClasses({{ $day['number'] }}, '{{ $slot['startsAt'] }}')"
                                            x-on:dragover.prevent="onDragOverSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}')"
                                            x-on:dragleave="onDragLeaveSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}')"
                                            x-on:drop.prevent="onDropSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}', {{ \Illuminate\Support\Js::from($day['label']) }}, {{ \Illuminate\Support\Js::from($slot['label']) }})"
                                            x-on:click="onClickMoveSlot({{ $day['number'] }}, '{{ $slot['startsAt'] }}', {{ \Illuminate\Support\Js::from($day['label']) }}, {{ \Illuminate\Support\Js::from($slot['label']) }})"
                                        >
                                            <span class="tala-timetable-slot-cue" aria-hidden="true" x-show="activeMoveId !== null" x-text="slotCueText({{ $day['number'] }}, '{{ $slot['startsAt'] }}', {{ \Illuminate\Support\Js::from($slot['label']) }})"></span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            @foreach ($renderMeetings as $meeting)
                                @if ($meeting['plottable'] && $meeting['day'] === $day['number'])
                                    @php
                                        [$startsAt, $endsAt] = array_pad(explode('–', $meeting['time'], 2), 2, '');
                                        $startMeridiem = \Illuminate\Support\Str::afterLast($startsAt, ' ');
                                        $endMeridiem = \Illuminate\Support\Str::afterLast($endsAt, ' ');
                                        $compactTime = ($startMeridiem !== '' && $startMeridiem === $endMeridiem)
                                            ? \Illuminate\Support\Str::beforeLast($startsAt, ' ').'–'.$endsAt
                                            : $meeting['time'];
                                        $rawRoom = trim((string) ($meeting['room'] ?? ''));
                                        $roomPrimary = str_contains($rawRoom, ' · ')
                                            ? trim(\Illuminate\Support\Str::before($rawRoom, ' · '))
                                            : $rawRoom;
                                        $roomLabel = in_array($rawRoom, ['', 'Room unassigned', 'Room unavailable'], true)
                                            ? ''
                                            : (\Illuminate\Support\Str::length($roomPrimary) > 18
                                                ? \Illuminate\Support\Str::limit($roomPrimary, 16, '…')
                                                : $roomPrimary);
                                        $rawClass = trim((string) ($meeting['class'] ?? ''));
                                        $rawCode = trim((string) ($meeting['courseCode'] ?? ''));
                                        $cleanClass = ($rawClass !== '' && $rawClass !== 'Class unavailable' && strcasecmp($rawClass, $rawCode) !== 0)
                                            ? trim((string) preg_replace('/^'.preg_quote($rawCode, '/').'\s*[·\-:]\s*/iu', '', $rawClass))
                                            : '';
                                        $componentBadge = $meeting['componentBadge'] ?? (($meeting['component'] ?? '') === 'Laboratory' ? 'LAB' : 'LEC');
                                        $isPinned = (bool) ($meeting['isPinned'] ?? false);
                                        $hasConflict = ($meeting['status'] ?? '') === 'Conflict recorded' || (bool) ($meeting['offGrid'] ?? false);
                                        $teacherLabel = filled($meeting['faculty'] ?? null) && ! in_array($meeting['faculty'], ['Faculty unassigned', 'Faculty unavailable'], true)
                                            ? $meeting['faculty']
                                            : null;
                                        $metaSummary = collect([$meeting['time'], $rawRoom !== '' ? $rawRoom : null, $teacherLabel])->filter()->implode(' · ');
                                        $durationMins = (int) (($meeting['end'] ?? 0) - ($meeting['start'] ?? 0));
                                    @endphp
                                    <button
                                        type="button"
                                        wire:key="week-meeting-{{ $meeting['id'] }}"
                                        data-course-color="{{ $presentation['courseColors'][$meeting['courseCode']] ?? (crc32($meeting['courseCode']) % 4) + 1 }}"
                                        data-component="{{ $componentBadge }}"
                                        data-meeting-id="{{ $meeting['id'] }}"
                                        data-status="{{ $hasConflict ? 'conflict' : 'ok' }}"
                                        class="tala-timetable-meeting tala-timetable-tile {{ $componentBadge === 'LAB' || ($meeting['component'] ?? '') === 'Laboratory' ? 'tala-timetable-laboratory' : 'tala-timetable-lecture' }} {{ $durationMins > 0 && $durationMins < 60 ? 'tala-timetable-short-meeting' : ($durationMins <= 60 ? 'tala-timetable-compact-meeting' : '') }} {{ $isPinned ? 'is-pinned' : '' }} {{ $hasConflict ? 'is-conflict' : '' }}"
                                        style="top: {{ $meeting['top'] }}%; height: {{ $meeting['height'] }}%; inset-inline-start: {{ $meeting['lane'] / $meeting['lanes'] * 100 }}%; width: {{ 100 / $meeting['lanes'] }}%;"
                                        :style="meetingStyle({{ \Illuminate\Support\Js::from($meeting['id']) }}, {{ $meeting['top'] }}, {{ $meeting['height'] }}, {{ $meeting['lane'] }}, {{ $meeting['lanes'] }})"
                                        x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))"
                                        @if ($editable)
                                            draggable="true"
                                            x-on:dragstart="startDragging({{ \Illuminate\Support\Js::from($meeting['id']) }}, $event)"
                                            x-on:dragend="stopDragging()"
                                            :class="{ 'is-selected': selected === {{ \Illuminate\Support\Js::from($meeting['id']) }}, 'is-dragging': draggingId === {{ \Illuminate\Support\Js::from($meeting['id']) }}, 'is-pinned': isMeetingPinned({{ \Illuminate\Support\Js::from($meeting['id']) }}) }"
                                        @elseif ($interactive)
                                            :class="{ 'is-selected': selected === {{ \Illuminate\Support\Js::from($meeting['id']) }} }"
                                        @endif
                                        @if ($nativeDetails)
                                            wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })"
                                            aria-haspopup="dialog"
                                        @elseif ($interactive)
                                            x-on:click="selected = {{ \Illuminate\Support\Js::from($meeting['id']) }}"
                                            :aria-current="selected === {{ \Illuminate\Support\Js::from($meeting['id']) }} ? 'true' : null"
                                        @else
                                            x-on:click="selected = {{ \Illuminate\Support\Js::from($meeting['id']) }}; $dispatch('open-modal', { id: '{{ $meetingId }}' })"
                                            aria-haspopup="dialog"
                                            aria-controls="{{ $meetingId }}"
                                        @endif
                                        aria-label="{{ $meeting['courseCode'] }}, {{ $meeting['courseTitle'] }}, {{ $meeting['class'] }}, {{ $day['label'] }} {{ $meeting['time'] }}, {{ $meeting['faculty'] }}, {{ $meeting['room'] }}, {{ $meeting['component'] }} ({{ $componentBadge }}), {{ $meeting['modality'] }}{{ $isPinned ? ', Locked' : '' }}{{ $meeting['offGrid'] ? ', outside recorded teaching grid' : '' }}"
                                    >
                                        @php
                                            $coursePrefixPattern = '/^'.preg_quote(str_replace(' ', '', (string) $meeting['courseCode']), '/').'[-_\s]+/i';
                                            $shortSection = $cleanClass !== '' ? trim((string) preg_replace($coursePrefixPattern, '', $cleanClass)) : '';
                                            $shortSection = $shortSection !== '' ? $shortSection : $cleanClass;
                                        @endphp
                                        <span class="tala-timetable-tile-header">
                                            <span class="tala-timetable-tile-identity">
                                                <span class="tala-timetable-tile-code">{{ $meeting['courseCode'] }}</span>
                                            </span>
                                            <span class="tala-timetable-tile-badges">
                                                <span
                                                    class="tala-timetable-pin-badge"
                                                    title="Locked slot"
                                                    @if (! $isPinned) x-cloak @endif
                                                    x-show="isMeetingPinned({{ \Illuminate\Support\Js::from($meeting['id']) }})"
                                                >
                                                    <x-filament::icon icon="heroicon-m-lock-closed" />
                                                    <span class="sr-only">Locked</span>
                                                </span>
                                                <span class="tala-timetable-component-badge {{ $componentBadge === 'LAB' ? 'is-lab' : 'is-lec' }}">{{ $componentBadge }}@if (($meeting['meetingCount'] ?? 1) > 1) {{ $meeting['meetingSequence'] ?? 1 }}/{{ $meeting['meetingCount'] }}@endif</span>
                                            </span>
                                        </span>
                                        <span class="tala-timetable-tile-body">
                                            <strong class="tala-timetable-course-title">{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong>
                                            <span class="tala-timetable-tile-subline" title="{{ $metaSummary }}">
                                                <span class="tala-timetable-tile-time">{{ $compactTime }}</span>
                                                @if ($shortSection !== '')
                                                    <span class="tala-timetable-tile-class">{{ $shortSection }}</span>
                                                @endif
                                                @if ($roomLabel !== '')
                                                    <span class="tala-timetable-tile-room">{{ $roomLabel }}</span>
                                                @endif
                                                @if ($teacherLabel !== null)
                                                    <span class="tala-timetable-tile-teacher">{{ $teacherLabel }}</span>
                                                @endif
                                            </span>
                                        </span>
                                        @if ($meeting['offGrid'])<span class="tala-timetable-attention" title="Outside recorded teaching grid">Outside grid</span>@endif
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endforeach
                @if ($sharedFullWidthBreaks !== [])
                    <div class="tala-timetable-shared-breaks" aria-hidden="true">
                        <div class="tala-timetable-day-heading is-spacer">&nbsp;</div>
                        <div class="tala-timetable-shared-breaks-body">
                            @foreach ($sharedFullWidthBreaks as $sharedBreak)
                                <div
                                    class="tala-timetable-shared-break"
                                    style="top: {{ $sharedBreak['top'] }}%; height: {{ $sharedBreak['height'] }}%;"
                                    title="{{ $sharedBreak['displayLabel'] }} · {{ $sharedBreak['time'] }}"
                                >
                                    <span class="tala-timetable-shared-break__label">{{ $sharedBreak['displayLabel'] }} · {{ $sharedBreak['compactTime'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif

        <div class="tala-timetable-list" :class="{'tala-timetable-list-active': mode === 'list'}">
            @php
                $listDayTabs = [
                    ['value' => 'all', 'label' => 'All days'],
                    ['value' => 1, 'label' => 'Mon'],
                    ['value' => 2, 'label' => 'Tue'],
                    ['value' => 3, 'label' => 'Wed'],
                    ['value' => 4, 'label' => 'Thu'],
                    ['value' => 5, 'label' => 'Fri'],
                    ['value' => 6, 'label' => 'Sat'],
                ];
            @endphp
            <div class="tala-timetable-list-toolbar" x-cloak x-show="mode === 'list'" role="toolbar" aria-label="Filter list by day of week">
                <div class="tala-timetable-day-pills">
                    @foreach ($listDayTabs as $dayTab)
                        <button
                            type="button"
                            class="tala-timetable-day-pill"
                            :class="{ 'is-active': String(selectedListDay) === '{{ $dayTab['value'] }}' }"
                            :aria-pressed="String(selectedListDay) === '{{ $dayTab['value'] }}' ? 'true' : 'false'"
                            x-on:click="selectedListDay = {{ is_int($dayTab['value']) ? $dayTab['value'] : "'all'" }}"
                        >
                            {{ $dayTab['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>
            @foreach ($renderMeetings as $meeting)
                @php
                    $componentBadge = $meeting['componentBadge'] ?? (($meeting['component'] ?? '') === 'Laboratory' ? 'LAB' : 'LEC');
                    $isLab = $componentBadge === 'LAB' || ($meeting['component'] ?? '') === 'Laboratory';
                    $isPinned = (bool) ($meeting['isPinned'] ?? false);
                    $rawListClass = trim((string) ($meeting['class'] ?? ''));
                    $rawListCode = trim((string) ($meeting['courseCode'] ?? ''));
                    $cleanListClass = ($rawListClass !== '' && $rawListClass !== 'Class unavailable' && strcasecmp($rawListClass, $rawListCode) !== 0)
                        ? trim((string) preg_replace('/^'.preg_quote($rawListCode, '/').'\s*[·\-:]\s*/iu', '', $rawListClass))
                        : '';
                @endphp
                @if ($nativeDetails)
                    <div wire:key="list-meeting-{{ $meeting['id'] }}" class="tala-timetable-list-meeting {{ ! $meeting['plottable'] ? 'tala-timetable-unplaced' : '' }}" x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))">
                        <button type="button" class="tala-timetable-mobile-meeting {{ $isLab ? 'is-lab' : 'is-lec' }}" data-component="{{ $componentBadge }}" data-course-color="{{ $presentation['courseColors'][$meeting['courseCode']] ?? (crc32($meeting['courseCode']) % 4) + 1 }}" wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })">
                            <span class="tala-timetable-list-time">
                                <strong class="tala-timetable-list-day">{{ $meeting['dayLabel'] }}</strong>
                                <span class="tala-timetable-list-clock">{{ $meeting['time'] }}</span>
                            </span>
                            <span class="tala-timetable-mobile-course">
                                <span class="tala-timetable-list-primary">
                                    <span class="tala-timetable-tile-code">{{ $meeting['courseCode'] }}</span>
                                    <strong class="tala-timetable-list-course-name">{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong>
                                    @if ($cleanListClass !== '')
                                        <span class="tala-timetable-list-section">· {{ $cleanListClass }}</span>
                                    @endif
                                    <span class="tala-timetable-component-badge {{ $isLab ? 'is-lab' : 'is-lec' }}">{{ $componentBadge }}</span>
                                </span>
                            </span>
                            <span class="tala-timetable-meeting-room">
                                <span class="tala-timetable-list-room">{{ $meeting['room'] }}</span>
                                <span class="tala-timetable-list-teacher">{{ $meeting['faculty'] ?? 'View details' }}</span>
                            </span>
                        </button>
                    </div>
                @else
                <div wire:key="list-meeting-{{ $meeting['id'] }}" class="tala-timetable-list-meeting {{ ! $meeting['plottable'] ? 'tala-timetable-unplaced' : '' }}" x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))">
                    <button
                        type="button"
                        class="tala-timetable-mobile-meeting {{ $isLab ? 'is-lab' : 'is-lec' }}"
                        data-component="{{ $componentBadge }}"
                        data-course-color="{{ $presentation['courseColors'][$meeting['courseCode']] ?? (crc32($meeting['courseCode']) % 4) + 1 }}"
                        @if ($interactive)
                            :class="{ 'is-selected': selected === {{ \Illuminate\Support\Js::from($meeting['id']) }} }"
                            :aria-current="selected === {{ \Illuminate\Support\Js::from($meeting['id']) }} ? 'true' : null"
                            x-on:click="selected = {{ \Illuminate\Support\Js::from($meeting['id']) }}"
                        @else
                            aria-haspopup="dialog"
                            aria-controls="{{ $meetingId }}"
                            x-on:click="selected = {{ \Illuminate\Support\Js::from($meeting['id']) }}; $dispatch('open-modal', { id: '{{ $meetingId }}' })"
                        @endif
                    >
                        <span class="tala-timetable-list-time">
                            <strong class="tala-timetable-list-day">{{ $meeting['dayLabel'] }}</strong>
                            <span class="tala-timetable-list-clock">{{ $meeting['time'] }}</span>
                        </span>
                        <span class="tala-timetable-mobile-course">
                            <span class="tala-timetable-list-primary">
                                <span class="tala-timetable-tile-code">{{ $meeting['courseCode'] }}</span>
                                <strong class="tala-timetable-list-course-name">{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong>
                                @if ($cleanListClass !== '')
                                    <span class="tala-timetable-list-section">· {{ $cleanListClass }}</span>
                                @endif
                                <span class="tala-timetable-component-badge {{ $isLab ? 'is-lab' : 'is-lec' }}">{{ $componentBadge }}</span>
                                <span @if (! $isPinned) x-cloak @endif x-show="isMeetingPinned({{ \Illuminate\Support\Js::from($meeting['id']) }})" class="tala-timetable-pin-inline">· Locked</span>
                            </span>
                        </span>
                        <span class="tala-timetable-meeting-room">
                            <span class="tala-timetable-list-room">{{ $meeting['room'] }}</span>
                            <span class="tala-timetable-list-teacher">{{ $meeting['faculty'] ?? 'View details' }}</span>
                        </span>
                    </button>
                </div>
                @endif
            @endforeach
        </div>
        @if (collect($renderMeetings)->contains('plottable', false))
            <p class="tala-timetable-guide">Some meetings have an invalid or missing day or time. They remain visible in the meeting list.</p>
        @endif

        @if ($interactive)
                    @php
                        $hasDockSlot = isset($draftDock) && trim((string) $draftDock) !== '';
                    @endphp
                    @if ($editable || $hasDockSlot)
                        <div
                            class="tala-studio-draft-dock"
                            role="toolbar"
                            aria-label="Draft timetable actions"
                            :class="{ 'is-move-mode': activeMoveMeeting !== null }"
                            @if (! $hasDockSlot)
                                x-cloak
                                x-show="activeMoveMeeting !== null"
                            @endif
                        >
                            @if ($editable)
                                <div class="tala-studio-draft-dock__move" x-cloak x-show="activeMoveMeeting !== null" aria-live="polite">
                                    <span class="tala-move-slot-banner__pulse" aria-hidden="true"></span>
                                    <span class="tala-studio-draft-dock__move-text">
                                        Moving <strong x-text="(activeMoveMeeting?.courseCode || '') + (activeMoveMeeting?.class ? ' · ' + activeMoveMeeting.class : '')"></strong>
                                        <span class="tala-move-slot-banner__meta">(<span x-text="activeMoveMeeting?.duration"></span> · <span x-text="activeMoveMeeting?.faculty"></span> · <span x-text="activeMoveMeeting?.room"></span>)</span>
                                        · Click or drop on a green slot
                                    </span>
                                    <button type="button" class="tala-move-slot-banner__cancel" x-on:click="cancelMoveMode()">
                                        Cancel (Esc)
                                    </button>
                                </div>
                            @endif
                            @if ($hasDockSlot)
                                <div class="tala-studio-draft-dock__actions" x-show="activeMoveMeeting === null">
                                    {{ $draftDock }}
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <aside class="tala-timetable-studio-rail" aria-label="Timetable controls and class inspector">
                    <section class="tala-rail-section tala-rail-controls" aria-label="Instant timetable filters and view controls">
                        <div class="tala-rail-section-head">
                            <h2 class="tala-rail-title">View &amp; filters</h2>
                        </div>

                        <x-tala-navigation compact stretch label="Timetable format" class="tala-interactive-format-tabs">
                            <x-filament::tabs.item
                                icon="heroicon-o-calendar-days"
                                alpine-active="mode === 'weekly'"
                                x-bind:aria-pressed="mode === 'weekly'"
                                x-on:click="setFormat('week')"
                                title="View weekly timetable"
                            >
                                Week
                            </x-filament::tabs.item>
                            <x-filament::tabs.item
                                icon="heroicon-o-list-bullet"
                                alpine-active="mode === 'list'"
                                x-bind:aria-pressed="mode === 'list'"
                                x-on:click="setFormat('list')"
                                title="View meeting list"
                            >
                                List
                            </x-filament::tabs.item>
                        </x-tala-navigation>

                        <x-tala-navigation compact stretch label="Timetable view" class="tala-interactive-view-tabs">
                            <x-filament::tabs.item
                                alpine-active="viewMode === 'whole'"
                                x-bind:aria-pressed="viewMode === 'whole'"
                                x-on:click="setViewMode('whole')"
                            >
                                <span aria-hidden="true">Whole</span><span class="sr-only">All (Whole Term)</span>
                            </x-filament::tabs.item>
                            <x-filament::tabs.item
                                alpine-active="viewMode === 'section'"
                                x-bind:aria-pressed="viewMode === 'section'"
                                x-on:click="setViewMode('section')"
                            >
                                <span aria-hidden="true">Section</span><span class="sr-only">By section</span>
                            </x-filament::tabs.item>
                            <x-filament::tabs.item
                                alpine-active="viewMode === 'faculty'"
                                x-bind:aria-pressed="viewMode === 'faculty'"
                                x-on:click="setViewMode('faculty')"
                            >
                                <span aria-hidden="true">Teacher</span><span class="sr-only">By teacher</span>
                            </x-filament::tabs.item>
                            <x-filament::tabs.item
                                alpine-active="viewMode === 'room'"
                                x-bind:aria-pressed="viewMode === 'room'"
                                x-on:click="setViewMode('room')"
                            >
                                <span aria-hidden="true">Room</span><span class="sr-only">By room</span>
                            </x-filament::tabs.item>
                        </x-tala-navigation>

                        <div class="tala-rail-field" x-cloak x-show="viewMode === 'section'">
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

                        <div class="tala-rail-field" x-cloak x-show="viewMode === 'faculty'">
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

                        <div class="tala-rail-field" x-cloak x-show="viewMode === 'room'">
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

                        @if (count($scheduleContext['programOptions'] ?? []) > 1 || count($scheduleContext['yearOptions'] ?? []) > 1)
                            <div class="tala-rail-filter-pair">
                                @if (count($scheduleContext['programOptions'] ?? []) > 1)
                                    <div class="tala-rail-field">
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
                                    <div class="tala-rail-field">
                                        <label for="{{ $overviewId }}-year-select" class="sr-only">Year level</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="{{ $overviewId }}-year-select" x-model="yearFilter">
                                                <option value="">All years</option>
                                                @foreach ($scheduleContext['yearOptions'] as $yr)
                                                    <option value="{{ $yr }}">{{ $yr }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="tala-rail-search-row">
                            <div class="tala-rail-field tala-rail-search">
                                <label for="timetable-meeting-search" class="sr-only">Search meetings</label>
                                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                    <x-filament::input
                                        id="timetable-meeting-search"
                                        type="search"
                                        placeholder="Search schedule…"
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
                                Reset
                            </x-filament::button>
                        </div>

                        <p class="tala-rail-scope-line">
                            <strong x-text="currentScopeLabel">Whole Term</strong>
                            <template x-if="programFilter"><span> · <span x-text="programFilter"></span></span></template>
                            <template x-if="yearFilter"><span> · <span x-text="yearFilter"></span></span></template>
                            · <span x-text="visibleCount">{{ count($renderMeetings) }}</span>/{{ count($renderMeetings) }} · Asia/Manila
                        </p>
                    </section>

                    <section x-ref="railInspector" class="tala-rail-section tala-rail-inspector" aria-label="Selected class inspector">
                        <div class="tala-rail-empty" x-show="!selectedMeeting">
                            <div class="tala-rail-empty__icon" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-cursor-arrow-rays" />
                            </div>
                            @if ($editable)
                                <p class="tala-rail-empty__copy">Select any class on the timetable to inspect details, lock, move, or edit.</p>
                            @elseif ($isOfficial)
                                <p class="tala-rail-empty__copy">Select any class to inspect or start a revision.</p>
                            @else
                                <p class="tala-rail-empty__copy">Select any class on the timetable to inspect its recorded details.</p>
                            @endif
                            <div class="tala-rail-legend" aria-label="Timetable legend">
                                <span class="tala-rail-legend__item">
                                    <span class="tala-timetable-component-badge is-lec">LEC</span>
                                    <span>Lecture</span>
                                </span>
                                <span class="tala-rail-legend__item">
                                    <span class="tala-timetable-component-badge is-lab">LAB</span>
                                    <span>Lab</span>
                                </span>
                                <span class="tala-rail-legend__item">
                                    <span class="tala-timetable-pin-badge" aria-hidden="true"><x-filament::icon icon="heroicon-m-lock-closed" /></span>
                                    <span>Locked</span>
                                </span>
                            </div>
                        </div>

                        <div class="tala-rail-selected" x-cloak x-show="selectedMeeting">
                            <header class="tala-rail-selected__head">
                                <div class="tala-rail-selected__badges">
                                    <strong class="tala-rail-selected__code" x-text="selectedMeeting?.courseCode"></strong>
                                    <span
                                        class="tala-timetable-component-badge"
                                        :class="(selectedMeeting?.componentBadge || (selectedMeeting?.component === 'Laboratory' ? 'LAB' : 'LEC')) === 'LAB' ? 'is-lab' : 'is-lec'"
                                        x-text="selectedMeeting?.componentBadge || (selectedMeeting?.component === 'Laboratory' ? 'LAB' : 'LEC')"
                                    ></span>
                                    <template x-if="selectedMeeting?.isPinned">
                                        <span class="tala-inspector-pinned-pill">
                                            <x-filament::icon icon="heroicon-m-lock-closed" />
                                            <span>Locked</span>
                                        </span>
                                    </template>
                                </div>
                                <button
                                    type="button"
                                    class="tala-rail-close-btn"
                                    x-on:click="selected = null"
                                    aria-label="Clear selected class"
                                    title="Clear selection"
                                >
                                    <x-filament::icon icon="heroicon-m-x-mark" />
                                </button>
                            </header>

                            <div class="tala-rail-selected__titles">
                                <h3 x-text="selectedMeeting?.courseTitle || selectedMeeting?.courseCode"></h3>
                                <p x-show="selectedClassLabel(selectedMeeting) !== ''" x-text="selectedClassLabel(selectedMeeting)"></p>
                            </div>

                            <dl class="tala-rail-facts">
                                <div><dt>When</dt><dd><span x-text="selectedMeeting?.dayLabel"></span> · <span x-text="selectedMeeting?.time"></span><template x-if="(selectedMeeting?.meetingCount || 1) > 1"><span> · Session <span x-text="selectedMeeting?.meetingSequence || 1"></span> of <span x-text="selectedMeeting?.meetingCount"></span></span></template></dd></div>
                                <div><dt>Room</dt><dd x-text="selectedMeeting?.room"></dd></div>
                                <div><dt>Teacher</dt><dd x-text="selectedMeeting?.faculty"></dd></div>
                                <div><dt>Delivery</dt><dd><span x-text="selectedMeeting?.modality"></span> · <span x-text="selectedMeeting?.component"></span></dd></div>
                                <div><dt>Status</dt><dd x-text="selectedMeeting?.status"></dd></div>
                            </dl>

                            <p x-cloak x-show="selectedMeeting && !selectedMeeting.plottable" class="tala-rail-warning">Meeting time is unavailable or invalid.</p>
                            <p x-cloak x-show="selectedMeeting?.offGrid" class="tala-rail-warning">Outside the recorded teaching window.</p>

                            @if ($editable)
                                <div class="tala-rail-actions">
                                    <button
                                        type="button"
                                        class="tala-inspector-btn"
                                        :class="{ 'is-pinned': selectedMeeting?.isPinned }"
                                        title="Keeps this class fixed to this day, time, room, and teacher if you regenerate."
                                        x-on:click="togglePinSelected()"
                                    >
                                        <span class="tala-inspector-btn__icon" x-show="selectedMeeting?.isPinned" x-cloak><x-filament::icon icon="heroicon-o-lock-open" /></span>
                                        <span class="tala-inspector-btn__icon" x-show="!selectedMeeting?.isPinned"><x-filament::icon icon="heroicon-m-lock-closed" /></span>
                                        <span x-text="selectedMeeting?.isPinned ? 'Unlock slot' : 'Lock slot'">Lock slot</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="tala-inspector-btn"
                                        x-on:click="startMoveOnGrid()"
                                    >
                                        <x-filament::icon icon="heroicon-o-arrows-pointing-out" />
                                        <span>Move on grid</span>
                                    </button>
                                    <p class="tala-inspector-pin-helper">Keeps this class fixed to this day, time, room, and teacher if you regenerate.</p>
                                </div>

                                <section class="tala-rail-edit-card" aria-label="Edit schedule">
                                    <header class="tala-inspector-edit-card__header">
                                        <h4>Edit schedule</h4>
                                        <span
                                            class="tala-inspector-slot-check"
                                            role="status"
                                            aria-live="polite"
                                            :class="evaluateProposedEdit().valid ? 'is-valid' : 'is-conflict'"
                                            x-text="evaluateProposedEdit().reason"
                                        ></span>
                                    </header>

                                    <div class="tala-rail-edit-fields">
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
                                                        <option :value="slot.value" x-text="timeSlotOptionLabel(slot)"></option>
                                                    </template>
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </label>

                                        <label class="tala-inspector-field">
                                            <span>Room (<span x-text="(selectedMeeting?.componentBadge || 'LEC') === 'LAB' ? 'Lab' : 'Lecture'"></span>)</span>
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

                                    <div class="tala-rail-edit-footer">
                                        <x-filament::button
                                            type="button"
                                            color="primary"
                                            size="sm"
                                            icon="heroicon-m-check"
                                            class="w-full justify-center"
                                            x-bind:disabled="!evaluateProposedEdit().valid"
                                            x-on:click="submitInlineEdit()"
                                        >
                                            Save schedule changes
                                        </x-filament::button>
                                    </div>
                                </section>
                            @elseif ($isOfficial)
                                <div class="tala-rail-locked-box">
                                    <span class="tala-inspector-state-pill is-official">
                                        <x-filament::icon icon="heroicon-m-lock-closed" />
                                        <span>Official · Locked</span>
                                    </span>
                                    <p>Published classes stay locked to protect student enrollments.</p>
                                    @if (! empty($scheduleContext['canReviseOfficial']) || ! empty($scheduleContext['reviseOfficialUrl']))
                                        <x-filament::button
                                            type="button"
                                            color="primary"
                                            size="sm"
                                            icon="heroicon-o-pencil-square"
                                            class="w-full justify-center"
                                            x-on:click="reviseSelectedOfficialMeeting()"
                                        >
                                            Revise this class
                                        </x-filament::button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </section>
                </aside>
            </div>
        @endif
    @endif
</section>

@if (! $focused)
    <x-filament::modal footer-actions-alignment="end" :id="$overviewId" width="screen" sticky-header heading="Whole timetable">
        <x-tala-timetable :presentation="$presentation" :focused="true" />
    </x-filament::modal>
@endif
