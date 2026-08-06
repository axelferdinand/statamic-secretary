Secretary

{{ $bodyBeforeAffected }}

@if ($affectedChange)
{{ $copy['affected_page'] }}: {{ $affectedChange['resource_title'] }} — {{ $affectedChange['public_url'] }}

@endif
@if ($bodyAfterAffected)
{{ $bodyAfterAffected }}

@endif
@if ($showChangeList)
{{ $copy['prepared_changes'] }}:
@foreach ($changeSets as $changeSet)
- {{ $changeSet['summary'] }} — {{ $changeSet['status'] === 'published' ? $copy['published'] : $copy['draft'] }}
@if ($changeSet['native_url'])
  {{ $changeSet['native_url'] }}
@endif
@endforeach

@endif
@if ($attachments)
{{ $copy['attachments'] }}:
@foreach ($attachments as $attachment)
- {{ $attachment['name'] }}
  {{ $attachment['native_url'] }}
@endforeach

@endif
{{ $primaryLabel }}:
{{ $primaryUrl }}

@if ($conversationUrl !== $primaryUrl)
{{ $copy['continue_conversation'] }}:
{{ $conversationUrl }}

@endif

{{ $copy['reply_to_continue'] }}
