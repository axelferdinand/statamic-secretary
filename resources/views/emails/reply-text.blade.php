Statamic Secretary

{{ $bodyBeforeAffected }}

@if ($affectedChange)
Berørt side: {{ $affectedChange['resource_title'] }} — {{ $affectedChange['public_url'] }}

@endif
@if ($bodyAfterAffected)
{{ $bodyAfterAffected }}

@endif
@if ($showChangeList)
Klargjorte endringer:
@foreach ($changeSets as $changeSet)
- {{ $changeSet['summary'] }} — {{ $changeSet['status'] === 'published' ? 'publisert' : 'utkast' }}
@if ($changeSet['native_url'])
  {{ $changeSet['native_url'] }}
@endif
@endforeach

@endif
{{ $primaryLabel }}:
{{ $primaryUrl }}

@if ($conversationUrl !== $primaryUrl)
Fortsett samtalen i Secretary:
{{ $conversationUrl }}

@endif

Svar på denne e-posten for å fortsette samme samtale.
