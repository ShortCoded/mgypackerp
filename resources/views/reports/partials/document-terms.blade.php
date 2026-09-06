@php($printTerms = collect($terms)->filter(fn ($content) => filled($content)))
@if($printTerms->isNotEmpty())
    <table class="document-terms"><tbody>
        @foreach($printTerms->chunk(2) as $termRow)
            <tr>@foreach($termRow as $label => $content)
                <td><strong>{{ $label }}</strong><div>@if($richText ?? false){!! $content !!}@else{{ $content }}@endif</div></td>
            @endforeach
            @if($termRow->count() === 1)<td></td>@endif
            </tr>
        @endforeach
    </tbody></table>
@endif
