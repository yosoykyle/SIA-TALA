@props(['records', 'selected' => null, 'selectAction' => null, 'noun' => 'students'])
<x-record-collection-footer :paginator="$records" :label="$noun" :page-options="[10, 25, 50]" />