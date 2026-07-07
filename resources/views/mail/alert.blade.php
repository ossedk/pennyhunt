<x-mail::message>
# {{ $subjectLine }}

@foreach ($lines as $line)
{{ $line }}

@endforeach

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel ?? 'Open in Pennyhunt' }}
</x-mail::button>
@endif

<small>Advisory only — authoritative exits settle on completed daily bars.</small>
</x-mail::message>
