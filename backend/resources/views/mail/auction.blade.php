<x-mail::message>
# {{ $heading }}

Hi {{ $name }},

@if ($photo)
<img src="{{ $photo }}" alt="{{ $title }}" style="display:block; width:100%; max-width:520px; height:auto; border-radius:8px; margin:0 0 16px;">
@endif

{{-- The template is Markdown already: escaped text keeps titles from injecting HTML, ** still makes bold. --}}
@foreach ($lines as $line)
{{ $line }}

@endforeach

<x-mail::button :url="$buttonUrl" color="primary">
{{ $buttonLabel }}
</x-mail::button>

<x-slot:subcopy>
You are receiving this because of your activity on LiveBid. [Unsubscribe from {{ $typeLabel }} emails]({{ $unsubscribeUrl }}) · [Email settings]({{ $settingsUrl }})
</x-slot:subcopy>
</x-mail::message>
