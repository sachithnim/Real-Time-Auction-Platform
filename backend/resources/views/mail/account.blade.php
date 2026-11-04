<x-mail::message>
# {{ $heading }}

Hi {{ $name }},

@foreach ($lines as $line)
{{ $line }}

@endforeach

@if ($buttonUrl)
<x-mail::button :url="$buttonUrl" color="primary">
{{ $buttonLabel }}
</x-mail::button>
@endif

<x-slot:subcopy>
{{ $footnote }}
</x-slot:subcopy>
</x-mail::message>
