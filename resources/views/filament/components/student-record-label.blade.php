@php($student = $getRecord())
<div class="tala-student-label">
    <span class="tala-student-label__avatar" aria-hidden="true">{{ str($student->first_name)->substr(0, 1) }}{{ str($student->last_name)->substr(0, 1) }}</span>
    <div>
        <strong>{{ $student->last_name }}, {{ $student->first_name }}</strong>
        <span>{{ $student->student_number }}</span>
    </div>
</div>
