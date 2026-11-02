{{-- Laravel's mail layout, branded LiveBid (APP_NAME also names the session cookie, so it stays as is). --}}
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.frontend_url')">
<span style="color:#4338ca;">Live</span><span style="color:#f97316;">Bid</span>
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} LiveBid. All rights reserved.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
