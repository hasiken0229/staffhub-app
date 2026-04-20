{{ $titleLine }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($context !== [])
詳細
@foreach ($context as $label => $value)
- {{ $label }}: {{ $value }}
@endforeach
@endif
