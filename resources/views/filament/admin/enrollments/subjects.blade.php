<table class="registrar-subject-table">
    <caption class="sr-only">Selected subjects and classes</caption>
    <thead><tr><th scope="col">Subject</th><th scope="col">Section</th><th scope="col">Units</th></tr></thead>
    <tbody>
        @foreach ($proposal->items as $item)
            <tr wire:key="{{ $sourceKey }}-subject-{{ $item->id }}">
                <th scope="row">{{ $item->course_title_snapshot }}<span>{{ $item->course_code_snapshot }}</span></th>
                <td>{{ $item->section?->code ?? 'Not assigned' }}</td>
                <td>{{ (float) $item->units_snapshot }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
