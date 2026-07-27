Statamic Secretary

{{ $body }}

@if (count($changeSets))
Endringer i denne meldingen:
@foreach ($changeSets as $changeSet)
- {{ $changeSet['summary'] }} — {{ $changeSet['status'] === 'published' ? 'publisert' : 'utkast' }}
@endforeach

@endif
Se samtale og utkast:
{{ $reviewUrl }}

Svar på denne e-posten for å fortsette samme samtale.
